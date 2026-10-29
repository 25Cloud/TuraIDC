<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Catalog;

use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use App\Services\ProductCatalog\ProductV2QueryService;
use App\Support\ProductGroupHierarchyFields;
use App\Support\SqlLike;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiTool;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiToolContext;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Concerns\DescribesProductForSale;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Concerns\FormatsToolResults;

/**
 * 找商品：站点目录按分组/类型过滤，再在本地按关键词匹配。
 *
 * 站点侧查询接口没有 keyword（只有分组与类型），所以关键词必须在这里落地。两条通道：
 *
 *   1. 商品名/规格命中（「2核4G」「20M」这类词）；
 *   2. 分组名命中——「香港」「华中」「CN2」「三网」这类词只出现在分组名里，商品名是
 *      `2核2G20Mbps`，只扫商品名会让「有没有香港的」永远搜不到东西。命中分组名时
 *      改用分组 id 过滤重新查一次，比在内存里翻页可靠。
 *
 * 返回的每一行都带完整叫法（区域 · 机房 · 规格）与官网链接，见 DescribesProductForSale：
 * 只说规格用户不知道是哪台机器，没有链接用户还得自己找回去。
 */
final class SearchProductsTool extends AiTool
{
    use DescribesProductForSale;
    use FormatsToolResults;

    /** 关键词最多再展开几个分组去查，防止一个宽泛词把所有分组都扫一遍 */
    private const MAX_GROUP_FILTERS = 3;

    public function __construct(private readonly ProductV2QueryService $products) {}

    public function name(): string
    {
        return 'search_products';
    }

