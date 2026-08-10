<?php

declare(strict_types=1);

namespace App\Http\Resources\V2\Content;

use App\Models\ContentArticle;
use App\Support\UploadUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/** @mixin ContentArticle */
class PublishedContentSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $category = $this->contentCategory;

        return [
            'id' => (int) $this->id,
            'content_type' => (string) $this->content_type,
            'type' => (string) $this->type,
            'type_label' => ContentArticle::typeLabelOf((string) $this->type),
            'category_id' => (int) ($this->category_id ?? 0),
            'content_category_id' => (int) ($this->content_category_id ?? 0),
            'title' => (string) $this->title,
            'slug' => (string) ($this->slug ?? ''),
            'summary' => $this->summary,
            'excerpt' => $this->excerpt(),
            'category_name' => $category?->name ?: $this->category_name,
            'category' => $category?->name ?: $this->category_name,
            'category_slug' => $category?->slug,
            'cover_image' => UploadUrl::resolve($this->cover_image),
            'status' => (int) $this->status,
            'status_label' => ContentArticle::statusLabelOf((int) $this->status),
            'is_pinned' => (int) $this->is_pinned,
            'is_recommended' => (int) $this->is_recommended,
            'view_count' => (int) $this->view_count,
            'publish_at' => optional($this->publish_at)?->toDateTimeString(),
            'last_published_at' => optional($this->last_published_at)?->toDateTimeString(),
            'created_at' => optional($this->created_at)?->toDateTimeString(),
        ];
    }

    private function excerpt(): string
    {
        if (trim((string) $this->summary) !== '') {
            return (string) $this->summary;
        }

        return Str::limit(
            preg_replace('/\s+/u', ' ', strip_tags(Str::markdown((string) $this->content))) ?: '',
            120,
            '...'
        );
    }
}
