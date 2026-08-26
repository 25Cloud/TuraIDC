<?php

declare(strict_types=1);

namespace App\Http\Controllers\ZjmfUpstream;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ZjmfUpstream\HostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 上游主机接口（被魔方财务对接）。
 * 协议约束：固定 HTTP 200，业务状态放 body.status。
 */
class HostController extends Controller
{
    public function __construct(
        private readonly HostService $host,
    ) {}

    public function header(Request $request): JsonResponse
    {
        $result = $this->host->header(
            $this->user($request),
            (int) $request->input('host_id', 0),
        );

        return response()->json($result, 200);
    }

    public function renew(Request $request): JsonResponse
    {
        $result = $this->host->renew(
            $this->user($request),
            (int) $request->input('hostid', 0),
            (string) $request->input('billingcycles', ''),
        );

        return response()->json($result, 200);
    }

    public function cancel(Request $request): JsonResponse
    {
        $result = $this->host->cancel(
            $this->user($request),
            (int) $request->input('id', 0),
            $request->all(),
        );

        return response()->json($result, 200);
    }

    private function user(Request $request): User
    {
        $user = $request->attributes->get('zjmf_upstream_user');

        return $user instanceof User ? $user : $request->user();
    }
}
