<?php

declare(strict_types=1);

namespace App\Http\Resources\Client\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientNotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : [];

        return [
            'id' => (string) ($item['id'] ?? ''),
            'raw_id' => isset($item['raw_id']) ? (int) $item['raw_id'] : null,
            'source' => (string) ($item['source'] ?? ''),
            'type' => (string) ($item['type'] ?? ''),
            'type_label' => (string) ($item['type_label'] ?? ''),
            'title' => (string) ($item['title'] ?? ''),
            'summary' => (string) ($item['summary'] ?? ''),
            'link' => (string) ($item['link'] ?? ''),
            'read' => (bool) ($item['read'] ?? false),
            'created_at' => $item['created_at'] ?? null,
        ];
    }
}
