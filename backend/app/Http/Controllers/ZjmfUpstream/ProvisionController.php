<?php

declare(strict_types=1);

namespace App\Http\Controllers\ZjmfUpstream;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ZjmfUpstream\DcimService;
use App\Services\ZjmfUpstream\UpstreamProvisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 上游模块命令接口（被魔方财务对接）：/provision/default、/provision/button。
 */
class ProvisionController extends Controller
{
    public function __construct(
        private readonly UpstreamProvisionService $provision,
        private readonly DcimService $dcim,
    ) {}

    public function execute(Request $request): JsonResponse
    {
        $user = $request->attributes->get('zjmf_upstream_user');
        $user = $user instanceof User ? $user : $request->user();

        $result = $this->provision->execute($user, $request->all());

        return response()->json($result, 200);
    }

    /**
     * provision/button：魔方财务 execClientButton 转发的无参控制按钮（id + func）。
     *
     * func 对齐魔方财务云产品按钮集合；救援/重装/抓鸡等需要参数的操作由魔方财务
     * 走 /dcim/rescue、/dcim/reinstall、/dcim/crack_pass 传参调用，这里返回 400 提示。
     */
    public function button(Request $request): JsonResponse
    {
        $user = $request->attributes->get('zjmf_upstream_user');
        $user = $user instanceof User ? $user : $request->user();
        $serviceId = (int) $request->input('id', 0);
        $func = (string) $request->input('func', '');

        $result = match ($func) {
            'on' => $this->dcim->on($user, $serviceId),
            'off' => $this->dcim->off($user, $serviceId),
            'reboot' => $this->dcim->reboot($user, $serviceId),
            'hard_off' => $this->dcim->hardOff($user, $serviceId),
            'hard_reboot' => $this->dcim->hardReboot($user, $serviceId),
            'novnc' => $this->dcim->novnc($user, $serviceId),
            'cancel_task' => $this->dcim->cancelTask($user, $serviceId),
            default => ['status' => 400, 'msg' => '不支持的操作，请通过对应接口调用'],
        };

        return response()->json($result, 200);
    }
}
