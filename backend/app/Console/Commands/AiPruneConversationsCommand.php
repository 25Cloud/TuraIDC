<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiToolInvocation;
use App\Services\Ai\AiConfig;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 会话保留期清理。
 *
 * 两步：超过保留期的会话先软删；软删满 7 天的会话连消息与工具调用一并物理删除。
 * 只删 AI 自己的四张表，绝不触碰支付、账单等审计边界。
 */
final class AiPruneConversationsCommand extends Command
{
    protected $signature = 'ai:prune-conversations {--dry-run : 只统计不删除}';

    protected $description = '按保留期清理 AI 会话与工具调用记录';

    public function handle(AiConfig $config): int
    {
        $retentionDays = $config->retentionDays();
        $softCutoff = now()->subDays($retentionDays);
        $purgeCutoff = now()->subDays(7);
        $dryRun = (bool) $this->option('dry-run');

        $stale = AiConversation::query()
            ->where('status', AiConversation::STATUS_ACTIVE)
            ->where('last_message_at', '<', $softCutoff)
            ->count();

        $purgeable = AiConversation::query()
            ->where('status', AiConversation::STATUS_DELETED)
            ->where('updated_at', '<', $purgeCutoff)
            ->pluck('id')
            ->all();

        $this->line(sprintf(
            '保留期 %d 天：待归档 %d 个会话，待物理清理 %d 个会话',
            $retentionDays,
            $stale,
            count($purgeable),
        ));

        if ($dryRun) {
            return self::SUCCESS;
        }

        AiConversation::query()
            ->where('status', AiConversation::STATUS_ACTIVE)
            ->where('last_message_at', '<', $softCutoff)
            ->update(['status' => AiConversation::STATUS_DELETED, 'updated_at' => now()]);

        foreach (array_chunk($purgeable, 200) as $ids) {
            DB::transaction(function () use ($ids): void {
                AiToolInvocation::query()->whereIn('conversation_id', $ids)->delete();
                AiMessage::query()->whereIn('conversation_id', $ids)->delete();
                AiConversation::query()->whereIn('id', $ids)->delete();
            });
        }

        $this->info('清理完成');

        return self::SUCCESS;
    }
}
