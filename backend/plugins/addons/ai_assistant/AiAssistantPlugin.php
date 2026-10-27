<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant;

use TuraIDC\Plugins\Addons\AiAssistant\Logic\AiAssistantStatus;

/**
 * AI 助手插件入口。
 *
 * 平台按 addon 约定通过 PluginRuntimeRegistry 调用本类的 execute()，
 * 只用于“状态自检 / 工具清单”这类无副作用动作；真正的对话、会话与治理接口
 * 由本插件 Provider 注册的 /api/v2/{client,admin}/ai/* 路由承载，
 * 路径与中间件与拆分前进核心时完全一致，前端不需要任何改动。
 */
final class AiAssistantPlugin
{
    public function __construct(
        private readonly AiAssistantStatus $status,
    ) {}

    /**
     * @param  array<string, mixed>  $request
     * @return array{success: bool, action: string, message: string, data: array<string, mixed>}
     */
    public function execute(array $request): array
    {
        $action = trim((string) ($request['action'] ?? 'ai.status'));
        $payload = is_array($request['payload'] ?? null) ? $request['payload'] : [];

        return $this->status->handle($action, $payload);
    }
}
