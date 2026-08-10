<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminCpuModelCatalogGroupResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $group = is_array($this->resource) ? $this->resource : [];

        return [
            'id' => (string) ($group['id'] ?? ''),
            'value' => (string) ($group['value'] ?? ''),
            'name' => (string) ($group['name'] ?? ''),
            'sort_order' => (int) ($group['sort_order'] ?? 0),
            'model_count' => (int) ($group['model_count'] ?? 0),
            'models' => $this->models($group['models'] ?? null),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function models(mixed $models): array
    {
        return collect(is_array($models) ? $models : [])
            ->filter(fn (mixed $model): bool => is_array($model))
            ->map(function (array $model): array {
                return [
                    'id' => (string) ($model['id'] ?? ''),
                    'value' => (string) ($model['value'] ?? ''),
                    'name' => (string) ($model['name'] ?? ''),
                    'base_frequency' => (string) ($model['base_frequency'] ?? ''),
                    'turbo_frequency' => (string) ($model['turbo_frequency'] ?? ''),
                    'sort_order' => (int) ($model['sort_order'] ?? 0),
                    'bindings' => $this->bindings($model['bindings'] ?? null),
                ];
            })
            ->values()
            ->all();
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
