<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Logic;

use TuraIDC\Plugins\Addons\AiAssistant\Models\AiConversation;
use TuraIDC\Plugins\Addons\AiAssistant\Services\AiConfig;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\AiToolRegistry;

/**
 * 插件级自检：给插件管理页的“试运行”和运维排查用。
 *
 * 只读：不写 settings、不改表、不发上游请求。返回的都是站长能直接看懂的中文摘要，
 * 不返回令牌、密钥或第三方原始响应（与插件标准 v2 的返回值边界一致）。
 */
final class AiAssistantStatus
{
    public function __construct(
        private readonly AiConfig $config,
        private readonly AiToolRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, action: string, message: string, data: array<string, mixed>}
     */
    public function handle(string $action, array $payload): array
    {
        return match ($action) {
            'ai.status', 'status' => $this->status(),
            'ai.tools', 'tools' => $this->tools($payload),
            default => [
                'success' => false,
                'action' => $action,
                'message' => "未知的插件动作：{$action}",
                'data' => [],
            ],
        };
    }

    /**
     * @return array{success: bool, action: string, message: string, data: array<string, mixed>}
     */
    private function status(): array
    {
        $configured = $this->config->isConfigured();

        return [
            'success' => true,
            'action' => 'ai.status',
            'message' => $configured ? 'AI 网关已配置' : '尚未填写网关地址与模型名称',
            'data' => [
                'configured' => $configured,
                'enabled' => $this->config->enabled(),
                'protocol' => $this->config->protocol(),
                'protocol_label' => $this->config->protocolLabel(),
                'model' => $this->config->model(),
                'has_api_key' => $this->config->hasApiKey(),
                'channels' => [
                    'console' => $this->config->channelEnabled('console'),
                    'site' => $this->config->channelEnabled('site'),
                    'admin' => $this->config->channelEnabled('admin'),
                ],
                'permission_modes' => [
                    'default' => $this->config->defaultPermissionMode(),
                    'max' => $this->config->maxPermissionMode(),
                ],
                'limits' => [
                    'user_daily_messages' => $this->config->userDailyMessages(),
                    'user_daily_tokens' => $this->config->userDailyTokens(),
                    'global_daily_tokens' => $this->config->globalDailyTokens(),
                    'global_daily_cost_cents' => $this->config->globalDailyCostCents(),
                    'max_tool_rounds' => $this->config->maxToolRounds(),
                    'request_timeout_seconds' => $this->config->requestTimeoutSeconds(),
                ],
                'retention_days' => $this->config->retentionDays(),
                'tool_count' => count($this->registry->catalog(AiConversation::MODE_AUTO_APPROVE)),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, action: string, message: string, data: array<string, mixed>}
     */
    private function tools(array $payload): array
    {
        $mode = trim((string) ($payload['permission_mode'] ?? AiConversation::MODE_AUTO_APPROVE));
        if (! in_array($mode, AiConversation::PERMISSION_MODES, true)) {
            $mode = AiConversation::MODE_CONFIRM;
        }

        $tools = $this->registry->catalog($mode);

        return [
            'success' => true,
            'action' => 'ai.tools',
            'message' => "当前档位可用工具 {$mode}：" . count($tools) . ' 个',
            'data' => [
                'permission_mode' => $mode,
                'tools' => array_map(
                    static fn (array $tool): array => [
                        'name' => (string) ($tool['name'] ?? ''),
                        'risk' => (string) ($tool['risk'] ?? ''),
                        'description' => (string) ($tool['description'] ?? ''),
                    ],
                    $tools
                ),
            ],
        ];
    }
}
