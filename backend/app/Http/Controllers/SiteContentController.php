<?php

namespace App\Http\Controllers;

use App\Http\Requests\Site\PublishedListContentRequest;
use App\Http\Resources\Content\ContentArticleResource;
use App\Http\Resources\Content\ContentCategoryResource;
use App\Models\ContentArticle;
use App\Services\Content\ContentArticleService;

class SiteContentController extends Controller
{
    public function __construct(private ContentArticleService $contentArticleService) {}

    public function overview()
    {
        $payload = $this->contentArticleService->publishedOverview();

        return $this->success([
            'notices' => ContentArticleResource::collection($payload['notices'])->resolve(),
            'help_articles' => ContentArticleResource::collection($payload['help_articles'])->resolve(),
            'notice_categories' => ContentCategoryResource::collection($payload['notice_categories'])->resolve(),
            'help_categories' => ContentCategoryResource::collection($payload['help_categories'])->resolve(),
        ]);
    }

    public function notices(PublishedListContentRequest $request)
    {
        return $this->publishedList($request, ContentArticle::TYPE_NOTICE);
    }

    public function noticeDetail(int $articleId)
    {
        return $this->publishedDetail($articleId, ContentArticle::TYPE_NOTICE);
    }

    public function helpArticles(PublishedListContentRequest $request)
    {
        return $this->publishedList($request, ContentArticle::TYPE_HELP);
    }

    public function helpDetail(int $articleId)
    {
        return $this->publishedDetail($articleId, ContentArticle::TYPE_HELP);
    }

    private function publishedList(PublishedListContentRequest $request, string $type)
    {
        $filters = $request->validated();

        if (empty($filters['category_id']) && ! empty($filters['content_category_id'])) {
            $filters['category_id'] = (int) $filters['content_category_id'];
        }

        $perPage = max(1, min((int) ($filters['page_size'] ?? 10), 50));
        $paginator = $this->contentArticleService->publishedList($type, $filters, $perPage);

        return $this->success([
            'list' => ContentArticleResource::collection($paginator->items())->resolve(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
            'categories' => ContentCategoryResource::collection($this->contentArticleService->publishedCategories($type))->resolve(),
        ]);
    }

    private function publishedDetail(int $articleId, string $type)
    {
        $article = $this->contentArticleService->publishedDetail($type, $articleId);

        return $this->success(new ContentArticleResource($article));
    }
}
