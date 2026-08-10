<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Finance\ListFinanceLedgerRequest;
use App\Http\Requests\Admin\V2\Finance\ShowFinanceLedgerRequest;
use App\Http\Requests\Admin\V2\Finance\SummarizeFinanceLedgerRequest;
use App\Services\Finance\FinanceLedgerQueryService;
use Illuminate\Http\JsonResponse;

class FinanceLedgerController extends Controller
{
    public function __construct(
        private readonly FinanceLedgerQueryService $ledgerQueryService,
    ) {}

    public function index(ListFinanceLedgerRequest $request): JsonResponse
    {
        return $this->success(
            $this->ledgerQueryService->paginateForAdmin(
                $request->filters(),
                $request->perPage()
            )
        );
    }

    public function summary(SummarizeFinanceLedgerRequest $request): JsonResponse
    {
        return $this->success(
            $this->ledgerQueryService->summaryForAdmin($request->filters())
        );
    }

    public function show(ShowFinanceLedgerRequest $request, int $id): JsonResponse
    {
        return $this->success(
            $this->ledgerQueryService->detailForAdmin($id)
        );
    }
}
