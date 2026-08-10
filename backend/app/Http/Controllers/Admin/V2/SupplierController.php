<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Supplier\DeleteSupplierRequest;
use App\Http\Requests\Admin\V2\Supplier\ListSupplierProviderTypesRequest;
use App\Http\Requests\Admin\V2\Supplier\ListSuppliersRequest;
use App\Http\Requests\Admin\V2\Supplier\RevealSupplierSecretRequest;
use App\Http\Requests\Admin\V2\Supplier\RunSupplierTaskRequest;
use App\Http\Requests\Admin\V2\Supplier\ShowSupplierRemoteResourceRequest;
use App\Http\Requests\Admin\V2\Supplier\ShowSupplierRequest;
use App\Http\Requests\Admin\V2\Supplier\ShowSupplierSummaryRequest;
use App\Http\Requests\Admin\V2\Supplier\UpdateSupplierStatusRequest;
use App\Http\Requests\Admin\V2\Supplier\UpsertSupplierRequest;
use App\Http\Resources\Admin\V2\AdminActionResultResource;
use App\Http\Resources\Admin\V2\AdminSupplierBalanceResource;
use App\Http\Resources\Admin\V2\AdminSupplierProductConfigTemplateResource;
use App\Http\Resources\Admin\V2\AdminSupplierProviderTypesResource;
use App\Http\Resources\Admin\V2\AdminSupplierRemoteProductsResource;
use App\Http\Resources\Admin\V2\AdminSupplierSummaryResource;
use App\Models\Supplier;
use App\Services\Admin\V2\AdminConfigurationV2QueryService;
use App\Services\Upstream\ProviderRegistry;

class SupplierController extends Controller
{
    public function __construct(
        private readonly AdminConfigurationV2QueryService $queryService,
    ) {}

    public function index(ListSuppliersRequest $request)
    {
        return $this->success($this->queryService->suppliers($request->filters(), $request->pageSize()));
    }

    public function summary(ShowSupplierSummaryRequest $request)
    {
        return $this->success(AdminSupplierSummaryResource::make([
            'total' => Supplier::query()->count(),
            'active' => Supplier::query()->where('status', 1)->count(),
            'inactive' => Supplier::query()->where('status', 0)->count(),
        ])->resolve());
    }

    public function providerTypes(ListSupplierProviderTypesRequest $request, ProviderRegistry $providerRegistry)
    {
        return $this->success(AdminSupplierProviderTypesResource::make([
            'list' => $providerRegistry->options(),
        ])->resolve());
    }

    public function store(UpsertSupplierRequest $request)
    {
        return $this->success(
            $this->queryService->createSupplier($request->supplierPayload(), $request->upstreamBindingPayload()),
            '创建成功'
        );
    }

    public function show(ShowSupplierRequest $request, Supplier $supplier)
    {
        return $this->success($this->queryService->supplierDetail($supplier));
    }

    public function update(UpsertSupplierRequest $request, Supplier $supplier)
    {
        return $this->success(
            $this->queryService->updateSupplier($supplier, $request->supplierPayload(), $request->upstreamBindingPayload()),
            '更新成功'
        );
    }

    public function destroy(DeleteSupplierRequest $request, Supplier $supplier)
    {
        $result = $this->queryService->deleteSupplier($supplier);

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    public function revealSecret(RevealSupplierSecretRequest $request, Supplier $supplier)
    {
        return $this->success($this->queryService->supplierSecret($supplier, $request->secretKey()));
    }

    public function updateStatus(UpdateSupplierStatusRequest $request, Supplier $supplier)
    {
        $result = $this->queryService->updateSupplierStatus($supplier, $request->enabled());

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    public function balance(ShowSupplierRemoteResourceRequest $request, Supplier $supplier)
    {
        return $this->success(
            AdminSupplierBalanceResource::make($this->queryService->supplierBalance($supplier))->resolve(),
            '余额获取成功'
        );
    }

    public function products(ShowSupplierRemoteResourceRequest $request, Supplier $supplier)
    {
        return $this->success(
            AdminSupplierRemoteProductsResource::make($this->queryService->supplierProducts($supplier))->resolve(),
            '供应商商品同步成功'
        );
    }

    public function productConfigTemplate(ShowSupplierRemoteResourceRequest $request, Supplier $supplier)
    {
        return $this->success(
            AdminSupplierProductConfigTemplateResource::make(
                $this->queryService->supplierProductConfigTemplate($supplier, $request->productId())
            )->resolve(),
            '商品配置拉取成功'
        );
    }

    public function runTask(RunSupplierTaskRequest $request, Supplier $supplier)
    {
        $result = $this->queryService->runSupplierTask($supplier, $request->taskType(), $request->payload());

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }
}
