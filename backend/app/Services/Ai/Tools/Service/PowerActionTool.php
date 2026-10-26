<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools\Service;

use App\Services\Ai\Tools\AiTool;
use App\Services\Ai\Tools\AiToolContext;
use App\Services\Ai\Tools\Concerns\FormatsToolResults;
use App\Services\ClientServiceConsole\ClientServiceConsoleService;

/**
 * 电源操作：开机 / 关机 / 重启 / 强制关机 / 强制重启。
 *
 * 实例动作是同步打上游的（没有队列），回包里 status 字段恒为 queued，
 * 工具结果必须显式说明「已同步执行」，否则模型会告诉用户「正在排队」。
 */
final class PowerActionTool extends AiTool
{
    use FormatsToolResults;

    private const LABELS = [
        'on' => '开机',
        'off' => '关机',
        'reboot' => '重启',
        'hard_off' => '强制关机',
        'hard_reboot' => '强制重启',
    ];

    public function __construct(private readonly ClientServiceConsoleService $console) {}

    public function name(): string
    {
        return 'power_action';
    }

    public function description(): string
    {
        return '对某台实例执行电源操作：开机、关机、重启、强制关机、强制重启。属于会中断服务的操作，必须由用户确认后执行。';
    }

    public function parameters(): array
    {
        return $this->objectSchema([
            'service_id' => ['type' => 'integer', 'minimum' => 1, 'description' => '实例编号'],
            'action' => [
                'type' => 'string',
                'enum' => ['on', 'off', 'reboot', 'hard_off', 'hard_reboot'],
                'description' => 'on 开机 / off 关机 / reboot 重启 / hard_off 强制关机 / hard_reboot 强制重启',
            ],
        ], ['service_id', 'action']);
    }

    public function risk(): string
    {
        return self::RISK_WRITE;
    }

    public function scopes(): array
    {
        return ['services'];
    }

    public function title(array $arguments): string
    {
        return '实例' . (self::LABELS[(string) ($arguments['action'] ?? '')] ?? '电源操作') . '（实例 #' . (string) ($arguments['service_id'] ?? '') . '）';
    }

    public function impact(array $arguments): string
    {
        $action = (string) ($arguments['action'] ?? '');
        if (in_array($action, ['hard_off', 'hard_reboot'], true)) {
            return '强制断电可能导致未保存数据丢失，且业务会立即中断。';
        }
        if (in_array($action, ['off', 'reboot'], true)) {
            return '实例业务会中断，关机后需重新开机。';
        }

        return '开机后会正常计费并恢复服务。';
    }

    public function run(AiToolContext $context, array $arguments): array
    {
        $action = (string) $arguments['action'];
        $result = $this->console->powerActionForUser(
            $context->user,
            (int) $arguments['service_id'],
            $action,
            $context->actionContext('.power.' . $action),
        );

        return [
            'summary' => '已同步执行「' . (self::LABELS[$action] ?? $action) . '」，实例状态以控制台为准（本动作非排队，已执行完成）',
            'data' => [
                'service_id' => (int) $arguments['service_id'],
                'action' => $action,
                'executed_synchronously' => true,
                'result_excerpt' => $this->excerpt((string) json_encode($result, JSON_UNESCAPED_UNICODE), 600),
            ],
        ];
    }
}
