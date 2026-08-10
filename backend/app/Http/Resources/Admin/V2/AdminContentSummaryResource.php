<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminContentSummaryResource extends JsonResource
{
    /**
     * @return array<string, int>
     */
    public function toArray(Request $request): array
    {
        /** @var array<string, int|string|null> $summary */
        $summary = (array) $this->resource;

        return [
            'notices_total' => (int) ($summary['notices_total'] ?? 0),
            'helps_total' => (int) ($summary['helps_total'] ?? 0),
            'published_total' => (int) ($summary['published_total'] ?? 0),
            'draft_total' => (int) ($summary['draft_total'] ?? 0),
            'pinned_total' => (int) ($summary['pinned_total'] ?? 0),
        ];
    }
}
