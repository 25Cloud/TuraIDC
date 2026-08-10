<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Product\DeleteProductRequest;
use App\Http\Requests\Admin\V2\Product\ListProductOwnersRequest;
use App\Http\Requests\Admin\V2\Product\ListProductsRequest;
use App\Http\Requests\Admin\V2\Product\ProductBatchUpdateCategoryRequest;
use App\Http\Requests\Admin\V2\Product\ProductBatchUpdateProvisionHostnameRequest;
use App\Http\Requests\Admin\V2\Product\ProductPullTrafficPackageCatalogRequest;
use App\Http\Requests\Admin\V2\Product\ProductReorderRequest;
use App\Http\Requests\Admin\V2\Product\ProductSplitPreviewRequest;
use App\Http\Requests\Admin\V2\Product\ProductSplitRequest;
use App\Http\Requests\Admin\V2\Product\ShowProductRequest;
use App\Http\Requests\Admin\V2\Product\ShowProductSummaryRequest;
use App\Http\Requests\Admin\V2\Product\StoreProductRequest;
use App\Http\Requests\Admin\V2\Product\UpdateProductRequest;
use App\Http\Requests\Admin\V2\Product\UpdateProductStatusRequest;
use App\Http\Resources\Admin\V2\AdminActionResultResource;
use App\Http\Resources\Admin\V2\AdminProductDetailResource;
use App\Http\Resources\Admin\V2\AdminProductOperationPayloadResource;
use App\Http\Resources\Admin\V2\AdminProductOwnerPageResource;
use App\Http\Resources\Admin\V2\AdminProductSummaryResource;
use App\Http\Resources\Admin\V2\AdminProductSummaryStatsResource;
use App\Models\Product;
use App\Services\Admin\V2\AdminCatalogActionV2Service;
use App\Services\ProductCatalog\ProductV2QueryService;
use Illuminate\Http\JsonResponse;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductV2QueryService $products,
        private readonly AdminCatalogActionV2Service $actions,
    ) {}

    public function index(ListProductsRequest $request): JsonResponse
    {
        return $this->paginate(
            $this->products->paginateAdminProducts($request->validated()),
            AdminProductSummaryResource::class
        );
    }

    public function summary(ShowProductSummaryRequest $request): JsonResponse
    {
        return $this->success(AdminProductSummaryStatsResource::make($this->products->adminSummary())->resolve());
    }

    public function show(ShowProductRequest $request, int $product): JsonResponse
    {
        return $this->success([
            'product' => (new AdminProductDetailResource($this->products->findAdminProduct($product)))->resolve(),
        ]);
    }

    public function owners(ListProductOwnersRequest $request, Product $product): JsonResponse
    {
        return $this->success(AdminProductOwnerPageResource::make(
            $this->products->adminProductOwners($product, $request->filters(), $request->perPage())
        )->resolve());
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->actions->createProduct($request->validated());

        return $this->success([
            'product' => AdminProductDetailResource::make($this->products->findAdminProduct((int) $product->id))->resolve(),
        ], '商品创建成功');
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $product = $this->actions->updateProduct($product, $request->validated());

        return $this->success([
            'product' => AdminProductDetailResource::make($this->products->findAdminProduct((int) $product->id))->resolve(),
        ], '商品更新成功');
    }

    public function destroy(DeleteProductRequest $request, int $product): JsonResponse
    {
        $result = $this->actions->deleteProduct($product);

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    public function restore(DeleteProductRequest $request, int $product): JsonResponse
    {
        $restored = $this->actions->restoreProduct($product);

        return $this->success([
            'product' => AdminProductDetailResource::make($this->products->findAdminProduct((int) $restored->id))->resolve(),
        ], '商品已恢复');
    }

    public function forceDelete(DeleteProductRequest $request, int $product): JsonResponse
    {
        $result = $this->actions->forceDeleteProduct($product);

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    public function updateStatus(UpdateProductStatusRequest $request, Product $product): JsonResponse
    {
        $result = $this->actions->updateProductStatus($product, $request->enabled());

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    public function reorder(ProductReorderRequest $request): JsonResponse
    {
        return $this->success(
            AdminProductOperationPayloadResource::make($this->actions->reorderProduct($request->validated()))->resolve(),
            '商品位置已更新'
        );
    }

    public function splitPreview(ProductSplitPreviewRequest $request): JsonResponse
    {
        return $this->success(
            AdminProductOperationPayloadResource::make($this->actions->splitPreview($request->validated()))->resolve(),
            '商品拆分预览完成'
        );
    }

    public function split(ProductSplitRequest $request): JsonResponse
    {
        return $this->success(
            AdminProductOperationPayloadResource::make($this->actions->splitProducts($request->validated()))->resolve(),
            '商品拆分完成'
        );
    }

    public function batchUpdateCategory(ProductBatchUpdateCategoryRequest $request): JsonResponse
    {
        return $this->success(
            AdminProductOperationPayloadResource::make($this->actions->batchUpdateCategory($request->validated()))->resolve(),
            '商品分类已批量更新'
        );
    }

    public function batchUpdateProvisionHostname(ProductBatchUpdateProvisionHostnameRequest $request): JsonResponse
    {
        return $this->success(
            AdminProductOperationPayloadResource::make($this->actions->batchUpdateProvisionHostname($request->validated(), [
                'operator_id' => (int) ($request->user()?->id ?? 0),
                'operator_name' => (string) ($request->user()?->username ?? $request->user()?->name ?? $request->user()?->email ?? 'admin'),
                'trace_id' => (string) $request->header('X-Request-Id', ''),
                'ip_address' => (string) $request->ip(),
            ]))->resolve(),
            '商品开通主机名规则已更新'
        );
    }

    public function pullTrafficPackageCatalog(ProductPullTrafficPackageCatalogRequest $request): JsonResponse
    {
        return $this->success(
            AdminProductOperationPayloadResource::make($this->actions->pullTrafficPackageCatalog($request->validated()))->resolve(),
            '流量包配置拉取成功'
        );
    }
}
