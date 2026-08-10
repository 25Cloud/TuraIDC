<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\V2\Ledger\ListLedgerRequest;
use App\Services\Finance\ClientLedgerV2QueryService;

class LedgerController extends Controller
{
    public function __construct(
        private readonly ClientLedgerV2QueryService $ledgerQueryService,
    ) {}

    public function index(ListLedgerRequest $request)
    {
        return $this->success(
            $this->ledgerQueryService->paginate(
                $request->user(),
                $request->filters(),
                $request->perPage()
            )
        );
    }
}
