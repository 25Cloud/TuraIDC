<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\Setting;
use App\Services\ClientServiceConsole\ServiceTrafficPackageService;
use App\Services\System\SettingService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 把「流量包目录」从空档一次性铺成可售状态（幂等，可反复跑）。
 *
 * 存量站点的两种情况让流量包买不了：
 *   1. 目录（traffic_package_catalog.items）是空的 —— 商品分类一个可售档位都没有，
 *      控制台与上游通道都拿不到列表；
 *   2. 匹配配置被存成了空串/关闭 —— option_field 与 option_keyword 为空、两种模式都是
 *      false 时，findTrafficPackageOption() 会把所有选项都过滤掉，即使开了功能也永远
 *      匹配不到上游的流量档位。
 *
 * 本命令按「商品分类 + 商品类型」逐个走与后台一键对接完全相同的拉取逻辑
 * （ServiceTrafficPackageService::pullCatalogTemplateForAdmin），把结果按成本价 + 加价
 * 规则写进目录。上游没报价（成本为 0）的档位写成 enabled=0，不静默按 0 元卖。
 *
 * 不会动的：总开关 traffic_package_enabled，以及已有手工档位（只在 --force 时覆盖）。
 */
class BackfillTrafficPackageCatalogCommand extends Command
{
    protected $signature = 'traffic:backfill-catalog
        {--category=* : 只处理指定商品分类 ID，可重复}
        {--product-type= : 只处理指定商品类型（按商品表原值过滤）}
        {--markup-type=none : 目录加价方式：none|percent|fixed}
        {--markup-value=0 : 加价数值（percent 为百分比，fixed 为金额）}
        {--force : 覆盖该分类下已有的手工档位（默认保留手填条目）}
        {--ignore-bindings : 不要求商品已绑定上游（仅用于没有绑定表的库与本地验证）}
        {--max=0 : 最多处理多少个分类，0 表示不限}
        {--repair-config : 同时修复空白的流量包匹配配置（选项字段/关键字/两种模式）}
        {--dry-run : 只打印将要写入的条目，不落库}';

    protected $description = '按商品分类回填流量包目录（成本价 + 加价）并修复流量包匹配配置';

    public function handle(ServiceTrafficPackageService $trafficPackages, SettingService $settings): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $markupType = (string) $this->option('markup-type');
        $markupValue = (float) $this->option('markup-value');

        if (! in_array($markupType, ['none', 'percent', 'fixed'], true)) {
            $this->error('--markup-type 只能是 none / percent / fixed');

            return self::INVALID;
        }

        if ((bool) $this->option('repair-config')) {
            $this->repairTrafficPackageConfig($dryRun);
        }

        $pairs = $this->collectPairs();
        if ($pairs === []) {
            $this->warn('没有找到已绑定上游的在售商品分类，无需处理');

            return self::SUCCESS;
        }

        $max = max((int) $this->option('max'), 0);
        if ($max > 0) {
            $pairs = array_slice($pairs, 0, $max);
        }

        $existing = $settings->getTrafficPackageCatalog();
        $existingKeys = [];
        foreach ($existing as $item) {
            $existingKeys[$this->catalogKey((int) $item['category_id'], (string) $item['product_type'])] = true;
        }

        $built = [];
        $failures = [];

