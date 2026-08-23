<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\AgentDiscount\ProductDiscountGroupRequest;
use App\Http\Resources\Admin\V2\AdminProductDiscountGroupListItemResource;
use App\Models\ProductDiscountGroup;
use App\Services\Finance\AdminAgentDiscountService;

class ProductDiscountGroupController extends Controller
{
    public function __construct(private readonly AdminAgentDiscountService $service) {}
    public function index() { return $this->success(['list' => AdminProductDiscountGroupListItemResource::collection($this->service->listProductGroups())->resolve()]); }
    public function store(ProductDiscountGroupRequest $request) { return $this->success(AdminProductDiscountGroupListItemResource::make($this->service->saveProductGroup(null, $request->payload()))->resolve(), '商品折扣组创建成功'); }
    public function update(ProductDiscountGroupRequest $request, ProductDiscountGroup $productDiscountGroup) { return $this->success(AdminProductDiscountGroupListItemResource::make($this->service->saveProductGroup($productDiscountGroup, $request->payload()))->resolve(), '商品折扣组更新成功'); }
    public function destroy(ProductDiscountGroup $productDiscountGroup) { $this->service->deleteProductGroup($productDiscountGroup); return $this->success(null, '商品折扣组删除成功'); }
}
