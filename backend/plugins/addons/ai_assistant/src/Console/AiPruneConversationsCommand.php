<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Console;

use Illuminate\Console\Command;
use TuraIDC\Plugins\Addons\AiAssistant\Services\AiConversationPruner;

/**
 * 会话保留期清理的运维入口。
 *
 * 生产实际由心跳定时任务 `Automation\AiPruneConversationsTask` 执行（清单里声明），
 * 本命令留给运维核对：`--dry-run` 只统计不删除，上线或调整保留期前后用它看数量。
 * 清理逻辑只有一份，在 AiConversationPruner。
 */
final class AiPruneConversationsCommand extends Command
{
    protected $signature = 'ai:prune-conversations {--dry-run : 只统计不删除}';

    protected $description = '按保留期清理 AI 会话与工具调用记录';

    public function handle(AiConversationPruner $pruner): int
    {
        $result = $pruner->prune((bool) $this->option('dry-run'));

        $this->line(sprintf(
            '保留期 %d 天：待归档 %d 个会话，待物理清理 %d 个会话%s',
            $result['retention_days'],
            $result['archived'],
            $result['purged'],
            $result['dry_run'] ? '（dry-run，未删除）' : '',
        ));

        if (! $result['dry_run']) {
            $this->info('清理完成');
        }

        return self::SUCCESS;
    }
}