        foreach ($pairs as $pair) {
            $categoryId = (int) $pair['category_id'];
            $rawType = (string) $pair['product_type'];
            $catalogType = (string) $pair['catalog_type'];
            $label = sprintf('#%d/%s', $categoryId, $catalogType);

            try {
                $template = $trafficPackages->pullCatalogTemplateForAdmin($categoryId, null, $rawType);
            } catch (BusinessException $exception) {
                $failures[] = $label.'：'.$exception->getMessage();
                $this->warn($label.' 跳过：'.$exception->getMessage());

                continue;
            } catch (Throwable $exception) {
                $failures[] = $label.'：'.$exception->getMessage();
                $this->warn($label.' 异常：'.$exception->getMessage());

                continue;
            }

            $packages = is_array($template['packages'] ?? null) ? $template['packages'] : [];
            if ($packages === []) {
                $failures[] = $label.'：上游未返回档位';
                $this->warn($label.' 跳过：上游未返回档位');

                continue;
            }

            $key = $this->catalogKey($categoryId, $catalogType);
            if (! (bool) $this->option('force')) {
                $keepExisting = false;
                foreach ($existing as $item) {
                    if ($this->catalogKey((int) $item['category_id'], (string) $item['product_type']) === $key) {
                        $keepExisting = true;
                        break;
                    }
                }

                if ($keepExisting) {
                    $this->line($label.' 已配置，保留现有档位（--force 可覆盖）');

                    continue;
                }
            }

            $built[$key] = $this->buildCatalogItems($categoryId, $catalogType, $packages, $markupType, $markupValue);
            $this->line(sprintf('%s 生成 %d 个档位', $label, count($built[$key])));
        }

        if ($built === []) {
            $this->warn('没有任何分类生成新档位');

            return $failures === [] ? self::SUCCESS : self::FAILURE;
        }

        $merged = [];
        foreach ($existing as $item) {
            $key = $this->catalogKey((int) $item['category_id'], (string) $item['product_type']);
            if (isset($built[$key])) {
                continue;
            }
            $merged[] = $item;
        }

        foreach ($built as $items) {
            foreach ($items as $item) {
                $merged[] = $item;
            }
        }

        $this->table(
            ['分类', '类型', '档位', '成本价', '售价', '在售'],
            array_map(fn (array $item): array => [
                (int) $item['category_id'],
                (string) $item['product_type'],
                (string) $item['label'],
                (string) $item['cost_price'],
                (string) $item['price'],
                (int) $item['enabled'] === 1 ? '是' : '否（上游未报价）',
            ], array_merge(...array_values($built))),
        );

        if ($dryRun) {
            $this->info(sprintf('dry-run：将写入 %d 个分类 / %d 个档位，未落库',
                count($built),
                array_sum(array_map('count', $built))
            ));

            return self::SUCCESS;
        }

        $settings->saveTrafficPackageCatalog($merged);

        $this->info(sprintf('已写入 %d 个分类 / %d 个档位（目录共 %d 条）',
            count($built),
            array_sum(array_map('count', $built)),
            count($merged)
        ));

        if ($failures !== []) {
            $this->warn('以下分类未生成档位，需要人工确认：');
            foreach ($failures as $failure) {
                $this->line('  - '.$failure);
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * 修复「存成空串导致功能即使开了也用不了」的匹配配置。
     *
     * 只补空值与 false，不改已经显式配置过的值；总开关一律不动。
     */
    private function repairTrafficPackageConfig(bool $dryRun): void
    {
        $defaults = SettingService::defaultTrafficPackageConfig();

        $stringFixes = [
            'traffic_package_option_field' => (string) ($defaults['traffic_package_option_field'] ?? ''),
            'traffic_package_option_keyword' => (string) ($defaults['traffic_package_option_keyword'] ?? ''),
            'traffic_package_button_text' => (string) ($defaults['traffic_package_button_text'] ?? ''),
        ];

        foreach ($stringFixes as $key => $default) {
            if ($default === '') {
                continue;
            }

            $current = trim((string) Setting::getValue('traffic_package', $key, ''));
            if ($current !== '') {
                continue;
            }

            $this->line('配置修复：'.$key.' 空值 → '.$default);
            if (! $dryRun) {
                Setting::setValue('traffic_package', $key, $default);
            }
        }

        // 两种模式同时关闭时，findTrafficPackageOption() 会把所有选项都过滤掉
        $choice = Setting::getValue('traffic_package', 'traffic_package_allow_choice_mode', null);
        $quantity = Setting::getValue('traffic_package', 'traffic_package_allow_quantity_mode', null);

        if ($this->isFalseValue($choice) && $this->isFalseValue($quantity)) {
            foreach (['traffic_package_allow_choice_mode', 'traffic_package_allow_quantity_mode'] as $key) {
                $this->line('配置修复：'.$key.' 关闭 → 打开');
                if (! $dryRun) {
                    Setting::setValue('traffic_package', $key, '1');
                }
            }
        }
    }

    private function isFalseValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        return in_array(strtolower(trim((string) $value)), ['0', 'false', ''], true);
    }

    /**
     * 待处理的「分类 + 商品类型」组合。
     *
     * 商品表的 product_type 原值与 Product::product_type 读值可能不同（读值按一级分组派生），
     * 而目录条目是按读值过滤的，所以两个值都要带出来：
     *   - 拉取用原值（拉取逻辑按商品表过滤）
     *   - 写入目录用读值（出售时按读值匹配）
     *
     * @return list<array{category_id:int, product_type:string, catalog_type:string}>
     */
    private function collectPairs(): array
    {
        $query = Product::query()
            ->where('products.status', 1)
            ->where('products.product_group_id', '>', 0);

        // 上游绑定表由插件安装建立：没有它就没法拉取，干脆全部跳过；
        // 表在但商品没绑定时同理（拉不到上游档位）
        if (! (bool) $this->option('ignore-bindings') && Schema::hasTable('product_upstream_bindings')) {
            $query->whereExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('product_upstream_bindings as pub')
                    ->whereColumn('pub.product_id', 'products.id')
                    ->where('pub.status', 1);
            });
        }

