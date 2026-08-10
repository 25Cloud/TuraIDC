<?php

namespace App\Http\Resources\Content;

use App\Models\ContentArticle;
use App\Support\UploadUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin ContentArticle */
class ContentArticleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ContentArticle $article */
        $article = $this->resource;
        $action = $request->route()?->getActionMethod();
        $includeContent = in_array($action, ['show', 'store', 'update', 'noticeDetail', 'helpDetail'], true);
        $excerpt = $article->summary ?: Str::limit(
            preg_replace(
                '/\s+/u',
                ' ',
                strip_tags(Str::markdown((string) $article->content))
            ),
            120,
            '...'
        );

        return [
            'id' => $article->id,
            'content_type' => $article->content_type,
            'type' => $article->type,
            'type_label' => ContentArticle::typeLabelOf($article->type),
            'category_id' => (int) ($article->category_id ?? 0),
            'content_category_id' => (int) ($article->content_category_id ?? 0),
            'title' => $article->title,
            'slug' => $article->slug,
            'summary' => $article->summary,
            'excerpt' => $excerpt,
            'content' => $this->when($includeContent, $article->content),
            'category_name' => $article->contentCategory?->name ?: $article->category_name,
            'category' => $article->contentCategory?->name ?: $article->category_name,
            'category_slug' => $article->contentCategory?->slug,
            'category_description' => $article->contentCategory?->description,
            'category_detail' => $article->contentCategory ? [
                'id' => $article->contentCategory->id,
                'name' => $article->contentCategory->name,
                'slug' => $article->contentCategory->slug,
                'description' => $article->contentCategory->description,
                'status' => (int) $article->contentCategory->status,
                'sort_order' => (int) $article->contentCategory->sort_order,
            ] : null,
            'keywords' => $article->keywords,
            'cover_image' => $this->resolveCoverImageUrl($article->cover_image),
            'status' => (int) $article->status,
            'status_label' => ContentArticle::statusLabelOf((int) $article->status),
            'is_pinned' => (int) $article->is_pinned,
            'is_recommended' => (int) $article->is_recommended,
            'sort_order' => (int) $article->sort_order,
            'view_count' => (int) $article->view_count,
            'publish_at' => optional($article->publish_at)?->toDateTimeString(),
            'last_published_at' => optional($article->last_published_at)?->toDateTimeString(),
            'operator' => $article->operator,
            'remark' => $article->remark,
            'trace_id' => $article->trace_id,
            'created_at' => optional($article->created_at)?->toDateTimeString(),
            'updated_at' => optional($article->updated_at)?->toDateTimeString(),
            'creator' => $article->relationLoaded('creator') && $article->creator ? [
                'id' => $article->creator->id,
                'username' => $article->creator->username,
                'nickname' => $article->creator->nickname,
            ] : null,
            'updater' => $article->relationLoaded('updater') && $article->updater ? [
                'id' => $article->updater->id,
                'username' => $article->updater->username,
                'nickname' => $article->updater->nickname,
            ] : null,
        ];
    }

    private function resolveCoverImageUrl(?string $value): ?string
    {
        return UploadUrl::resolve($value);
    }
}
