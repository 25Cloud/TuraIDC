<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Http\Resources\Client\V2\ClientLedgerSummaryResource;
use App\Models\User;

class ClientLedgerV2QueryService
{
    public function __construct(
        private readonly FinanceLedgerQueryService $financeLedgerQueryService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function paginate(User $user, array $filters, int $perPage): array
    {
        $paginator = $this->financeLedgerQueryService->paginatorForUser($user, $filters, $perPage);

        return [
            'list' => ClientLedgerSummaryResource::collection($paginator->items())->resolve(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
            'summary' => $this->financeLedgerQueryService->summaryForClient($user, $filters),
        ];
    }
}
