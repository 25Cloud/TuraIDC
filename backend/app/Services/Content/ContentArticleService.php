<?php

namespace App\Services\Content;

use App\Exceptions\BusinessException;
use App\Models\ContentArticle;
use App\Models\ContentCategory;
use App\Support\ContentPublishedCacheVersion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContentArticleService
{
    private const PUBLISHED_OVERVIEW_CACHE_TTL_SECONDS = 300; // 5分钟：文章列表不频繁变化

    private const PUBLISHED_CATEGORIES_CACHE_TTL_SECONDS = 600; // 10分钟：分类信息更稳定

    /**
     * @return array<string, int>
     */
    public function adminSummary(): array
    {
        return [
            'notices_total' => ContentArticle::query()->where('content_type', ContentArticle::TYPE_NOTICE)->count(),
            'helps_total' => ContentArticle::query()->where('content_type', ContentArticle::TYPE_HELP)->count(),
            'published_total' => ContentArticle::query()->where('status', ContentArticle::STATUS_PUBLISHED)->count(),
            'draft_total' => ContentArticle::query()->where('status', ContentArticle::STATUS_DRAFT)->count(),
            'pinned_total' => ContentArticle::query()->where('is_pinned', 1)->count(),
        ];
    }

    public function adminList(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = ContentArticle::query()
            ->with([
                'contentCategory',
                'creator:id,username,nickname',
                'updater:id,username,nickname',
            ]);

        if (! empty($filters['content_type'])) {
            $query->where('content_type', (string) $filters['content_type']);
        }

        if (($filters['status'] ?? null) !== null && $filters['status'] !== '') {
            $query->where('status', (int) $filters['status']);
        }

        if (($filters['is_pinned'] ?? null) !== null && $filters['is_pinned'] !== '') {
            $query->where('is_pinned', (int) $filters['is_pinned']);
        }

        $categoryId = (int) ($filters['category_id'] ?? $filters['content_category_id'] ?? 0);
        if ($categoryId > 0) {
            $query->where('category_id', $categoryId);
        }

        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function ($builder) use ($keyword) {
                $builder
                    ->where('title', 'like', "%{$keyword}%")
                    ->orWhere('summary', 'like', "%{$keyword}%")
                    ->orWhere('content', 'like', "%{$keyword}%")
                    ->orWhere('slug', 'like', "%{$keyword}%")
                    ->orWhereHas('contentCategory', fn ($query) => $query->where('name', 'like', "%{$keyword}%"));
            });
        }

        return $query
            ->orderByDesc('is_pinned')
            ->orderBy('sort_order')
            ->orderByDesc(DB::raw('COALESCE(publish_at, created_at)'))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function create(array $data, int $adminId, ?string $traceId = null): ContentArticle
    {
        $article = DB::transaction(function () use ($data, $adminId, $traceId) {
            $payload = $this->preparePayload($data);
            $payload['created_by'] = $adminId;
            $payload['updated_by'] = $adminId;
            $payload['operator'] = $payload['operator'] ?: 'admin#'.$adminId;
            $payload['trace_id'] = $traceId ?: $payload['trace_id'];

            $article = ContentArticle::query()->create($payload);

            return $article->load([
                'contentCategory',
                'creator:id,username,nickname',
                'updater:id,username,nickname',
            ]);
        });

        $this->bumpPublishedCacheVersion();

        return $article;
    }

    public function update(ContentArticle $article, array $data, int $adminId, ?string $traceId = null): ContentArticle
    {
        $requireReread = (bool) ($data['require_reread'] ?? false);
        unset($data['require_reread']);

        $updatedArticle = DB::transaction(function () use ($article, $data, $adminId, $traceId, $requireReread) {
            $payload = $this->preparePayload($data, $article);
            $payload['updated_by'] = $adminId;
            $payload['operator'] = $payload['operator'] ?: 'admin#'.$adminId;
            $payload['trace_id'] = $traceId ?: $payload['trace_id'];

            if ($requireReread) {
                $payload['require_reread_at'] = now();
            }

            $article->update($payload);

            return $article->refresh()->load([
                'contentCategory',
                'creator:id,username,nickname',
                'updater:id,username,nickname',
            ]);
        });

        $this->bumpPublishedCacheVersion();

        return $updatedArticle;
    }

    public function delete(ContentArticle $article): void
    {
        // 事务内软删：slug 唯一键释放（ReleasesUniqueKeysOnDelete）与 deleted_at 写入原子
        DB::transaction(function () use ($article): void {
            $article->delete();
        });
        $this->bumpPublishedCacheVersion();
    }

    public function publishedList(string $type, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = ContentArticle::query()
            ->ofType($type)
            ->published();

        $categoryId = (int) ($filters['category_id'] ?? $filters['content_category_id'] ?? 0);
        if ($categoryId > 0) {
            $query->where('category_id', $categoryId);
        }

        if (! empty($filters['keyword'])) {
            $keyword = trim((string) $filters['keyword']);
            $query->where(function ($builder) use ($keyword) {
                $builder
                    ->where('title', 'like', "%{$keyword}%")
                    ->orWhere('summary', 'like', "%{$keyword}%")
                    ->orWhere('content', 'like', "%{$keyword}%")
                    ->orWhere('keywords', 'like', "%{$keyword}%")
                    ->orWhereHas('contentCategory', fn ($query) => $query->where('name', 'like', "%{$keyword}%"));
            });
        }

        if (($filters['is_recommended'] ?? null) !== null && $filters['is_recommended'] !== '') {
            $query->where('is_recommended', (int) $filters['is_recommended']);
        }

        return $query
            ->orderByDesc('is_pinned')
            ->orderBy('sort_order')
            ->orderByDesc(DB::raw('COALESCE(publish_at, created_at)'))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function publishedDetail(string $type, int $articleId): ContentArticle
    {
        $article = ContentArticle::query()
            ->ofType($type)
            ->published()
            ->find($articleId);

        throw_if(! $article, new BusinessException('内容不存在或未发布'));

        $article->increment('view_count');

        return $article->fresh([
            'contentCategory',
            'creator:id,username,nickname',
            'updater:id,username,nickname',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function publishedOverview(int $noticeLimit = 5, int $helpLimit = 6): array
    {
        $cacheKey = $this->publishedCacheKey("overview:{$noticeLimit}:{$helpLimit}");

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(self::PUBLISHED_OVERVIEW_CACHE_TTL_SECONDS),
            fn () => [
                'notices' => $this->latestPublished(ContentArticle::TYPE_NOTICE, $noticeLimit),
                'help_articles' => $this->latestPublished(ContentArticle::TYPE_HELP, $helpLimit, true),
                'notice_categories' => $this->publishedCategories(ContentArticle::TYPE_NOTICE),
                'help_categories' => $this->publishedCategories(ContentArticle::TYPE_HELP),
            ]
        );
    }

    /**
     * @return array<int, string>
     */
    public function publishedCategories(string $type): Collection
    {
        $cacheKey = $this->publishedCacheKey("categories:{$type}");

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(self::PUBLISHED_CATEGORIES_CACHE_TTL_SECONDS),
            fn () => ContentCategory::query()
                ->ofType($type)
                ->enabled()
                ->withCount(['articles' => fn ($builder) => $builder->where('content_type', $type)->published()])
                ->having('articles_count', '>', 0)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
        );
    }

    /**
     * @return Collection<int, ContentArticle>
     */
    public function latestPublished(string $type, int $limit, bool $recommendedFirst = false): Collection
    {
        $query = ContentArticle::query()
            ->ofType($type)
            ->published()
            ->with('contentCategory')
            ->orderByDesc('is_pinned');

        if ($recommendedFirst) {
            $query->orderByDesc('is_recommended');
        }

        return $query
            ->orderBy('sort_order')
            ->orderByDesc(DB::raw('COALESCE(publish_at, created_at)'))
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function preparePayload(array $data, ?ContentArticle $article = null): array
    {
        $type = trim((string) ($data['content_type'] ?? $data['type'] ?? $article?->type ?? ''));
        throw_if(! in_array($type, [ContentArticle::TYPE_NOTICE, ContentArticle::TYPE_HELP], true), new BusinessException('内容类型不正确'));

        $title = trim((string) ($data['title'] ?? ''));
        throw_if($title === '', new BusinessException('标题不能为空'));

        $content = trim((string) ($data['content'] ?? ''));
        throw_if($content === '', new BusinessException('内容不能为空'));

        $contentCategoryId = (int) ($data['category_id'] ?? $data['content_category_id'] ?? $article?->content_category_id ?? 0);
        throw_if($contentCategoryId <= 0, new BusinessException('请选择分类'));

        $contentCategory = ContentCategory::query()->find($contentCategoryId);
        throw_if(! $contentCategory, new BusinessException('分类不存在'));
        throw_if($contentCategory->content_type !== $type, new BusinessException('文章类型与分类类型不匹配'));

        $status = array_key_exists('status', $data)
            ? (int) $data['status']
            : (int) ($article?->status ?? ContentArticle::STATUS_DRAFT);

        $publishAt = $this->resolvePublishAt(
            value: $data['publish_at'] ?? $article?->publish_at,
            status: $status,
        );

        $lastPublishedAt = $article?->last_published_at;
        if ($status === ContentArticle::STATUS_PUBLISHED) {
            $lastPublishedAt = $article?->status === ContentArticle::STATUS_PUBLISHED && $article?->last_published_at
                ? $article->last_published_at
                : now();
        }

        return [
            'content_type' => $type,
            'category_id' => $contentCategoryId,
            'title' => $title,
            'slug' => $this->generateUniqueSlug(
                source: (string) ($data['slug'] ?? $title),
                type: $type,
                ignoreId: $article?->id,
            ),
            'summary' => $this->normalizeNullableString($data['summary'] ?? null),
            'content' => $content,
            'category_name' => $contentCategory->name,
            'keywords' => $this->normalizeNullableString($data['keywords'] ?? null),
            'cover_image' => $this->normalizeNullableString($data['cover_image'] ?? $article?->cover_image),
            'status' => $status,
            'is_pinned' => (int) (($data['is_pinned'] ?? $article?->is_pinned ?? 0) ? 1 : 0),
            'is_recommended' => (int) (($data['is_recommended'] ?? $article?->is_recommended ?? 0) ? 1 : 0),
            'sort_order' => max((int) ($data['sort_order'] ?? $article?->sort_order ?? 0), 0),
            'publish_at' => $publishAt,
            'last_published_at' => $lastPublishedAt,
            'operator' => $this->normalizeNullableString($data['operator'] ?? $article?->operator),
            'remark' => $this->normalizeNullableString($data['remark'] ?? $article?->remark),
            'trace_id' => $this->normalizeNullableString($data['trace_id'] ?? $article?->trace_id),
        ];
    }

    private function resolvePublishAt(mixed $value, int $status): ?Carbon
    {
        if ($status !== ContentArticle::STATUS_PUBLISHED) {
            return $value ? Carbon::parse($value) : null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            return Carbon::parse($value);
        }

        return now();
    }

    private function generateUniqueSlug(string $source, string $type, ?int $ignoreId = null): string
    {
        $source = trim($source);
        $slug = Str::slug($source);

        if ($slug === '') {
            $slug = $type.'-'.Str::lower(Str::random(8));
        }

        $candidate = $slug;
        $suffix = 1;

        while (
            ContentArticle::query()
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->where('slug', $candidate)
                ->exists()
        ) {
            $suffix++;
            $candidate = $slug.'-'.$suffix;
        }

        return $candidate;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function publishedCacheKey(string $suffix): string
    {
        return 'content:published:'.$suffix.':v'.$this->publishedCacheVersion();
    }

    private function publishedCacheVersion(): int
    {
        return ContentPublishedCacheVersion::current();
    }

    private function bumpPublishedCacheVersion(): void
    {
        ContentPublishedCacheVersion::bump();
    }
}
