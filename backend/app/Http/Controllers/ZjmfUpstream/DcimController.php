<?php

declare(strict_types=1);

namespace App\Http\Controllers\ZjmfUpstream;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ZjmfUpstream\DcimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 上游 dcim 控制接口（被魔方财务对接）。
 * 协议约束：固定 HTTP 200，业务状态放 body.status。
 */
class DcimController extends Controller
{
    public function __construct(
        private readonly DcimService $dcim,
    ) {}

    public function on(Request $request): JsonResponse
    {
        return response()->json($this->dcim->on($this->user($request), $this->id($request)), 200);
    }

    public function off(Request $request): JsonResponse
    {
        return response()->json($this->dcim->off($this->user($request), $this->id($request)), 200);
    }

    public function reboot(Request $request): JsonResponse
    {
        return response()->json($this->dcim->reboot($this->user($request), $this->id($request)), 200);
    }

    public function novnc(Request $request): JsonResponse
    {
        return response()->json($this->dcim->novnc($this->user($request), $this->id($request)), 200);
    }

    public function rescue(Request $request): JsonResponse
    {
        return response()->json($this->dcim->rescue(
            $this->user($request),
            $this->id($request),
            (string) $request->input('system', '1'),
        ), 200);
    }

    public function crackPass(Request $request): JsonResponse
    {
        return response()->json($this->dcim->crackPass(
            $this->user($request),
            $this->id($request),
            (string) $request->input('password', ''),
        ), 200);
    }

    public function reinstall(Request $request): JsonResponse
    {
        return response()->json($this->dcim->reinstall(
            $this->user($request),
            $this->id($request),
            (string) $request->input('os', ''),
        ), 200);
    }

    public function cancelTask(Request $request): JsonResponse
    {
        return response()->json($this->dcim->cancelTask($this->user($request), $this->id($request)), 200);
    }

    public function reinstallStatus(Request $request): JsonResponse
    {
        return response()->json($this->dcim->reinstallStatus($this->user($request), $this->id($request)), 200);
    }

    public function detail(Request $request): JsonResponse
    {
        return response()->json($this->dcim->detail($this->user($request), $this->id($request)), 200);
    }

    public function refreshPowerStatus(Request $request): JsonResponse
    {
        return response()->json($this->dcim->refreshPowerStatus($this->user($request), $this->id($request)), 200);
    }

    public function refreshAllPowerStatus(Request $request): JsonResponse
    {
        $ids = $request->input('id', []);
        if (! is_array($ids)) {
            $ids = [$ids];
        }

        return response()->json($this->dcim->refreshAllPowerStatus($this->user($request), array_map('intval', $ids)), 200);
    }

    public function hideResult(Request $request): JsonResponse
    {
        return response()->json($this->dcim->hideResult($this->user($request), $this->id($request)), 200);
    }

    public function checkReinstall(Request $request): JsonResponse
    {
        return response()->json($this->dcim->checkReinstall($this->user($request), $this->id($request)), 200);
    }

    public function traffic(Request $request): JsonResponse
    {
        return response()->json($this->dcim->traffic(), 200);
    }

    public function trafficUsage(Request $request): JsonResponse
    {
        return response()->json($this->dcim->trafficUsage(), 200);
    }

    public function kvm(Request $request): JsonResponse
    {
        return response()->json($this->dcim->kvm(), 200);
    }

    public function ikvm(Request $request): JsonResponse
    {
        return response()->json($this->dcim->ikvm(), 200);
    }

    public function bmc(Request $request): JsonResponse
    {
        return response()->json($this->dcim->bmc(), 200);
    }

    public function buyReinstallTimes(Request $request): JsonResponse
    {
        return response()->json($this->dcim->buyReinstallTimes(), 200);
    }

    public function buyFlowPacket(Request $request): JsonResponse
    {
        return response()->json($this->dcim->buyFlowPacket(), 200);
    }

    private function id(Request $request): int
    {
        return (int) ($request->input('id') ?? $request->input('host_id', 0));
    }

    private function user(Request $request): User
    {
        $user = $request->attributes->get('zjmf_upstream_user');

        return $user instanceof User ? $user : $request->user();
    }
}
