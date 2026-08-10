<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminInstanceSpecCatalogItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : [];

        return [
            'id' => (string) ($item['id'] ?? ''),
            'value' => (string) ($item['value'] ?? ''),
            'text' => (string) ($item['text'] ?? ''),
            'alias' => (string) ($item['alias'] ?? ''),
            'note' => (string) ($item['note'] ?? ''),
            'status' => (string) ($item['status'] ?? ''),
            'sort_order' => (int) ($item['sort_order'] ?? 0),
            'bindings' => $this->bindings($item['bindings'] ?? null),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function bindings(mixed $bindings): array
    {
        return collect(is_array($bindings) ? $bindings : [])
            ->filter(fn (mixed $binding): bool => is_array($binding))
            ->map(fn (array $binding): array => (new AdminCatalogBindingResource($binding))->resolve())
            ->values()
            ->all();
    }
}
