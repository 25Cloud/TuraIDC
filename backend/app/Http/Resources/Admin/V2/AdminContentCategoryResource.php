<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Models\ContentCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContentCategory */
class AdminContentCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ContentCategory $category */
        $category = $this->resource;

        return [
            'id' => (int) $category->id,
            'content_type' => (string) $category->content_type,
            'name' => (string) $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'status' => (int) $category->status,
            'sort_order' => (int) $category->sort_order,
            'articles_count' => isset($category->articles_count) ? (int) $category->articles_count : 0,
            'created_at' => $category->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $category->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
