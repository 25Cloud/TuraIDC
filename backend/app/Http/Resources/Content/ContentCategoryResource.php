<?php

namespace App\Http\Resources\Content;

use App\Models\ContentCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContentCategory */
class ContentCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ContentCategory $category */
        $category = $this->resource;

        return [
            'id' => $category->id,
            'content_type' => $category->content_type,
            'type' => $category->type,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'status' => (int) $category->status,
            'sort_order' => (int) $category->sort_order,
            'articles_count' => isset($category->articles_count) ? (int) $category->articles_count : null,
            'created_at' => optional($category->created_at)?->toDateTimeString(),
            'updated_at' => optional($category->updated_at)?->toDateTimeString(),
        ];
    }
}
