<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\V2\Finance\ListFinanceLedgerRequest;
use App\Http\Requests\Client\V2\Finance\ShowFinanceLedgerRequest;
use App\Http\Requests\Client\V2\Finance\SummarizeFinanceLedgerRequest;
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
            $this->ledgerQueryService->paginateForClient(
                $request->user(),
                $request->filters(),
                $request->perPage()
            )
        );
    }

    public function summary(SummarizeFinanceLedgerRequest $request): JsonResponse
    {
        return $this->success(
            $this->ledgerQueryService->summaryForClient(
                $request->user(),
                $request->filters()
            )
        );
    }

    public function show(ShowFinanceLedgerRequest $request, int $id): JsonResponse
    {
        return $this->success(
            $this->ledgerQueryService->detailForClient($request->user(), $id)
        );
    }
}
