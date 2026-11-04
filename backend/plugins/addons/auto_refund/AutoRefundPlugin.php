<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund;

use TuraIDC\Plugins\Addons\AutoRefund\Logic\AutoRefundStatus;

/**
 * 自助退款插件入口。
 *
 * 平台按 addon 约定通过 PluginRuntimeRegistry 调 `execute()`，只用于
 * **无副作用的状态自检**（是否启用、配置是否就绪、上游地址是否已配置）。
 *
 * 真正的业务动作全部走本插件 Provider 注册的
 * `/api/v2/{client,admin}/auto-refund/*` 接口：
 * 那里有 `execute()` 拿不到的登录态、权限与请求上下文。
 * 尤其注意：`execute()` 里**绝不发上游请求**——它会在插件扫描、启用、
 * 后台列表等时机被调用，一次网络请求就足以把整个后台拖慢。
 */
final class AutoRefundPlugin
{
    public function __construct(
        private readonly AutoRefundStatus $status,
    ) {}

    /**
     * @param  array<string, mixed>  $request
     * @return array{success: bool, action: string, message: string, data: array<string, mixed>}
     */
    public function execute(array $request): array
    {
        $action = trim((string) ($request['action'] ?? 'auto_refund.status'));

        return $this->status->handle($action === '' ? 'auto_refund.status' : $action);
    }
}
