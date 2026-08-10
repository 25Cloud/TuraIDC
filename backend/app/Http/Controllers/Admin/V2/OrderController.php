<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Order\ListOrdersRequest;
use App\Http\Requests\Admin\V2\Order\ShowOrderRequest;
use App\Http\Resources\Admin\V2\AdminOrderDetailResource;
use App\Http\Resources\Admin\V2\AdminOrderSummaryResource;
use App\Services\Finance\OrderV2QueryService;
use Illuminate\Http\JsonResponse;

class OrderController extends Controller
{
    public function __construct(
        private readonly OrderV2QueryService $orders,
    ) {}

    public function index(ListOrdersRequest $request): JsonResponse
    {
        return $this->paginate(
            $this->orders->paginateAdminOrders($request->validated()),
            AdminOrderSummaryResource::class
        );
    }

    public function show(ShowOrderRequest $request, int $order): JsonResponse
    {
        return $this->success([
            'order' => (new AdminOrderDetailResource($this->orders->findAdminOrder($order)))->resolve(),
        ]);
    }
}