    public function description(): string
    {
        return '查找在售商品，返回每个商品的完整叫法（区域 · 机房/线路 · 规格）、分组路径、月付价格、库存与官网配置页链接。keyword 同时匹配商品规格与分组名（可填「香港」「华中」「CN2」「2核4G」等）。用于回答「有没有香港服务器」「有什么便宜的小鸡」这类选购问题。';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'keyword' => ['type' => 'string', 'maxLength' => 60, 'description' => '关键词，匹配商品规格或分组/机房名，例如「香港」「CN2」「2核4G」，可留空表示按分组浏览'],
            'first_product_group_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '一级分组的编号，可选'],
            'second_product_group_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '二级分组的编号，可选'],
            'third_product_group_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '三级分组的编号，可选'],
            'product_type' => ['type' => 'string', 'maxLength' => 40, 'description' => '业务类型编码，例如 cloud_server，可选'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'description' => '最多返回几条，默认 6'],
        ]);
    }

    public function run(AiToolContext $context, array $arguments): array
    {
        $limit = max(1, min(10, (int) ($arguments['limit'] ?? 6)));
        $filters = [
            'page' => 1,
            // 关键词在内存里过滤，单页给太少会漏；站点接口上限就是 50
            'page_size' => min(max($limit * 4, 20), 50),
        ];
        foreach (['first_product_group_id', 'second_product_group_id', 'third_product_group_id', 'product_type'] as $key) {
            if (isset($arguments[$key]) && $arguments[$key] !== '') {
                $filters[$key] = $arguments[$key];
            }
        }

        $keyword = mb_strtolower(trim((string) ($arguments['keyword'] ?? '')));
        $matched = $keyword === ''
            ? $this->browse($filters, $limit)
            : $this->search($filters, $keyword, $limit);

        $items = [];
        foreach ($matched as $product) {
            $items[] = $this->describeProductForSale($product, $this->monthlyPrice($product));
        }

        if ($items === []) {
            return [
                'summary' => $keyword === ''
                    ? '当前分组下没有在售商品'
                    : '没有找到与「'.$keyword.'」匹配的在售商品',
                'data' => [
                    'keyword' => $keyword,
                    'total_matched' => 0,
                    'items' => [],
                    // 空结果也要给下一步：让模型引导用户换词，而不是硬编商品
                    'note' => '没有命中时不要编造商品，改为询问用户用途/预算/地区，或用更短的词（如只写「香港」「2核4G」）再查一次',
                ],
            ];
        }

        $summary = '找到 '.count($items).' 个商品：'.implode('、', array_map(
            static fn (array $row): string => sprintf(
                '%s（月付 %s%s）',
                (string) $row['title'],
                (string) ($row['monthly_price'] ?? '未知'),
                $row['in_stock'] === false ? '，暂时缺货' : '',
            ),
            $items,
        ));

        return [
            'summary' => $this->excerpt($summary, 900),
            'data' => [
                'keyword' => $keyword,
                'total_matched' => count($items),
                'items' => $items,
                'note' => '把 title 原样说给用户（不要只报规格）；推荐时必须附上 product_url / link。价格仅为月付挂牌价，实际金额与配置加价必须调用 quote_price',
            ],
        ];
    }

    /**
     * 无关键词：按分组/类型浏览。
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, Product>
     */
    private function browse(array $filters, int $limit): array
    {
        $rows = [];
        foreach ($this->toList($this->products->paginateSiteProducts($filters)) as $product) {
            if (! $product instanceof Product) {
                continue;
            }
            $rows[] = $product;
            if (count($rows) >= $limit) {
                break;
            }
        }

        return $rows;
    }

    /**
     * 有关键词：先按商品名/规格命中，不够再按分组名展开。
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, Product>
     */
    private function search(array $filters, string $keyword, int $limit): array
    {
        $matched = [];

        foreach ($this->toList($this->products->paginateSiteProducts($filters)) as $product) {
            if (! $product instanceof Product) {
                continue;
            }
            if ($this->matchesKeyword($product, $keyword)) {
                $matched[(int) $product->id] = $product;
            }
            if (count($matched) >= $limit) {
                return array_slice(array_values($matched), 0, $limit);
            }
        }

        foreach ($this->groupFiltersFor($keyword) as $groupFilter) {
            foreach ($this->toList($this->products->paginateSiteProducts(array_replace($filters, $groupFilter))) as $product) {
                if (! $product instanceof Product) {
                    continue;
                }
                // 分组过滤只是缩小范围，命中判据仍按关键词走：用户搜「香港」时
                // 同组里没有「香港」字样的商品不该被算进来。
                if ($this->matchesKeyword($product, $keyword)) {
                    $matched[(int) $product->id] = $product;
                }
                if (count($matched) >= $limit) {
                    return array_slice(array_values($matched), 0, $limit);
                }
            }
        }

        return array_values($matched);
    }

    /**
     * 商品名 / 规格 / 分组路径任一命中即可。
     *
     * 分组名命中是「有没有香港的」这类问题唯一能搜到东西的通道：商品名是 `2核2G20Mbps`，
     * 里面没有半个字提到香港。
     */
    private function matchesKeyword(Product $product, string $keyword): bool
    {
        $hierarchy = ProductGroupHierarchyFields::fromProduct($product);
        $haystack = mb_strtolower(implode(' ', array_filter([
            (string) ($product->custom_display_name ?? ''),
            (string) ($hierarchy['group_path_text'] ?? ''),
        ], static fn (string $part): bool => $part !== '')));

        return str_contains($haystack, $keyword);
    }

    /**
     * 关键词命中的分组 → 站点商品查询的过滤参数，三级优先（更具体）。
     *
     * @return array<int, array<string, int>>
     */
    private function groupFiltersFor(string $keyword): array
    {
        $like = SqlLike::contains($keyword);
        $filters = [];

        $thirdIds = ThirdProductGroup::query()
            ->where('is_visible', 1)
            ->where('name', 'like', $like)
            ->orderBy('sort_order')
            ->limit(self::MAX_GROUP_FILTERS)
            ->pluck('id');

        foreach ($thirdIds as $id) {
            $filters[] = ['third_product_group_id' => (int) $id];
        }

        if (count($filters) < self::MAX_GROUP_FILTERS) {
            $secondIds = SecondProductGroup::query()
                ->where('is_visible', 1)
                ->where('name', 'like', $like)
                ->orderBy('sort_order')
                ->limit(self::MAX_GROUP_FILTERS - count($filters))
                ->pluck('id');

            foreach ($secondIds as $id) {
                $filters[] = ['second_product_group_id' => (int) $id];
            }
        }

        return $filters;
    }

    private function monthlyPrice(Product $product): string
    {
        try {
            $price = $product->getPriceByBillingCycle('monthly');
        } catch (\Throwable) {
            $price = null;
        }

        return is_numeric($price) ? number_format((float) $price, 2, '.', '') : '未知';
    }
}
