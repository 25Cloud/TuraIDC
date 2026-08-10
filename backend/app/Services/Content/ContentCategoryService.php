<?php

namespace App\Services\Content;

use App\Exceptions\BusinessException;
use App\Models\ContentArticle;
use App\Models\ContentCategory;
use App\Support\ContentPublishedCacheVersion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ContentCategoryService
{
    /**
     * @return Collection<int, ContentCategory>
     */
    public function adminList(string $contentType): Collection
    {
        return ContentCategory::query()
            ->ofType($contentType)
            ->withCount('articles')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function create(array $data, int $adminId): ContentCategory
    {
        $category = DB::transaction(function () use ($data, $adminId) {
            $payload = $this->preparePayload($data);
            $payload['created_by'] = $adminId;
            $payload['updated_by'] = $adminId;

            return ContentCategory::query()->create($payload);
        });

        $this->bumpPublishedCacheVersion();

        return $category;
    }

    public function update(ContentCategory $category, array $data, int $adminId): ContentCategory
    {
        $updatedCategory = DB::transaction(function () use ($category, $data, $adminId) {
            $payload = $this->preparePayload($data, $category);
            $payload['updated_by'] = $adminId;
            $category->update($payload);

            ContentArticle::query()
                ->where('category_id', $category->id)
                ->update(['category_name' => $category->name]);

            return $category->refresh();
        });

        $this->bumpPublishedCacheVersion();

        return $updatedCategory;
    }

    public function delete(ContentCategory $category): void
    {
        throw_if(
            $category->articles()->exists(),
            new BusinessException('当前分类下还有文章，无法删除，请先迁移或删除文章'),
        );

        $category->delete();
        $this->bumpPublishedCacheVersion();
    }

    /**
     * @return Collection<int, ContentCategory>
     */
    public function publicList(string $contentType, bool $onlyPublishedArticles = true): Collection
    {
        $query = ContentCategory::query()
            ->ofType($contentType)
            ->enabled()
            ->withCount(['articles' => function ($builder) use ($onlyPublishedArticles, $contentType) {
                $builder->where('content_type', $contentType);

                if ($onlyPublishedArticles) {
                    $builder->published();
                }
            }])
            ->orderBy('sort_order')
            ->orderBy('id');

        if ($onlyPublishedArticles) {
            $query->having('articles_count', '>', 0);
        }

        return $query->get();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function preparePayload(array $data, ?ContentCategory $category = null): array
    {
        $contentType = trim((string) ($data['content_type'] ?? $data['type'] ?? $category?->type ?? ''));
        throw_if(! in_array($contentType, [ContentArticle::TYPE_NOTICE, ContentArticle::TYPE_HELP], true), new BusinessException('分类类型不正确'));

        $name = trim((string) ($data['name'] ?? ''));
        throw_if($name === '', new BusinessException('分类名称不能为空'));

        $existsByName = ContentCategory::query()
            ->when($category?->id, fn ($query) => $query->where('id', '!=', $category->id))
            ->where('content_type', $contentType)
            ->where('name', $name)
            ->exists();
        throw_if($existsByName, new BusinessException('同类型下分类名称已存在'));

        return [
            'content_type' => $contentType,
            'name' => $name,
            'slug' => $this->generateUniqueSlug(
                source: (string) ($data['slug'] ?? $name),
                type: $contentType,
                ignoreId: $category?->id,
            ),
            'description' => $this->normalizeNullableString($data['description'] ?? null),
            'status' => (int) (($data['status'] ?? $category?->status ?? 1) ? 1 : 0),
            'sort_order' => max((int) ($data['sort_order'] ?? $category?->sort_order ?? 0), 0),
        ];
    }

    private function generateUniqueSlug(string $source, string $type, ?int $ignoreId = null): string
    {
        $slug = Str::slug(trim($source));
        if ($slug === '') {
            $slug = $type.'-category';
        }

        $candidate = $slug;
        $suffix = 1;

        while (
            ContentCategory::query()
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->where('content_type', $type)
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

    private function bumpPublishedCacheVersion(): void
    {
        ContentPublishedCacheVersion::bump();
    }
}