        $categoryFilter = array_values(array_filter(array_map('intval', (array) $this->option('category'))));
        if ($categoryFilter !== []) {
            $query->whereIn('products.product_group_id', $categoryFilter);
        }

        $typeFilter = trim((string) $this->option('product-type'));
        if ($typeFilter !== '') {
            $query->where('products.product_type', $typeFilter);
        }

        $rows = $query
            ->select(['products.id', 'products.product_group_id', 'products.product_type'])
            ->orderBy('products.product_group_id')
            ->orderBy('products.id')
            ->get();

        $pairs = [];
        $seen = [];

        foreach ($rows as $row) {
            $rawType = trim((string) ($row->getRawOriginal('product_type') ?? ''));
            $catalogType = trim((string) $row->product_type);
            if ($catalogType === '') {
                continue;
            }

            $key = $this->catalogKey((int) $row->product_group_id, $catalogType);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $pairs[] = [
                'category_id' => (int) $row->product_group_id,
                'product_type' => $rawType !== '' ? $rawType : $catalogType,
                'catalog_type' => $catalogType,
            ];
        }

        return $pairs;
    }

    /**
     * @param  list<array<string, mixed>>  $packages
     * @return list<array<string, mixed>>
     */
    private function buildCatalogItems(
        int $categoryId,
        string $catalogType,
        array $packages,
        string $markupType,
        float $markupValue,
    ): array {
        $items = [];
        $sort = 0;

        foreach ($packages as $package) {
            $targetValue = (int) ($package['target_value'] ?? 0);
            if ($targetValue <= 0) {
                continue;
            }

            $sort++;
            $cost = round(max((float) ($package['price'] ?? 0), 0), 2);
            $label = trim((string) ($package['label'] ?? ''));
            if ($label === '') {
                $label = $targetValue.'G';
            }

            $items[] = [
                'category_id' => $categoryId,
                'product_type' => $catalogType,
                'label' => $label,
                'target_value' => $targetValue,
                'cost_price' => number_format($cost, 2, '.', ''),
                'markup_type' => $markupType,
                'markup_value' => $markupValue,
                'price' => number_format(SettingService::applyTrafficPackageMarkup($cost, $markupType, $markupValue), 2, '.', ''),
                // 上游没报价的档位不能按 0 元卖出去
                'enabled' => $cost > 0 ? 1 : 0,
                'sort_order' => $sort,
            ];
        }

        return $items;
    }

    private function catalogKey(int $categoryId, string $productType): string
    {
        return $categoryId.'|'.$productType;
    }
}
