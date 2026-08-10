<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Http\Resources\Client\V2\ClientNotificationResource;
use App\Http\Resources\V2\Content\PublishedContentCategoryResource;
use App\Http\Resources\V2\Content\PublishedContentDetailResource;
use App\Http\Resources\V2\Content\PublishedContentSummaryResource;
use App\Services\Notification\InboxService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ContentV2QueryService
{
    public function __construct(
        private readonly ContentArticleService $contentArticleService,
        private readonly InboxService $inboxService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function publishedList(string $type, array $filters, int $perPage): array
    {
        $paginator = $this->contentArticleService->publishedList($type, $filters, $perPage);

        return $this->pagePayload($paginator, [
            'categories' => PublishedContentCategoryResource::collection(
                $this->contentArticleService->publishedCategories($type)
            )->resolve(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function publishedDetail(string $type, int $articleId): array
    {
        return [
            'article' => PublishedContentDetailResource::make(
                $this->contentArticleService->publishedDetail($type, $articleId)
            )->resolve(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function overview(int $noticeLimit = 5, int $helpLimit = 6): array
    {
        $payload = $this->contentArticleService->publishedOverview($noticeLimit, $helpLimit);

        return [
            'notices' => PublishedContentSummaryResource::collection($payload['notices'])->resolve(),
            'help_articles' => PublishedContentSummaryResource::collection($payload['help_articles'])->resolve(),
            'notice_categories' => PublishedContentCategoryResource::collection($payload['notice_categories'])->resolve(),
            'help_categories' => PublishedContentCategoryResource::collection($payload['help_categories'])->resolve(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function notifications(int $userId, bool $unreadOnly, int $page, int $pageSize): array
    {
        $result = $this->inboxService->list($userId, $unreadOnly, $page, $pageSize);

        return [
            'list' => ClientNotificationResource::collection($result['list'])->resolve(),
            'total' => (int) $result['total'],
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function pagePayload(LengthAwarePaginator $paginator, array $extra = []): array
    {
        return array_merge([
            'list' => PublishedContentSummaryResource::collection($paginator->items())->resolve(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
        ], $extra);
    }
}
