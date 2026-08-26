<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use App\Constants\BillingCycle;
use App\Models\Product;
use App\Models\ThirdProductGroup;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * 上游商品数据转换：把 TuraIDC 商品结构映射成魔方财务上游协议期望的结构。
 *
 * 对齐点（魔方财务侧）：
 *   - cart/all                  -> data.products(分组数组) + data.count + data.currency
 *   - api/product/proinfo       -> data.info[{id,name,location_version,stock_control,qty}] + data.currency
 *   - api/product/prodetail     -> data.detail[{pid => 商品全量 + product_pricings + flag}]
 *   - cart/get_product_config   -> data = products + product_pricings + flag + config_groups 等
 *   - cart/ontrialmax           -> data.product{ontrial,qty}
 *
 * products 字段清单对齐 ProductModel::syncProduct 的读取点
 * （type/description/host/password/pay_type/auto_setup/qty/pay_method/location_version
 *   /stock_control/retired/groupid/product_shopping_url 等）。
 */
class ProductService
{
    public const CURRENCY = 'CNY';

    /** TuraIDC 业务类型 -> 魔方财务 products.type */
    private const TYPE_MAP = [
        'cloud_server' => 'cloud',
        'game_cloud' => 'cloud',
        'cloud_desktop' => 'cloud',
        'bare_metal' => 'dcim',
        'physical_machine' => 'dcim',
        'web_hosting' => 'hostingaccount',
        'cdn' => 'cdn',
        'domain' => 'domain',
        'other' => 'other',
    ];

    /**
     * 魔方财务 pricing 表周期价格列（config price_type 全量，含 fourly~tenly）。
     * 未配置的周期填 -1，魔方财务按「>=0 才算有效价格」处理。
     */
    private const PRICING_SETUP_MAP = [
        'onetime' => 'osetupfee',
        'hour' => 'hsetupfee',
        'day' => 'dsetupfee',
        'ontrial' => 'ontrialfee',
        'monthly' => 'msetupfee',
        'quarterly' => 'qsetupfee',
        'semiannually' => 'ssetupfee',
        'annually' => 'asetupfee',
        'biennially' => 'bsetupfee',
        'triennially' => 'tsetupfee',
        'fourly' => 'foursetupfee',
        'fively' => 'fivesetupfee',
        'sixly' => 'sixsetupfee',
        'sevenly' => 'sevensetupfee',
        'eightly' => 'eightsetupfee',
        'ninely' => 'ninesetupfee',
        'tenly' => 'tensetupfee',
    ];

    /**
     * cart/all：按三级分组聚合的上架商品列表。
     *
     * @return array{products: list<array{id:int,name:string,products:list<array>}>, count:int, currency:string}
     */
    public function all(): array
    {
        $products = $this->onSaleProducts();

        $groups = $products
            ->groupBy(static fn (Product $product): int => (int) ($product->product_group_id ?? 0))
            ->map(fn (Collection $items, int $gid): array => [
                'id' => $gid,
                'name' => $this->groupName($gid),
                'products' => $items
                    ->map(fn (Product $product): array => [
                        'id' => (int) $product->id,
                        'type' => $this->mapType((string) $product->product_type),
                        'name' => $this->productName($product),
                        'description' => (string) ($product->remark ?? ''),
                    ])
                    ->values()
                    ->all(),
            ])
            ->values()
            ->all();

        return [
            'products' => $groups,
            'count' => $products->count(),
            'currency' => self::CURRENCY,
        ];
    }

    /**
     * api/product/proinfo：商品版本/库存信息，供魔方财务比对 location_version 触发同步。
     *
     * @param  list<int>  $pids
     * @return array{info:list<array>, currency:string}
     */
    public function infos(array $pids): array
    {
        $products = $this->onSaleProducts($pids);

        return [
            'info' => $products
                ->map(fn (Product $product): array => [
                    'id' => (int) $product->id,
                    'name' => $this->productName($product),
                    'location_version' => $this->versionOf($product),
                    'stock_control' => $this->stockControl($product),
                    'qty' => $this->stockQty($product),
                ])
                ->values()
                ->all(),
            'currency' => self::CURRENCY,
        ];
    }

