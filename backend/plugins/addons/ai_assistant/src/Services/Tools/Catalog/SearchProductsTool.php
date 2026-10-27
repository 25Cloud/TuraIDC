<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Catalog;

use App\Models\Product;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiTool;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiToolContext;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Concerns\FormatsToolResults;
use App\Services\ProductCatalog\ProductV2QueryService;

/**
 * 找商品：站点目录按分组/类型过滤，再在本地按名称匹配关键词。
 *
 * 站点侧查询接口没有 keyword（只有分组与类型），管理端的关键词是内存全表过滤，
 * 两者都不能直接当 AI 的检索入口，所以这里在服务返回的当页结果上做名称匹配。
 */
final class SearchProductsTool extends AiTool
{
    use FormatsToolResults;

    public function __construct(private readonly ProductV2QueryService $products) {}

    public function name(): string
    {
        return 'search_products';
    }

    public function description(): string
    {
        return '按关键词或分组查找在售商品，返回商品编号、名称、月付价格与库存状态。用于回答「有没有香港服务器」「有什么便宜的小鸡」这类选购问题。';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'keyword' => ['type' => 'string', 'maxLength' => 60, 'description' => '商品名称关键词，例如「香港」「CN2」，可留空'],
            'first_product_group_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '一级分组的编号，可选'],
            'second_product_group_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '二级分组的编号，可选'],
            'third_product_group_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '三级分组的编号，可选'],
            'product_type' => ['type' => 'string', 'maxLength' => 40, 'description' => '业务类型编码，例如 cloud_server，可选'],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'description' => '最多返回几条，默认 6'],
        ]);
    }

    public function run(AiToolContext $context, array $arguments): array
    {
        $limit = (int) ($arguments['limit'] ?? 6);
        $filters = [
            'page' => 1,
            'page_size' => max($limit * 2, 10),
        ];
        foreach (['first_product_group_id', 'second_product_group_id', 'third_product_group_id', 'product_type'] as $key) {
            if (isset($arguments[$key]) && $arguments[$key] !== '') {
                $filters[$key] = $arguments[$key];
            }
        }

        $keyword = mb_strtolower(trim((string) ($arguments['keyword'] ?? '')));
        $items = [];
        $paginator = $this->products->paginateSiteProducts($filters);

        foreach ($this->toList($paginator) as $product) {
            if (! $product instanceof Product) {
                continue;
            }
            $name = (string) $product->name;
            if ($keyword !== '' && ! str_contains(mb_strtolower($name), $keyword)) {
                continue;
            }
            $items[] = [
                'product_id' => (int) $product->id,
                'name' => $name,
                'product_type' => (string) ($product->product_type ?? ''),
                'monthly_price' => $this->monthlyPrice($product),
                'stock' => (int) ($product->stock ?? -1),
            ];
            if (count($items) >= $limit) {
                break;
            }
        }

        $summary = $items === []
            ? '没有找到符合条件的在售商品'
            : '找到 ' . count($items) . ' 个商品：' . implode('、', array_map(
                static fn (array $row): string => $row['name'] . '（月付 ' . $row['monthly_price'] . '）',
                $items,
            ));

        return [
            'summary' => $this->excerpt($summary, 400),
            'data' => [
                'keyword' => $keyword,
                'total_matched' => count($items),
                'items' => $items,
                'note' => '价格仅为月付挂牌价，实际金额与配置加价必须调用 quote_price 获取实时报价',
            ],
        ];
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
