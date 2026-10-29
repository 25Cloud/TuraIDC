<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Automation;

use App\Services\Automation\Heartbeat\Contracts\ScheduledTask;
use App\Services\Automation\Heartbeat\Data\TaskContext;
use App\Services\Automation\Heartbeat\ScheduleRule;
use App\Services\Automation\Heartbeat\TriggerRule;
use TuraIDC\Plugins\Addons\AiAssistant\Services\AiConversationPruner;

/**
 * AI 会话保留期清理（心跳定时任务）。
 *
 * 为什么放在插件里而不是核心 `routes/console.php`：插件规范第 7 节要求插件的周期能力
 * 只能通过清单 `info.extra.scheduled_tasks` 接入平台调度。核心去 schedule 一个插件命令
 * 是同一条边界上的变通——插件停用/卸载后那条 Schedule 还在，每天必然「命令不存在」，
 * 而且核心得额外知道「这个功能有没有装」。改成清单声明后，只有已启用的插件会被
 * `PluginScheduledTaskProvider` 扫到，插件不在，任务自然消失，核心一行都不用改。
 *
 * 触发用 cron `30 4 * * *`：心跳按 15 分钟槽位派发，分钟只能是 00/15/30/45，
 * 所以原先核心里那个 `dailyAt('04:40')` 无法直接照搬，取最接近的 04:30。
 */
final class AiPruneConversationsTask implements ScheduledTask
{
    public function __construct(private readonly AiConversationPruner $pruner) {}

    public function key(): string
    {
        return 'ai-prune-conversations';
    }

    public function title(): string
    {
        return 'AI 会话清理';
    }

    public function description(): string
    {
        return '按保留期软删过期 AI 会话，并物理清理软删满 7 天的会话、消息与工具调用记录。';
    }

    public function category(): string
    {
        return '插件任务';
    }

    /**
     * @return list<TriggerRule>
     */
    public function triggers(): array
    {
        return [ScheduleRule::cron('30 4 * * *')];
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(TaskContext $context): array
    {
        $result = $this->pruner->prune(false);

        return [
            'archived' => $result['archived'],
            'purged' => $result['purged'],
            'retention_days' => $result['retention_days'],
            'source' => $context->source,
        ];
    }

    public function queue(): string
    {
        return (string) config('queue.TURAIDC_SCHEDULE_QUEUE', 'automation');
    }

    public function timeout(): int
    {
        return 300;
    }

    public function lockTtlSeconds(): int
    {
        // 必须至少比 timeout 多 60 秒，否则锁会先过期、下一槽位再起一个实例
        return 600;
    }

    public function manualTriggerable(): bool
    {
        return true;
    }
}
