<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Services;

use Illuminate\Support\Facades\DB;
use TuraIDC\Plugins\Addons\AiAssistant\Models\AiConversation;
use TuraIDC\Plugins\Addons\AiAssistant\Models\AiMessage;
use TuraIDC\Plugins\Addons\AiAssistant\Models\AiToolInvocation;

/**
 * 会话保留期清理的唯一实现。
 *
 * 两步：超过保留期的活跃会话先软删；软删满 7 天的会话连消息与工具调用一并物理删除。
 * 只删 AI 自己的四张表，绝不触碰支付、账单等审计边界。
 *
 * 两个入口共用这一份逻辑：
 * - 心跳定时任务 `Automation\AiPruneConversationsTask`（插件清单 `extra.scheduled_tasks` 声明，生产实际执行者）；
 * - 运维命令 `ai:prune-conversations`（`--dry-run` 只统计，便于上线前核对）。
 */
final class AiConversationPruner
{
    /** 软删之后保留多久再物理删除（天） */
    public const PURGE_AFTER_SOFT_DELETE_DAYS = 7;

    public function __construct(private readonly AiConfig $config) {}

    /**
     * @return array{retention_days: int, archived: int, purged: int, dry_run: bool}
     */
    public function prune(bool $dryRun = false): array
    {
        $retentionDays = $this->config->retentionDays();
        $softCutoff = now()->subDays($retentionDays);
        $purgeCutoff = now()->subDays(self::PURGE_AFTER_SOFT_DELETE_DAYS);

        $stale = $this->staleQuery($softCutoff)->count();
        $purgeable = AiConversation::query()
            ->where('status', AiConversation::STATUS_DELETED)
            ->where('updated_at', '<', $purgeCutoff)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($dryRun) {
            return [
                'retention_days' => $retentionDays,
                'archived' => $stale,
                'purged' => count($purgeable),
                'dry_run' => true,
            ];
        }

        $archived = $this->staleQuery($softCutoff)->update([
            'status' => AiConversation::STATUS_DELETED,
            'updated_at' => now(),
        ]);

        foreach (array_chunk($purgeable, 200) as $ids) {
            DB::transaction(static function () use ($ids): void {
                // 先删子表再删会话：外键是 CASCADE，但显式删除让每张表的删除条数
                // 可审计，也让「只删 AI 四张表」这条边界在代码里看得见。
                AiToolInvocation::query()->whereIn('conversation_id', $ids)->delete();
                AiMessage::query()->whereIn('conversation_id', $ids)->delete();
                AiConversation::query()->whereIn('id', $ids)->delete();
            });
        }

        return [
            'retention_days' => $retentionDays,
            'archived' => (int) $archived,
            'purged' => count($purgeable),
            'dry_run' => false,
        ];
    }

    /**
     * 软删的目标集合。
     *
     * `last_message_at` 为空的历史行按创建时间兜底：这些行从来没被写过时间戳，
     * 直接比较 `last_message_at < ?` 会把它们永远排除在清理之外。
     *
     * @return \Illuminate\Database\Eloquent\Builder<AiConversation>
     */
    private function staleQuery(\Illuminate\Support\Carbon $softCutoff): \Illuminate\Database\Eloquent\Builder
    {
        return AiConversation::query()
            ->where('status', AiConversation::STATUS_ACTIVE)
            ->where(static function ($query) use ($softCutoff): void {
                $query->where('last_message_at', '<', $softCutoff)
                    ->orWhere(static function ($inner) use ($softCutoff): void {
                        $inner->whereNull('last_message_at')->where('created_at', '<', $softCutoff);
                    });
            });
    }
}
