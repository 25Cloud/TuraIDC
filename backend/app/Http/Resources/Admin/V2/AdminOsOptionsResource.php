<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminOsOptionsResource extends JsonResource
{
    /**
     * @return array{groups: list<array{label: string, children: list<array{label: string, value: string}>}>}
     */
    public function toArray(Request $request): array
    {
        $groups = is_array($this->resource) ? ($this->resource['groups'] ?? []) : [];

        return [
            'groups' => collect(is_array($groups) ? $groups : [])
                ->filter(static fn (mixed $group): bool => is_array($group))
                ->map(static fn (array $group): array => [
                    'label' => (string) ($group['label'] ?? ''),
                    'children' => collect(is_array($group['children'] ?? null) ? $group['children'] : [])
                        ->filter(static fn (mixed $item): bool => is_array($item))
                        ->map(static fn (array $item): array => [
                            'label' => (string) ($item['label'] ?? ''),
                            'value' => (string) ($item['value'] ?? ''),
                        ])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }
}
