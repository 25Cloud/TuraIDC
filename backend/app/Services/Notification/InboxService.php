<?php

namespace App\Services\Notification;

use App\Constants\UserNotificationType;
use App\Models\ContentArticle;
use App\Models\NoticeRead;
use App\Models\UserNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 站内信聚合服务。
 *
 * 把两类来源合并成统一的「消息流」：
 *  1. 公告（content_articles + notice_reads，时间戳比较法判定已读）
 *  2. 个性化通知（user_notifications，订购/续费/到期等）
 *
 * 聚合仅用于「未读数」与「下拉/列表展示」，不落地中间表。
 */
class InboxService
{
    public function __construct(
        private UserNotificationService $userNotificationService,
    ) {}

    /**
     * 总未读数 = 公告未读 + 个性化未读。
     */
    public function unreadCount(int $userId): int
    {
        return $this->noticeUnreadCount($userId) + $this->userNotificationService->unreadCount($userId);
    }

    /**
     * 下拉用：取最新 N 条合并消息（含已读，按时间倒序）。
     *
     * @return array<int,array<string,mixed>>
     */
    public function feed(int $userId, int $limit = 10): array
    {
        $notices = $this->mapNotices($this->visibleNoticeItems($userId));
        $personal = $this->mapPersonal(
            UserNotification::query()
                ->where('user_id', $userId)
                ->where(fn ($q) => $q->whereNull('read_at')->orWhere('read_at', '>=', now()->subDays(7)))
                ->orderByDesc('created_at')
                ->limit($limit)
                ->get()
        );

        return $notices->merge($personal)
            ->sortByDesc('sort_at')
            ->take($limit)
            ->map(fn (array $item) => $this->stripSortKey($item))
            ->values()
            ->all();
    }

    /**
     * 完整列表：可选只看未读，分页返回。
     *
     * @return array{list: array<int,array<string,mixed>>, total: int}
     */
    public function list(int $userId, bool $unreadOnly = false, int $page = 1, int $pageSize = 15): array
    {
        $notices = $this->mapNotices($this->visibleNoticeItems($userId));

        $personalQuery = UserNotification::query()->where('user_id', $userId);
        if ($unreadOnly) {
            $personalQuery->whereNull('read_at');
        } else {
            $personalQuery->where(fn ($q) => $q->whereNull('read_at')->orWhere('read_at', '>=', now()->subDays(7)));
        }
        $personal = $this->mapPersonal($personalQuery->orderByDesc('created_at')->get());

        $merged = $notices->merge($personal);
        if ($unreadOnly) {
            $merged = $merged->where('read', false);
        }

        $sorted = $merged->sortByDesc('sort_at')->values();
        $total = $sorted->count();
        $offset = max(0, ($page - 1) * $pageSize);

        $items = $sorted
            ->slice($offset, $pageSize)
            ->map(fn (array $item) => $this->stripSortKey($item))
            ->values()
            ->all();

        return [
            'list' => $items,
            'total' => $total,
        ];
    }

    /**
     * 全部标记已读（公告 + 个性化）。
     */
    public function markAllRead(int $userId): void
    {
        $this->markAllNoticesRead($userId);
        $this->userNotificationService->markAllRead($userId);
    }

    // ─── 公告部分 ───────────────────────────────────────────────────────────

    private function noticeUnreadCount(int $userId): int
    {
        return $this->noticeItems($userId)->where('read', false)->count();
    }

    /**
     * 取已发布公告并附带当前用户的已读状态。
     *
     * @return Collection<int,array<string,mixed>>
     */
    private function noticeItems(int $userId): Collection
    {
        $notices = ContentArticle::query()
            ->ofType(ContentArticle::TYPE_NOTICE)
            ->published()
            ->select(['id', 'title', 'summary', 'require_reread_at', 'last_published_at', 'publish_at', 'created_at'])
            ->orderByDesc('last_published_at')
            ->get();

        if ($notices->isEmpty()) {
            return collect();
        }

        $reads = NoticeRead::query()
            ->where('user_id', $userId)
            ->whereIn('article_id', $notices->pluck('id'))
            ->pluck('read_at', 'article_id');

        return $notices->map(function (ContentArticle $notice) use ($reads) {
            $readAt = $reads->get($notice->id);
            $isRead = $readAt !== null
                && ! ($notice->require_reread_at && $readAt < $notice->require_reread_at);

            $sortAt = $notice->last_published_at
                ?? $notice->publish_at
                ?? $notice->created_at;

            return [
                'article' => $notice,
                'read' => $isRead,
                'read_at' => $readAt,
                'sort_at' => $sortAt instanceof Carbon ? $sortAt : ($sortAt ? Carbon::parse($sortAt) : now()),
            ];
        });
    }

    /**
     * 可见公告：仅未读的显示；已读即隐藏。
     */
    private function visibleNoticeItems(int $userId): Collection
    {
        return $this->noticeItems($userId)->where('read', false);
    }

    /**
     * @param  Collection<int,array<string,mixed>>  $items
     * @return Collection<int,array<string,mixed>>
     */
    private function mapNotices(Collection $items): Collection
    {
        return $items->map(function (array $item) {
            /** @var ContentArticle $article */
            $article = $item['article'];

            return [
                'id' => 'notice-'.$article->id,
                'source' => 'notice',
                'type' => 'notice',
                'type_label' => '系统公告',
                'title' => (string) $article->title,
                'summary' => (string) ($article->summary ?? ''),
                'link' => '/client/notices/'.$article->id,
                'read' => (bool) $item['read'],
                'created_at' => optional($item['sort_at'])->toDateTimeString(),
                'sort_at' => $item['sort_at'],
            ];
        });
    }

    /**
     * @param  Collection<int,UserNotification>  $notifications
     * @return Collection<int,array<string,mixed>>
     */
    private function mapPersonal(Collection $notifications): Collection
    {
        return $notifications->map(function (UserNotification $item) {
            return [
                'id' => 'msg-'.$item->id,
                'raw_id' => $item->id,
                'source' => 'message',
                'type' => $item->type,
                'type_label' => UserNotificationType::label($item->type),
                'title' => (string) $item->title,
                'summary' => (string) ($item->content ?? ''),
                'link' => $item->link,
                'read' => $item->read_at !== null,
                'created_at' => optional($item->created_at)->toDateTimeString(),
                'sort_at' => $item->created_at ?? now(),
            ];
        });
    }

    private function stripSortKey(array $item): array
    {
        unset($item['sort_at']);

        return $item;
    }

    private function markAllNoticesRead(int $userId): void
    {
        $ids = ContentArticle::query()
            ->ofType(ContentArticle::TYPE_NOTICE)
            ->published()
            ->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        $now = now();
        $records = $ids->map(fn ($id) => [
            'user_id' => $userId,
            'article_id' => $id,
            'read_at' => $now,
            'created_at' => $now,
        ])->all();

        DB::table('notice_reads')->upsert(
            $records,
            ['user_id', 'article_id'],
            ['read_at']
        );
    }
}
