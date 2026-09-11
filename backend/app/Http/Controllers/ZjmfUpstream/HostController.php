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

    /**
     * 自定义 tab 内容：下游（魔方财务 / TuraIDC 自身）按 host/header 下发的
     * module_client_area.key 取面板 HTML。
     */
    public function customContent(Request $request): JsonResponse
    {
        $result = $this->host->customAreaContent(
            $this->user($request),
            (int) $request->input('id', 0),
            (string) $request->input('key', ''),
            (string) $request->input('api_url', ''),
        );

        return response()->json($result, 200);
    }

    private function user(Request $request): User
    {
        $user = $request->attributes->get('zjmf_upstream_user');

        return $user instanceof User ? $user : $request->user();
    }
}
