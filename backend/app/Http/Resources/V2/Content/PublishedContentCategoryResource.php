<?php

declare(strict_types=1);

namespace App\Http\Resources\V2\Content;

use App\Models\ContentCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ContentCategory */
class PublishedContentCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'content_type' => (string) $this->content_type,
            'type' => (string) $this->type,
            'name' => (string) $this->name,
            'slug' => (string) ($this->slug ?? ''),
            'description' => $this->description,
            'articles_count' => isset($this->articles_count) ? (int) $this->articles_count : null,
        ];
    }
}
