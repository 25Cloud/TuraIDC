<?php

declare(strict_types=1);

namespace App\Http\Controllers\Client\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\V2\Content\ListPublishedContentRequest;
use App\Http\Requests\V2\Content\ShowContentOverviewRequest;
use App\Http\Requests\V2\Content\ShowPublishedContentRequest;
use App\Models\ContentArticle;
use App\Services\Content\ContentV2QueryService;
use App\Services\Content\NoticeReadService;
use Illuminate\Http\Request;

class ContentController extends Controller
{
    public function __construct(
        private readonly ContentV2QueryService $contentQueryService,
        private readonly NoticeReadService $noticeReadService,
    ) {}

    public function overview(ShowContentOverviewRequest $request)
    {
        return $this->success($this->contentQueryService->overview(6, 6));
    }

    public function notices(ListPublishedContentRequest $request)
    {
        return $this->success($this->contentQueryService->publishedList(
            ContentArticle::TYPE_NOTICE,
            $request->filters(),
            $request->perPage(12)
        ));
    }

    public function noticeDetail(ShowPublishedContentRequest $request, int $article)
    {
        $payload = $this->contentQueryService->publishedDetail(ContentArticle::TYPE_NOTICE, $article);
        $this->noticeReadService->markRead((int) $request->user()->id, $article);

        return $this->success($payload);
    }

    public function noticeUnreadCount(Request $request)
    {
        return $this->success([
            'count' => $this->noticeReadService->unreadCount((int) $request->user()->id),
        ]);
    }

    public function markAllNoticesRead(Request $request)
    {
        $this->noticeReadService->markAllRead((int) $request->user()->id);

        return $this->success();
    }

    public function helpArticles(ListPublishedContentRequest $request)
    {
        return $this->success($this->contentQueryService->publishedList(
            ContentArticle::TYPE_HELP,
            $request->filters(),
            $request->perPage(12)
        ));
    }

    public function helpDetail(ShowPublishedContentRequest $request, int $article)
    {
        return $this->success($this->contentQueryService->publishedDetail(ContentArticle::TYPE_HELP, $article));
    }
}
