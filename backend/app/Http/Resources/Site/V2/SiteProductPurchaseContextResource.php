<?php

declare(strict_types=1);

namespace App\Http\Resources\Site\V2;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class SiteProductPurchaseContextResource extends JsonResource
{
    private const ROOT_GROUP_SLOGAN_MAX_LENGTH = 120;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];
        $rootGroups = $payload['root_groups'] ?? null;

        return [
            'types' => $this->purchaseTypes((array) ($payload['types'] ?? [])),
            'root_groups' => $rootGroups instanceof LengthAwarePaginator
                ? $this->rootGroupItems($rootGroups, $request)
                : [],
            'root_groups_total' => $rootGroups instanceof LengthAwarePaginator ? $rootGroups->total() : 0,
            'root_groups_page' => $rootGroups instanceof LengthAwarePaginator ? $rootGroups->currentPage() : 1,
            'root_groups_page_size' => $rootGroups instanceof LengthAwarePaginator ? $rootGroups->perPage() : 0,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rootGroupItems(LengthAwarePaginator $paginator, Request $request): array
    {
        return collect($paginator->items())
            ->map(function (mixed $group) use ($request): array {
                $payload = (new SiteProductGroupResource($group))->resolve($request);
                $payload['slogan'] = Str::limit(
                    (string) ($payload['slogan'] ?? ''),
                    self::ROOT_GROUP_SLOGAN_MAX_LENGTH,
                    ''
                );

                return $payload;
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $types
     * @return list<array{id: int, value: string, label: string, product_type: string, group_count: int, product_count: int}>
     */
    private function purchaseTypes(array $types): array
    {
        return collect($types)
            ->filter(static fn (mixed $type): bool => is_array($type))
            ->map(static fn (array $type): array => [
                'id' => (int) ($type['id'] ?? 0),
                'value' => (string) ($type['value'] ?? ''),
                'label' => (string) ($type['label'] ?? ''),
                'product_type' => (string) ($type['product_type'] ?? ''),
                'group_count' => (int) ($type['group_count'] ?? 0),
                'product_count' => (int) ($type['product_count'] ?? 0),
            ])
            ->filter(static fn (array $type): bool => $type['id'] > 0 && $type['value'] !== '')
            ->values()
            ->all();
    }
}
