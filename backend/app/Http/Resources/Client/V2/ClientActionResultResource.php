<?php

declare(strict_types=1);

namespace App\Http\Resources\Client\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClientActionResultResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : [];

        return array_filter([
            'id' => $item['id'] ?? null,
            'status' => (string) ($item['status'] ?? 'completed'),
            'task_id' => $item['task_id'] ?? null,
            'message' => (string) ($item['message'] ?? ''),
            'detail' => $item['detail'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
