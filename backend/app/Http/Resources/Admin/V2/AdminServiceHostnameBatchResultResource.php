<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminServiceHostnameBatchResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : [];

        return [
            'updated_count' => (int) ($item['updated_count'] ?? 0),
            'unchanged_count' => (int) ($item['unchanged_count'] ?? 0),
            'items' => collect(is_array($item['items'] ?? null) ? $item['items'] : [])
                ->map(fn (array $row): array => [
                    'service_id' => (int) ($row['service_id'] ?? 0),
                    'custom_hostname' => (string) ($row['custom_hostname'] ?? ''),
                    'updated' => (bool) ($row['updated'] ?? false),
                ])
                ->values()
                ->all(),
        ];
    }
}