    /**
     * api/product/prodetail：商品全量详情（结构与 cart/get_product_config 的 data 同构）。
     *
     * @param  list<int>  $pids  pids 为空时返回全部上架商品
     * @return array{detail:array<int,array>}
     */
    public function details(array $pids): array
    {
        $detail = [];
        foreach ($this->onSaleProducts($pids) as $product) {
            $detail[(int) $product->id] = $this->productDetail($product);
        }

        return ['detail' => $detail];
    }

    /**
     * cart/get_product_config：单个商品完整配置。
     *
     * @return array{status:int,msg:string,data?:array}
     */
    public function config(int $pid): array
    {
        $product = $this->onSaleProducts()->firstWhere('id', $pid);

        if (! $product instanceof Product) {
            return ['status' => 400, 'msg' => '商品不存在或已下架'];
        }

        return [
            'status' => 200,
            'msg' => '请求成功',
            'data' => $this->productDetail($product),
        ];
    }

    /**
     * cart/ontrialmax：API 用户对该商品的试用/数量限制。
     * TuraIDC 不提供试用，qty 取库存；库存不限时返回 0（魔方财务语义：0 = 不限制）。
     *
     * @return array{status:int,msg:string,data?:array}
     */
    public function trialLimit(int $pid): array
    {
        $product = $this->onSaleProducts()->firstWhere('id', $pid);

        if (! $product instanceof Product) {
            return ['status' => 400, 'msg' => '商品不存在或已下架'];
        }

        return [
            'status' => 200,
            'msg' => '请求成功',
            'data' => [
                'product' => [
                    'ontrial' => 0,
                    'qty' => $this->stockQty($product),
                ],
            ],
        ];
    }

    /**
     * 单个商品的完整详情，供 cart/get_product_config 与 prodetail 复用。
     *
     * @return array<string, mixed>
     */
    private function productDetail(Product $product): array
    {
        return array_merge($this->toProduct($product), [
            'product_pricings' => [$this->toPricing($product)],
            'flag' => 0,
            'config_groups' => $this->configGroups($product),
            'config_links' => [],
            'customfields' => [],
            'advanced' => [],
        ]);
    }

