<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools\Catalog;

use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Services\Ai\Tools\AiTool;
use App\Services\Ai\Tools\AiToolContext;
use App\Services\Ai\Tools\Concerns\FormatsToolResults;
use App\Services\ProductCatalog\ProductV2QueryService;

/**
 * 商品详情：配置项结构与可选周期。
 *
 * 站点详情接口对配置项做了白名单裁剪，子项不含价格——加价必须走 quote_price，
 * 这里只回答「有哪些配置可选、有哪些周期」，绝不报价格差。
 */
final class GetProductDetailTool extends AiTool
{
    use FormatsToolResults;

    public function __construct(private readonly ProductV2QueryService $products) {}

    public function name(): string
    {
        return 'get_product_detail';
    }

    public function description(): string
    {
        return '查看某个商品的配置项、可选计费周期与购买前置条件（实名/手机号）。不含配置项加价，价格必须另调 quote_price。';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'product_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '商品编号'],
        ], ['product_id']);
    }

    public function run(AiToolContext $context, array $arguments): array
    {
        $productId = (int) $arguments['product_id'];

        try {
            $product = $this->products->findSiteProduct($productId);
        } catch (\Throwable) {
            throw new BusinessException('商品不存在或已下架', 40400, 404);
        }

        if (! $product instanceof Product) {
            throw new BusinessException('商品不存在或已下架', 40400, 404);
        }

        $cycles = [];
        $pricing = is_array($product->pricing) ? $product->pricing : [];
        foreach (['monthly', 'quarterly', 'semiannually', 'annually'] as $cycle) {
            if (isset($pricing[$cycle]) && is_numeric($pricing[$cycle])) {
                $cycles[] = $cycle . '=' . number_format((float) $pricing[$cycle], 2, '.', '');
            }
        }

        $options = [];
        $rawOptions = is_array($product->config_options) ? $product->config_options : [];
        foreach (array_slice($rawOptions, 0, 20) as $option) {
            if (! is_array($option)) {
                continue;
            }
            $options[] = [
                'field' => (string) ($option['field'] ?? $option['form_key'] ?? ''),
                'name' => (string) ($option['name'] ?? $option['option_name'] ?? ''),
                'type' => (int) ($option['type'] ?? 0),
                'sub_count' => is_array($option['sub'] ?? null) ? count($option['sub']) : 0,
            ];
        }

        $purchaseRequires = is_array($product->purchase_requires) ? $product->purchase_requires : [];

        return [
            'summary' => sprintf(
                '商品「%s」共 %d 个配置项，可选周期：%s',
                (string) $product->name,
                count($options),
                $cycles === [] ? '以实时报价为准' : implode('、', $cycles),
            ),
            'data' => [
                'product_id' => (int) $product->id,
                'name' => (string) $product->name,
                'product_type' => (string) ($product->product_type ?? ''),
                'stock' => (int) ($product->stock ?? -1),
                'pricing_cycles' => $cycles,
                'config_options' => $options,
                'config_options_truncated' => count($rawOptions) > 20,
                'purchase_requires' => [
                    'require_verification' => (bool) ($purchaseRequires['require_verification'] ?? false),
                    'require_phone' => (bool) ($purchaseRequires['require_phone'] ?? false),
                ],
                'note' => '配置项加价与最终金额请调用 quote_price',
            ],
        ];
    }
}
