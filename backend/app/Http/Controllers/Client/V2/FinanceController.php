<?php

namespace App\Http\Controllers\Client\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\V2\Finance\ListBalanceLogsRequest;
use App\Http\Requests\Client\V2\Finance\SummarizeBalanceLogsRequest;
use App\Services\Finance\ClientFinanceQueryService;

class FinanceController extends Controller
{
    public function __construct(
        private ClientFinanceQueryService $financeQueryService,
    ) {}

    /**
     * 浣欓鍙樺姩璁板綍
     */
    public function balanceLogs(ListBalanceLogsRequest $request)
    {
        return $this->success(
            $this->financeQueryService->paginateBalanceLogs(
                $request->user(),
                $request->filters(),
                $request->perPage(15, 200)
            )
        );
    }

    public function balanceLogsSummary(SummarizeBalanceLogsRequest $request)
    {
        return $this->success(
            $this->financeQueryService->balanceLogSummary(
                $request->user(),
                $request->filters()
            )
        );
    }
}
