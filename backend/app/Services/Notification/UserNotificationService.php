<?php

namespace App\Services\Notification;

use App\Models\User;
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
     * 请求内营销偏好缓存（user_id => 是否接受）。
     *
     * 批量场景（账单提醒、生命周期提醒逐用户写入）里同一个用户会被多次询问，
     * 此前每次都要一条 `users` 查询。缓存只活在本实例的生命周期内，
     * 用户改了偏好后新请求会读到新值，不存在跨请求的陈旧问题。
     *
     * @var array<int, bool>
     */
    private array $marketingPreferences = [];

    /**
     * 创建一条站内信。
     *
     * @param  array<string,mixed>  $data  附加业务数据
     * @param  bool  $marketing  是否营销/推广类通知：受用户 marketing_alert 偏好控制，关闭则不写入
     */
    public function create(
        int $userId,
        string $type,
        string $title,
        ?string $content = null,
        ?string $link = null,
        array $data = [],
        bool $marketing = false
    ): ?UserNotification {
        if ($userId <= 0 || ! $this->tableReady()) {
            return null;
        }

        // 通知偏好生效：营销类站内信按用户偏好过滤，业务必要提醒不受影响。
        if ($marketing && ! $this->userAcceptsMarketing($userId)) {
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

    /**
     * 用户是否接受营销类通知（marketing_alert 默认关闭）。
     *
     * 结果按 user_id 缓存在实例内：群发循环里同一用户被反复询问时只查一次库。
     */
    private function userAcceptsMarketing(int $userId): bool
    {
        if (array_key_exists($userId, $this->marketingPreferences)) {
            return $this->marketingPreferences[$userId];
        }

        // value() 只取单列，避免整行 hydration；用户不存在时返回 null → 视为不接受
        $accepted = (bool) User::query()->whereKey($userId)->value('marketing_alert');

        return $this->marketingPreferences[$userId] = $accepted;
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
