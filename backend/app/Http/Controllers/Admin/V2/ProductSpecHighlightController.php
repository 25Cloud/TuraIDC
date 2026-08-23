<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\SpecCatalog\SaveProductSpecHighlightRequest;
use App\Models\Product;
use App\Services\ProductCatalog\ProductSpecHighlightService;
use Illuminate\Http\JsonResponse;

class ProductSpecHighlightController extends Controller
{
    public function __construct(
        private readonly ProductSpecHighlightService $specHighlightService,
    ) {}

    /**
     * 当前生效的规格亮点与后台覆盖。
     */
    public function show(Product $product): JsonResponse
    {
        $productId = (int) $product->id;

        return $this->success([
            'product_id' => $productId,
            'spec_highlights' => $this->specHighlightService->resolveHighlightsForProduct($product),
            'spec_highlight_text' => $this->specHighlightService->resolveHighlightText($product),
            'overrides' => (object) $this->specHighlightService->overridesFor($productId),
            'dimensions' => $this->specHighlightService->dimensionOptions(),
        ]);
    }

    /**
     * 保存后台自定义覆盖；items 留空表示恢复自动提取。
     */
    public function update(Product $product, SaveProductSpecHighlightRequest $request): JsonResponse
    {
        $productId = (int) $product->id;
        $this->specHighlightService->saveOverrides($productId, (array) $request->validated('items', []));

        return $this->success([
            'product_id' => $productId,
            'overrides' => (object) $this->specHighlightService->overridesFor($productId),
        ], '规格描述栏已更新');
    }
}
