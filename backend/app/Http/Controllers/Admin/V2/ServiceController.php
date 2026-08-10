<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Service\BatchUpdateServiceCustomHostnameRequest;
use App\Http\Requests\Admin\V2\Service\ListServicesRequest;
use App\Services\Admin\V2\AdminServiceV2QueryService;

class ServiceController extends Controller
{
    public function __construct(
        private readonly AdminServiceV2QueryService $queryService,
    ) {}

    public function index(ListServicesRequest $request)
    {
        return $this->success($this->queryService->list($request->filters()));
    }

    public function batchUpdateCustomHostnames(BatchUpdateServiceCustomHostnameRequest $request)
    {
        return $this->success(
            $this->queryService->batchUpdateCustomHostnames($request->payload(), [
                'operator_id' => (int) ($request->user()?->id ?? 0),
                'operator_name' => (string) ($request->user()?->username ?? $request->user()?->name ?? $request->user()?->email ?? 'admin'),
                'trace_id' => (string) $request->header('X-Request-Id', ''),
                'ip_address' => (string) $request->ip(),
            ]),
            '自定义主机名已更新'
        );
    }
}
