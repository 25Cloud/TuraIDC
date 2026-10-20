<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\ProductType\DeleteProductTypeRequest;
use App\Http\Requests\Admin\V2\ProductType\ListProductTypesRequest;
use App\Http\Requests\Admin\V2\ProductType\ReorderProductTypesRequest;
use App\Http\Requests\Admin\V2\ProductType\StoreProductTypeRequest;
use App\Http\Requests\Admin\V2\ProductType\UpdateProductTypeRequest;
use App\Http\Resources\Admin\V2\AdminProductTypeResource;
use App\Services\ProductCatalog\ProductTypeService;
use Illuminate\Http\JsonResponse;

class ProductTypeController extends Controller
{
    public function __construct(private readonly ProductTypeService $productTypes) {}

    public function index(ListProductTypesRequest $request): JsonResponse
    {
        return $this->success([
            'list' => AdminProductTypeResource::collection($this->productTypes->list())->resolve(),
        ]);
    }

    public function store(StoreProductTypeRequest $request): JsonResponse
    {
        $payload = $request->validated();

        return $this->success([
            'type' => AdminProductTypeResource::make(
                $this->productTypes->create(
                    (string) $payload['label'],
                    $payload['icon'] ?? null,
                    $payload['product_type'] ?? null,
                    $payload['slug'] ?? null,
                    $this->groupIconPayload($payload)
                )
            )->resolve(),
        ], '商品种类已创建');
    }

    public function update(UpdateProductTypeRequest $request, string $productType): JsonResponse
    {
        $payload = $request->validated();

        return $this->success([
            'type' => AdminProductTypeResource::make(
                $this->productTypes->update(
                    $productType,
                    (string) $payload['label'],
                    array_key_exists('is_hidden', $payload) ? (bool) $payload['is_hidden'] : null,
                    $payload['icon'] ?? null,
                    $payload['product_type'] ?? null,
                    $payload['slug'] ?? null,
                    $this->groupIconPayload($payload)
                )
            )->resolve(),
        ], '商品种类已更新');
    }

    public function destroy(DeleteProductTypeRequest $request, string $productType): JsonResponse
    {
        $this->productTypes->delete($productType);

        return $this->success(null, '商品种类已删除');
    }

    public function reorder(ReorderProductTypesRequest $request): JsonResponse
    {
        $payload = $request->validated();

        return $this->success([
            'list' => AdminProductTypeResource::collection($this->productTypes->reorder((array) $payload['values']))->resolve(),
        ], '商品种类排序已更新');
    }

    /**
     * 只挑出请求里显式提交的图标字段：未提交表示不改，空串表示清空。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function groupIconPayload(array $payload): array
    {
        $groupIcon = [];

        foreach (['icon_text', 'icon_image'] as $field) {
            if (array_key_exists($field, $payload)) {
                $groupIcon[$field] = $payload[$field];
            }
        }

        return $groupIcon;
    }
}
