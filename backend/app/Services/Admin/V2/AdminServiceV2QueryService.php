<?php

declare(strict_types=1);

namespace App\Services\Admin\V2;

use App\Http\Resources\Admin\V2\AdminServiceHostnameBatchResultResource;
use App\Http\Resources\Admin\V2\AdminServiceListItemResource;
use App\Services\Provisioning\AdminServiceHostnameService;
use App\Services\Provisioning\AdminServiceListService;

class AdminServiceV2QueryService
{
    public function __construct(
        private readonly AdminServiceListService $listService,
        private readonly AdminServiceHostnameService $hostnameService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function list(array $filters): array
    {
        $result = $this->listService->paginate($filters);

        return [
            'list' => AdminServiceListItemResource::collection((array) ($result['list'] ?? []))->resolve(),
            'total' => (int) ($result['total'] ?? 0),
            'page' => (int) ($result['page'] ?? 1),
            'page_size' => (int) ($result['page_size'] ?? 20),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function batchUpdateCustomHostnames(array $payload, array $context): array
    {
        return AdminServiceHostnameBatchResultResource::make(
            $this->hostnameService->batchUpdateCustomHostnames($payload, $context)
        )->resolve();
    }
}
