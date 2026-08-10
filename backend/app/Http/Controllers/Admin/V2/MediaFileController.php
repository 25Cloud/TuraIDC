<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Content\ReindexMediaFilesRequest;
use App\Http\Requests\Admin\V2\MediaFile\DeleteMediaFileRequest;
use App\Http\Requests\Admin\V2\MediaFile\ListMediaFilesRequest;
use App\Http\Requests\Admin\V2\MediaFile\StoreMediaFileRequest;
use App\Http\Resources\Admin\V2\AdminActionResultResource;
use App\Http\Resources\Admin\V2\AdminMediaFileResource;
use App\Models\MediaFile;
use App\Services\Admin\V2\AdminOperationalActionV2Service;
use App\Services\Content\MediaFileService;
use App\Support\ApiResponseBuilder;
use Illuminate\Http\JsonResponse;

class MediaFileController extends Controller
{
    public function __construct(
        private readonly AdminOperationalActionV2Service $actions,
        private readonly MediaFileService $mediaFileService,
    ) {}

    public function index(ListMediaFilesRequest $request): JsonResponse
    {
        $filters = $request->validated();

        if (($filters['group'] ?? null) === MediaFileService::HERO_VIDEO_GROUP) {
            return $this->success($this->heroVideoPayload($request, $filters));
        }

        $paginator = $this->mediaFileService->list(
            filters: array_intersect_key($filters, array_flip(['group', 'keyword', 'type'])),
            perPage: $request->perPage(24, 100),
        );

        return $this->success(ApiResponseBuilder::pagination($paginator, AdminMediaFileResource::class));
    }

    public function store(StoreMediaFileRequest $request): JsonResponse
    {
        $mediaFile = $this->mediaFileService->upload(
            file: $request->file('file'),
            adminId: (int) $request->user()->id,
            group: trim((string) $request->input('group', 'content')) ?: 'content',
        );

        return $this->success(AdminMediaFileResource::make($mediaFile)->resolve(), '上传成功');
    }

    public function destroy(DeleteMediaFileRequest $request, MediaFile $mediaFile): JsonResponse
    {
        $this->mediaFileService->delete($mediaFile);

        return $this->success(null, '删除成功');
    }

    public function references(MediaFile $mediaFile): JsonResponse
    {
        return $this->success([
            'references' => $this->mediaFileService->checkReferences($mediaFile),
        ]);
    }

    public function reindex(ReindexMediaFilesRequest $request): JsonResponse
    {
        $result = $this->actions->reindexMediaFiles((int) $request->user()->id);

        return $this->success(AdminActionResultResource::make($result)->resolve(), (string) $result['message']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function heroVideoPayload(ListMediaFilesRequest $request, array $filters): array
    {
        $items = collect($this->mediaFileService->listHeroVideos((string) ($filters['keyword'] ?? '')));

        if (($filters['type'] ?? null) === 'image') {
            $items = collect();
        }

        $page = max((int) ($filters['page'] ?? 1), 1);
        $pageSize = $request->perPage(24, 100);
        $list = $items->forPage($page, $pageSize)->values()->all();

        return [
            'list' => AdminMediaFileResource::collection($list)->resolve(),
            'total' => $items->count(),
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }
}
