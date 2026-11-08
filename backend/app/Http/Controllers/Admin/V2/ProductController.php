<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Product\CopyProductPricingRequest;
use App\Http\Requests\Admin\V2\Product\DeleteProductRequest;
use App\Http\Requests\Admin\V2\Product\ListProductOwnersRequest;
use App\Http\Requests\Admin\V2\Product\ListProductsRequest;
use App\Http\Requests\Admin\V2\Product\ProductBatchDeleteRequest;
use App\Http\Requests\Admin\V2\Product\ProductBatchUpdateCategoryRequest;
use App\Http\Requests\Admin\V2\Product\ProductBatchUpdateProvisionHostnameRequest;
use App\Http\Requests\Admin\V2\Product\ProductBatchUpdateRequest;
use App\Http\Requests\Admin\V2\Product\ProductPullTrafficPackageCatalogRequest;
use App\Http\Requests\Admin\V2\Product\ProductReorderRequest;
use App\Http\Requests\Admin\V2\Product\ProductSortOrderRequest;
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
use App\Jobs\PullTrafficPackageCatalogJob;
use App\Models\Product;
use App\Services\Admin\V2\AdminCatalogActionV2Service;
use App\Services\ProductCatalog\ProductPricingSyncService;
use App\Services\ProductCatalog\ProductV2QueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductV2QueryService $products,
        private readonly AdminCatalogActionV2Service $actions,
        private readonly ProductPricingSyncService $pricingSync,
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

    /**
     * 按月价同步到其他周期（Issue #77）。
     *
     * 一次性动作：服务层按 `BillingCycle` 的月数换算并写库，之后各周期独立可改；
     * 这里不注册任何观察者，改月付不会反过来改年付。
     */
    public function copyPricing(CopyProductPricingRequest $request, Product $product): JsonResponse
    {
        $result = $this->pricingSync->copy($product, $request->validated(), [
            'operator_id' => (int) ($request->user()?->id ?? 0),
            'operator_name' => (string) ($request->user()?->username ?? $request->user()?->name ?? $request->user()?->email ?? 'admin'),
            'ip_address' => (string) $request->ip(),
            'trace_id' => (string) $request->header('X-Request-Id', ''),
        ]);

        // 服务层返回值里 message 一定存在（同 destroy/restore 的写法），不再做空值兜底
        $message = (string) $result['message'];
        unset($result['message']);

        return $this->success($result, $message);
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

    public function batchUpdate(ProductBatchUpdateRequest $request): JsonResponse
    {
        return $this->success(
            AdminProductOperationPayloadResource::make($this->actions->batchUpdateProducts($request->validated()))->resolve(),
            '商品已批量更新'
        );
    }

    /**
     * 商品列表拖拽排序。
     */
    public function sortOrder(ProductSortOrderRequest $request): JsonResponse
    {
        return $this->success(
            AdminProductOperationPayloadResource::make($this->actions->sortProducts($request->payload()))->resolve(),
            '商品排序已更新'
        );
    }

    public function batchDelete(ProductBatchDeleteRequest $request): JsonResponse
    {
        return $this->success(
            AdminProductOperationPayloadResource::make($this->actions->batchDeleteProducts($request->validated()))->resolve(),
            '商品已批量删除'
        );
    }

    public function batchForceDelete(ProductBatchDeleteRequest $request): JsonResponse
    {
        return $this->success(
            AdminProductOperationPayloadResource::make($this->actions->batchForceDeleteProducts($request->validated()))->resolve(),
            '商品已批量彻底删除'
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

    /**
     * 一键对接：拉取上游流量包档位。
     *
     * 上游拉取会串行访问 HTTP、最坏几分钟，同步等会把请求挂死，所以改成「提交任务 + 轮询」：
     * - 有后台队列：立即返回 `pending` 任务，前端按 task_id 轮询；
     * - `QUEUE_CONNECTION=sync`（本地与测试环境没有 worker）：就地执行，返回 `done` 与完整结果，
     *   行为与改造前一致，老前端读 `data.source/traffic/packages` 仍然拿得到。
     */
    public function pullTrafficPackageCatalog(ProductPullTrafficPackageCatalogRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $taskId = (string) Str::uuid();
        $state = PullTrafficPackageCatalogJob::seedState($taskId);

        if ($this->shouldRunPullInline()) {
            try {
                PullTrafficPackageCatalogJob::dispatchSync($taskId, $payload);
            } catch (\Throwable $exception) {
                // 任务体会先把失败原因写进任务状态再抛；这里据此回统一的业务错误信封（带 task_id，前端可重试/查询）。
                // 状态没落盘说明是框架级异常，原样抛出交给全局处理器，别把 500 伪装成业务错误。
                $state = PullTrafficPackageCatalogJob::readState($taskId) ?? $state;

                if (($state['status'] ?? '') !== PullTrafficPackageCatalogJob::STATUS_FAILED) {
                    throw $exception;
                }
            }

            $state = PullTrafficPackageCatalogJob::readState($taskId) ?? $state;

            // 同步执行失败时保持改造前的契约：回 422 业务错误信封，前端照旧弹原始原因；
            // 任务状态同时留在 Cache 里，轮询接口也能读到 failed。
            if (($state['status'] ?? '') === PullTrafficPackageCatalogJob::STATUS_FAILED) {
                return $this->error(
                    42200,
                    (string) ($state['error'] ?? '流量包配置拉取失败'),
                    $this->trafficPackagePullPayload($state)
                );
            }

            return $this->success($this->trafficPackagePullPayload($state), '流量包配置拉取成功');
        }

        PullTrafficPackageCatalogJob::dispatch($taskId, $payload);

        return $this->success($this->trafficPackagePullPayload($state), '流量包拉取任务已提交，正在后台执行');
    }

    /**
     * 查询一键拉取任务的进度。与提交接口返回同一份数据形状。
     */
    public function trafficPackagePullStatus(string $task): JsonResponse
    {
        $state = PullTrafficPackageCatalogJob::readState($task);

        if ($state === null) {
            return $this->error(40400, '拉取任务不存在或已过期，请重新发起一键拉取');
        }

        return $this->success($this->trafficPackagePullPayload($state));
    }

    /**
     * 没有后台 worker 时（sync/空队列连接）同步执行，否则任务会一直停在 pending。
     */
    private function shouldRunPullInline(): bool
    {
        $connection = trim((string) config('queue.default', 'sync'));

        return $connection === '' || $connection === 'sync';
    }

    /**
     * 响应数据：老字段（source/traffic/packages 以及结果里的其它键）平铺在顶层保证老前端可用，
     * 新字段（task_id/status/progress/step/result/error/时间戳）并列追加。
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function trafficPackagePullPayload(array $state): array
    {
        $result = is_array($state['result'] ?? null) ? $state['result'] : [];

        // 沿用既有下发净化：上游结果里的 token / api_key 等敏感键一律不下发（老接口就是这么做的）
        return AdminProductOperationPayloadResource::make(array_merge($result, [
            'source' => $result['source'] ?? null,
            'traffic' => $result['traffic'] ?? null,
            'packages' => is_array($result['packages'] ?? null) ? $result['packages'] : [],
            'task_id' => (string) ($state['task_id'] ?? ''),
            'status' => (string) ($state['status'] ?? PullTrafficPackageCatalogJob::STATUS_PENDING),
            'progress' => (int) ($state['progress'] ?? 0),
            'step' => (string) ($state['step'] ?? ''),
            'result' => is_array($state['result'] ?? null) ? $state['result'] : null,
            'error' => $state['error'] ?? null,
            'started_at' => $state['started_at'] ?? null,
            'finished_at' => $state['finished_at'] ?? null,
        ]))->resolve();
    }
}
