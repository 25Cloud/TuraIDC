<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Models\ContentArticle;
use App\Models\ContentCategory;
use App\Support\UploadUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin ContentArticle */
class AdminContentArticleListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ContentArticle $article */
        $article = $this->resource;

        return [
            'id' => (int) $article->id,
            'content_type' => (string) $article->content_type,
            'category_id' => (int) ($article->category_id ?? 0),
            'category_name' => $article->contentCategory?->name ?: $article->category_name,
            'content_category' => $this->categoryPayload($article->contentCategory),
            'title' => (string) $article->title,
            'slug' => $article->slug,
            'summary' => $article->summary,
            'excerpt' => $this->excerpt($article),
            'cover_image' => UploadUrl::resolve($article->cover_image),
            'status' => (int) $article->status,
            'status_label' => ContentArticle::statusLabelOf((int) $article->status),
            'is_pinned' => (int) $article->is_pinned,
            'is_recommended' => (int) $article->is_recommended,
            'sort_order' => (int) $article->sort_order,
            'view_count' => (int) $article->view_count,
            'publish_at' => $article->publish_at?->format('Y-m-d H:i:s'),
            'last_published_at' => $article->last_published_at?->format('Y-m-d H:i:s'),
            'operator' => $article->operator,
            'created_at' => $article->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $article->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function categoryPayload(?ContentCategory $category): ?array
    {
        if (! $category) {
            return null;
        }

        return [
            'id' => (int) $category->id,
            'name' => (string) $category->name,
            'slug' => $category->slug,
            'status' => (int) $category->status,
            'sort_order' => (int) $category->sort_order,
        ];
    }

    protected function excerpt(ContentArticle $article): ?string
    {
        if ($article->summary !== null && trim((string) $article->summary) !== '') {
            return (string) $article->summary;
        }

        $plainText = preg_replace('/\s+/u', ' ', strip_tags(Str::markdown((string) $article->content)));

        return Str::limit((string) $plainText, 120, '...');
    }
}
