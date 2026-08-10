<?php

declare(strict_types=1);

namespace App\Http\Resources\V2\Content;

use Illuminate\Http\Request;

class PublishedContentDetailResource extends PublishedContentSummaryResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'content' => (string) ($this->content ?? ''),
            'keywords' => $this->keywords,
            'updated_at' => optional($this->updated_at)?->toDateTimeString(),
        ]);
    }
}
