<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Client\V2\Service\ShowServiceConnectionRequest;
use App\Http\Requests\Client\V2\Service\ShowServiceRequest;
use App\Http\Requests\Client\V2\Service\ShowServiceRuntimeRequest;
use App\Http\Resources\Service\V2\ServiceConnectionResource;
use App\Http\Resources\Service\V2\ServiceDetailResource;
use App\Http\Resources\Service\V2\ServiceRuntimeResource;
use App\Services\ClientServiceConsole\ClientServiceConsoleService;
use Illuminate\Http\JsonResponse;

class ServiceController extends Controller
{
    public function __construct(
        private readonly ClientServiceConsoleService $services,
    ) {}

    public function show(ShowServiceRequest $request, int $service): JsonResponse
    {
        $detail = $this->services->getDetailForUser(
            $request->user(),
            $service,
            $request->boolean('refresh'),
            true
        );

        return $this->success([
            'service' => (new ServiceDetailResource($detail))->resolve(),
        ]);
    }

    public function connection(ShowServiceConnectionRequest $request, int $service): JsonResponse
    {
        $detail = $this->services->getBaseDetailForUser($request->user(), $service, true);

        return $this->success([
            'connection' => (new ServiceConnectionResource((array) ($detail['connection'] ?? []), true))->resolve(),
        ]);
    }

    public function runtime(ShowServiceRuntimeRequest $request, int $service): JsonResponse
    {
        $detail = $this->services->getRemoteStatusPatchForUser($request->user(), $service, true);

        return $this->success([
            'runtime' => (new ServiceRuntimeResource($detail))->resolve(),
        ]);
    }
}
