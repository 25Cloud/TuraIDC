<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\ContentArticle\DeleteContentArticleRequest;
use App\Http\Requests\Admin\V2\ContentArticle\ListContentArticlesRequest;
use App\Http\Requests\Admin\V2\ContentArticle\ShowContentArticleRequest;
use App\Http\Requests\Admin\V2\ContentArticle\StoreContentArticleRequest;
use App\Http\Requests\Admin\V2\ContentArticle\UpdateContentArticleRequest;
use App\Http\Resources\Admin\V2\AdminContentArticleDetailResource;
use App\Http\Resources\Admin\V2\AdminContentArticleListResource;
use App\Http\Resources\Admin\V2\AdminContentSummaryResource;
use App\Models\ContentArticle;
use App\Services\Content\ContentArticleService;
use App\Support\ApiResponseBuilder;
use Illuminate\Http\JsonResponse;

class ContentArticleController extends Controller
{
    public function __construct(
        private readonly ContentArticleService $contentArticleService,
    ) {}

    public function summary(ShowContentArticleRequest $request): JsonResponse
    {
        return $this->success(AdminContentSummaryResource::make(
            $this->contentArticleService->adminSummary()
        )->resolve());
    }

    public function index(ListContentArticlesRequest $request): JsonResponse
    {
        $paginator = $this->contentArticleService->adminList(
            filters: $request->validated(),
            perPage: $request->perPage(),
        );

        return $this->success(ApiResponseBuilder::pagination($paginator, AdminContentArticleListResource::class));
    }

    public function show(ShowContentArticleRequest $request, ContentArticle $article): JsonResponse
    {
        $article->load([
            'contentCategory',
        ]);

        return $this->success(AdminContentArticleDetailResource::make($article)->resolve());
    }

    public function store(StoreContentArticleRequest $request): JsonResponse
    {
        $article = $this->contentArticleService->create(
            data: $request->validated(),
            adminId: (int) $request->user()->id,
            traceId: $request->header('X-Request-Id'),
        );

        return $this->success(AdminContentArticleDetailResource::make($article)->resolve(), '内容创建成功');
    }

    public function update(UpdateContentArticleRequest $request, ContentArticle $article): JsonResponse
    {
        $article = $this->contentArticleService->update(
            article: $article,
            data: $request->validated(),
            adminId: (int) $request->user()->id,
            traceId: $request->header('X-Request-Id'),
        );

        return $this->success(AdminContentArticleDetailResource::make($article)->resolve(), '内容更新成功');
    }

    public function destroy(DeleteContentArticleRequest $request, ContentArticle $article): JsonResponse
    {
        $this->contentArticleService->delete($article);

        return $this->success(null, '内容删除成功');
    }
}
