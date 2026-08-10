<?php

namespace App\Services\Notification;

use App\Models\UserNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 站内信（个性化通知）写入服务。
 *
 * 仅负责往 user_notifications 表写记录；公告类未读由 NoticeReadService 统计。
 * 所有写入都做防御性处理：表不存在或写入异常都不应阻断业务主流程。
 */
class UserNotificationService
{
    private ?bool $tableReady = null;

    /**
     * 创建一条站内信。
     *
     * @param  array<string,mixed>  $data  附加业务数据
     */
    public function create(
        int $userId,
        string $type,
        string $title,
        ?string $content = null,
        ?string $link = null,
        array $data = []
    ): ?UserNotification {
        if ($userId <= 0 || ! $this->tableReady()) {
            return null;
        }

        try {
            return UserNotification::create([
                'user_id' => $userId,
                'type' => $type,
                'title' => mb_substr(trim($title), 0, 191),
                'content' => $content !== null ? trim($content) : null,
                'link' => $link,
                'data' => $data === [] ? null : $data,
            ]);
        } catch (\Throwable $exception) {
            Log::warning('[站内信] 写入失败，已忽略以避免阻断业务', [
                'user_id' => $userId,
                'type' => $type,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    public function unreadCount(int $userId): int
    {
        if ($userId <= 0 || ! $this->tableReady()) {
            return 0;
        }

        return (int) UserNotification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->count();
    }

    public function markRead(int $userId, int $id): void
    {
        if (! $this->tableReady()) {
            return;
        }

        UserNotification::query()
            ->where('user_id', $userId)
            ->whereKey($id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function markAllRead(int $userId): void
    {
        if (! $this->tableReady()) {
            return;
        }

        UserNotification::query()
            ->where('user_id', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    private function tableReady(): bool
    {
        if ($this->tableReady === null) {
            try {
                $this->tableReady = Schema::hasTable('user_notifications');
            } catch (\Throwable) {
                $this->tableReady = false;
            }
        }

        return $this->tableReady;
    }
}
