<?php

namespace App\Services\Content;

use App\Models\ContentArticle;
use App\Models\NoticeRead;
use Illuminate\Support\Facades\DB;

class NoticeReadService
{
    public function unreadCount(int $userId): int
    {
        $publishedNotices = ContentArticle::query()
            ->ofType(ContentArticle::TYPE_NOTICE)
            ->published()
            ->select(['id', 'require_reread_at'])
            ->get();

        if ($publishedNotices->isEmpty()) {
            return 0;
        }

        $reads = NoticeRead::query()
            ->where('user_id', $userId)
            ->whereIn('article_id', $publishedNotices->pluck('id'))
            ->pluck('read_at', 'article_id');

        $unread = 0;
        foreach ($publishedNotices as $notice) {
            $readAt = $reads->get($notice->id);
            if (! $readAt) {
                $unread++;
            } elseif ($notice->require_reread_at && $readAt < $notice->require_reread_at) {
                $unread++;
            }
        }

        return $unread;
    }

    public function markRead(int $userId, int $articleId): void
    {
        NoticeRead::query()->updateOrCreate(
            ['user_id' => $userId, 'article_id' => $articleId],
            ['read_at' => now()]
        );
    }

    public function markAllRead(int $userId): void
    {
        $publishedNoticeIds = ContentArticle::query()
            ->ofType(ContentArticle::TYPE_NOTICE)
            ->published()
            ->pluck('id');

        if ($publishedNoticeIds->isEmpty()) {
            return;
        }

        $now = now();
        $records = $publishedNoticeIds->map(fn ($id) => [
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

    public function requireReread(ContentArticle $article): void
    {
        $article->update(['require_reread_at' => now()]);
    }
}
