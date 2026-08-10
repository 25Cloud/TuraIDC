<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\V2\Content\ListPublishedContentRequest;
use App\Http\Requests\V2\Content\ShowContentOverviewRequest;
use App\Http\Requests\V2\Content\ShowPublishedContentRequest;
use App\Models\ContentArticle;
use App\Services\Content\ContentV2QueryService;

class ContentController extends Controller
{
    public function __construct(
        private readonly ContentV2QueryService $contentQueryService,
    ) {}

    public function overview(ShowContentOverviewRequest $request)
    {
        return $this->success($this->contentQueryService->overview());
    }

    public function notices(ListPublishedContentRequest $request)
    {
        return $this->success($this->contentQueryService->publishedList(
            ContentArticle::TYPE_NOTICE,
            $request->filters(),
            $request->perPage()
        ));
    }

    public function noticeDetail(ShowPublishedContentRequest $request, int $article)
    {
        return $this->success($this->contentQueryService->publishedDetail(ContentArticle::TYPE_NOTICE, $article));
    }

    public function helpArticles(ListPublishedContentRequest $request)
    {
        return $this->success($this->contentQueryService->publishedList(
            ContentArticle::TYPE_HELP,
            $request->filters(),
            $request->perPage()
        ));
    }

    public function helpDetail(ShowPublishedContentRequest $request, int $article)
    {
        return $this->success($this->contentQueryService->publishedDetail(ContentArticle::TYPE_HELP, $article));
    }
}
