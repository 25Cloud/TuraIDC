<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\ContentCategory\DeleteContentCategoryRequest;
use App\Http\Requests\Admin\V2\ContentCategory\ListContentCategoriesRequest;
use App\Http\Requests\Admin\V2\ContentCategory\StoreContentCategoryRequest;
use App\Http\Requests\Admin\V2\ContentCategory\UpdateContentCategoryRequest;
use App\Http\Resources\Admin\V2\AdminContentCategoryResource;
use App\Models\ContentCategory;
use App\Services\Content\ContentCategoryService;
use Illuminate\Http\JsonResponse;

class ContentCategoryController extends Controller
{
    public function __construct(
        private readonly ContentCategoryService $contentCategoryService,
    ) {}

    public function index(ListContentCategoriesRequest $request): JsonResponse
    {
        $items = $this->contentCategoryService->adminList($request->contentType());
        $page = max((int) ($request->validated('page') ?? 1), 1);
        $pageSize = $request->perPage(100, 100);
        $list = $items->forPage($page, $pageSize)->values();

        return $this->success([
            'list' => AdminContentCategoryResource::collection($list)->resolve(),
            'total' => $items->count(),
            'page' => $page,
            'page_size' => $pageSize,
        ]);
    }

    public function store(StoreContentCategoryRequest $request): JsonResponse
    {
        $category = $this->contentCategoryService->create(
            data: $request->validated(),
            adminId: (int) $request->user()->id,
        );

        return $this->success(AdminContentCategoryResource::make($category)->resolve(), '分类创建成功');
    }

    public function update(UpdateContentCategoryRequest $request, ContentCategory $category): JsonResponse
    {
        $category = $this->contentCategoryService->update(
            category: $category,
            data: $request->validated(),
            adminId: (int) $request->user()->id,
        );

        return $this->success(AdminContentCategoryResource::make($category)->resolve(), '分类更新成功');
    }

    public function destroy(DeleteContentCategoryRequest $request, ContentCategory $category): JsonResponse
    {
        $this->contentCategoryService->delete($category);

        return $this->success(null, '分类删除成功');
    }
}