    /**
     * products 全字段，字段名对齐魔方财务 products 表（syncProduct 直接落库）。
     *
     * @return array<string, mixed>
     */
    private function toProduct(Product $product): array
    {
        return [
            'id' => (int) $product->id,
            'gid' => (int) ($product->product_group_id ?? 0),
            'groupid' => 0,
            'type' => $this->mapType((string) $product->product_type),
            'name' => $this->productName($product),
            'description' => (string) ($product->remark ?? ''),
            'host' => '',
            'password' => '',
            'pay_type' => json_encode($this->payType($product), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'auto_setup' => (int) $product->auto_setup,
            'auto_terminate_days' => 0,
            'config_options_upgrade' => $this->hasUpgradeableConfigOptions($product) ? 1 : 0,
            'down_configoption_refund' => 0,
            'retired' => (int) $product->status === 1 ? 0 : 1,
            'is_featured' => 0,
            'allow_qty' => 1,
            'is_truename' => 0,
            'is_bind_phone' => 0,
            'qty' => $this->stockQty($product),
            'product_shopping_url' => '',
            'location_version' => $this->versionOf($product),
            'stock_control' => $this->stockControl($product),
            'pay_method' => 'prepayment',
            'api_type' => '',
            'upstream_pid' => 0,
            'upstream_price_type' => 'fixed',
            'upstream_price_value' => 100,
            'hidden' => 0,
        ];
    }

    /**
     * 单币种（CNY）价格行，键名对齐魔方财务 pricing 表列。
     *
     * @return array<string, mixed>
     */
    private function toPricing(Product $product): array
    {
        $pricing = (array) $product->pricing;
        $setupFee = round((float) $product->setup_fee, 2);

        $row = [
            'code' => self::CURRENCY,
            'type' => 'product',
            'currency' => 1,
            'relid' => (int) $product->id,
        ];

        foreach (self::PRICING_SETUP_MAP as $cycle => $setupColumn) {
            $row[$cycle] = $this->priceForCycle($pricing, $cycle);
            $row[$setupColumn] = $setupFee;
        }

        return $row;
    }

    /**
     * 从 TuraIDC pricing 映射中取指定魔方周期价格；未配置返回 -1。
     *
     * @param  array<string, mixed>  $pricing
     */
    private function priceForCycle(array $pricing, string $cycle): int|float
    {
        foreach ($pricing as $key => $amount) {
            if (BillingCycle::normalize((string) $key) === BillingCycle::normalize($cycle)) {
                return is_numeric($amount) ? round((float) $amount, 2) : -1;
            }
        }

        return -1;
    }

    /**
     * 计费方式：免费 / 一次性 / 周期。
     *
     * @return array{pay_type:string,pay_ontrial_status:int,clientscount_rule:int}
     */
    private function payType(Product $product): array
    {
        $configured = [];
        foreach ((array) $product->pricing as $cycle => $amount) {
            $normalized = BillingCycle::normalize((string) $cycle);
            if ($normalized !== '' && is_numeric($amount) && (float) $amount >= 0) {
                $configured[$normalized] = true;
            }
        }

        $payType = match (true) {
            isset($configured[BillingCycle::FREE]) || $configured === [] => 'free',
            count($configured) === 1 && isset($configured[BillingCycle::ONE_TIME]) => 'onetime',
            default => 'recurring',
        };

        return [
            'pay_type' => $payType,
            'pay_ontrial_status' => 0,
            'clientscount_rule' => 0,
        ];
    }

    private function mapType(string $type): string
    {
        return self::TYPE_MAP[$type] ?? self::TYPE_MAP['other'];
    }

    private function productName(Product $product): string
    {
        $custom = trim((string) ($product->custom_display_name ?? ''));
        if ($custom !== '') {
            return $custom;
        }

        $supplier = trim((string) ($product->supplier_product_name ?? ''));
        if ($supplier !== '') {
            return $supplier;
        }

        return '商品 #'.$product->id;
    }

    private function versionOf(Product $product): int
    {
        $updatedAt = $product->updated_at;

        return $updatedAt instanceof CarbonInterface ? $updatedAt->getTimestamp() : time();
    }

    private function stockQty(Product $product): int
    {
        $stock = (int) $product->stock;

        return $stock > 0 ? $stock : 0;
    }

    private function stockControl(Product $product): int
    {
        return (int) $product->stock > 0 ? 1 : 0;
    }

    private function groupName(int $groupId): string
    {
        if ($groupId <= 0) {
            return '未分组';
        }

        $group = ThirdProductGroup::query()->find($groupId);

        return $group ? trim((string) $group->name) ?: '未分组' : '未分组';
    }

    /**
     * 上架商品集合；pids 非空时按 id 过滤（pids 为空返回全部）。
     *
     * @param  list<int>  $pids
     */
    private function onSaleProducts(array $pids = []): Collection
    {
        $query = Product::query()
            ->where('status', 1)
            ->orderBy('sort_order');

        if ($pids !== []) {
            $query->whereIn('id', $pids);
        }

        return $query->get();
    }

    /**
     * 是否存在可升级配置项（供 config_options_upgrade 标记）。
     */
    private function hasUpgradeableConfigOptions(Product $product): bool
    {
        return $this->configGroups($product) !== [];
    }

    /**
     * 组装魔方财务 config_groups 结构（同步产品配置项）。
     * 对齐 ProductModel::syncProduct 读取点：config_groups[].id/name/description/options[]，
     * options[].id/sub[].id 作为 upstream_id 落库，sub[].pricings 按币种写入 pricing 表。
     *
     * @return list<array<string, mixed>>
     */
    private function configGroups(Product $product): array
    {
        $groupId = (int) $product->id;
        $options = [];
        $sortOrder = 0;

        foreach ((array) ($product->config_options ?? []) as $item) {
            $item = (array) $item;
            if (! $this->isUpgradeableConfigItem($item)) {
                continue;
            }

            $optionId = (int) ($item['id'] ?? 0);
            if ($optionId <= 0) {
                continue;
            }

            $subs = [];
            $subSort = 0;
            foreach ((array) ($item['sub'] ?? []) as $sub) {
                $sub = (array) $sub;
                if ((int) ($sub['hidden'] ?? 0) === 1) {
                    continue;
                }
                $subId = (int) ($sub['id'] ?? 0);
                if ($subId <= 0) {
                    continue;
                }
                $pricings = $this->subPricings($sub);
                if ($pricings === []) {
                    continue;
                }
                $subs[] = [
                    'id' => $subId,
                    'config_id' => $optionId,
                    'option_name' => $this->subOptionName($sub),
                    'sort_order' => $subSort++,
                    'hidden' => 0,
                    'pricings' => $pricings,
                ];
            }

            if ($subs === []) {
                continue;
            }

            $options[] = [
                'id' => $optionId,
                'gid' => $groupId,
                'option_name' => (string) ($item['option_name'] ?? $item['name'] ?? '配置项 #'.$optionId),
                'option_type' => $this->mapConfigOptionType($item),
                'qty_minimum' => (int) ($item['qty_minimum'] ?? 0),
                'qty_maximum' => 0,
                'order' => $sortOrder++,
                'hidden' => 0,
                'is_rebate' => 1,
                'qty_stage' => max((int) ($item['qty_stage'] ?? 1), 1),
                'unit' => '',
                'senior' => 0,
                'auto' => 1,
                'upgrade' => 1,
                'sub' => $subs,
            ];
        }

        if ($options === []) {
            return [];
        }

        return [[
            'id' => $groupId,
            'name' => '产品配置',
            'description' => '',
            'options' => $options,
        ]];
    }

    /**
     * 可同步配置项过滤：跳过隐藏项、OS 类型与缺失 id 的项。
     *
     * @param  array<string, mixed>  $item
     */
    private function isUpgradeableConfigItem(array $item): bool
    {
        if ((int) ($item['hidden'] ?? 0) === 1) {
            return false;
        }
        if ((int) ($item['id'] ?? 0) <= 0) {
            return false;
        }
        if ((int) ($item['option_type'] ?? -1) === 5 || trim((string) ($item['field'] ?? '')) === 'os') {
            return false;
        }

        return true;
    }

    /**
     * TuraIDC 配置项类型 -> 魔方财务 option_type（4=quantity 数量型，1=dropdown 选择型）。
     * 范围型（RANGE_TYPES 或 option_mode=range）按数量型同步，升级时魔方财务传 qty。
     *
     * @param  array<string, mixed>  $item
     */
    private function mapConfigOptionType(array $item): int
    {
        $type = (int) ($item['option_type'] ?? -1);
        $isRange = in_array($type, [4, 7, 9, 11, 14, 15, 16, 17, 18, 19], true)
            || trim((string) ($item['option_mode'] ?? '')) === 'range';

        return $isRange ? 4 : 1;
    }

    /**
     * 子项价格 -> 魔方财务 pricing 行（单币种 CNY，type=configoptions）。
     * 无任何有效价格时返回空数组（该子项不参与升级）。
     *
     * @param  array<string, mixed>  $sub
     * @return list<array<string, mixed>>
     */
    private function subPricings(array $sub): array
    {
        $pricing = is_array($sub['pricing'] ?? null) ? $sub['pricing'] : [];
        $row = [
            'code' => self::CURRENCY,
            'type' => 'configoptions',
            'currency' => 1,
            'relid' => 0,
        ];
        $hasPrice = false;

        foreach (self::PRICING_SETUP_MAP as $cycle => $setupColumn) {
            $price = $this->priceForCycle($pricing, $cycle);
            $row[$cycle] = $price;
            $row[$setupColumn] = 0;
            if ($price >= 0) {
                $hasPrice = true;
            }
        }

        return $hasPrice ? [$row] : [];
    }

    /**
     * 子项名称：魔方财务用 "值|名称" 分隔展示。
     *
     * @param  array<string, mixed>  $sub
     */
    private function subOptionName(array $sub): string
    {
        $label = trim((string) ($sub['option_name'] ?? ''));
        $value = trim((string) ($sub['option_name_first'] ?? $sub['value'] ?? $sub['id'] ?? ''));

        if ($value !== '' && $label !== '' && $value !== $label) {
            return $value.'|'.$label;
        }

        return $label !== '' ? $label : $value;
    }
}
