<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Constants\OrderType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Finance\ListFinanceOrdersRequest;
use App\Http\Requests\Admin\V2\Finance\ListFinanceRechargesRequest;
use App\Http\Requests\Admin\V2\Finance\ListFinanceUpgradeOrdersRequest;
use App\Http\Requests\Admin\V2\Finance\ShowNewCustomerDailySummaryRequest;
use App\Http\Resources\Admin\V2\AdminFinanceOrderListItemResource;
use App\Http\Resources\Admin\V2\AdminFinanceRechargeListItemResource;
use App\Http\Resources\Admin\V2\AdminNewCustomerDailySummaryResource;
use App\Http\Resources\Admin\V2\AdminProductIncomeSummaryResource;
use App\Services\Finance\AdminFinanceQueryService;
use Illuminate\Http\JsonResponse;

class FinanceMenuController extends Controller
{
    public function __construct(
        private readonly AdminFinanceQueryService $financeQueryService,
    ) {}

    public function recharges(ListFinanceRechargesRequest $request): JsonResponse
    {
        return $this->paginate(
            $this->financeQueryService->paginateRecharges($request->filters(), $request->pageSize()),
            AdminFinanceRechargeListItemResource::class
        );
    }

    public function newCustomerDailySummary(ShowNewCustomerDailySummaryRequest $request): JsonResponse
    {
        $data = $request->filters();

        return $this->success(
            (new AdminNewCustomerDailySummaryResource(
                $this->financeQueryService->dailyCustomerSummary(
                    (string) ($data['start_date'] ?? $data['month']),
                    isset($data['end_date']) ? (string) $data['end_date'] : null
                )
            ))->resolve()
        );
    }

    public function productIncomeSummary(ShowNewCustomerDailySummaryRequest $request): JsonResponse
    {
        $data = $request->filters();

        return $this->success(
            (new AdminProductIncomeSummaryResource(
                $this->financeQueryService->productIncomeSummary(
                    (string) ($data['start_date'] ?? $data['month']),
                    isset($data['end_date']) ? (string) $data['end_date'] : null
                )
            ))->resolve()
        );
    }

    public function renewalOrders(ListFinanceOrdersRequest $request): JsonResponse
    {
        return $this->paginate(
            $this->financeQueryService->paginateOrders($request->filters(), $request->pageSize(), OrderType::RENEW),
            AdminFinanceOrderListItemResource::class
        );
    }

    public function upgradeOrders(ListFinanceUpgradeOrdersRequest $request): JsonResponse
    {
        return $this->paginate(
            $this->financeQueryService->paginateUpgradeOrders($request->filters(), $request->pageSize()),
            AdminFinanceOrderListItemResource::class
        );
    }
}
