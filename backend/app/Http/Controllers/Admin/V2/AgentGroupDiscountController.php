<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\AgentDiscount\AgentGroupDiscountRequest;
use App\Services\Finance\AdminAgentDiscountService;

class AgentGroupDiscountController extends Controller
{
    public function __construct(private readonly AdminAgentDiscountService $service) {}
    public function index() { return $this->success(['rows' => $this->service->matrix()]); }
    public function update(AgentGroupDiscountRequest $request) { return $this->success($this->service->saveMatrix($request->items()), '折扣矩阵保存成功'); }
}
