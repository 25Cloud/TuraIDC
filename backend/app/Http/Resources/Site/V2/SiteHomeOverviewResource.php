<?php

declare(strict_types=1);

namespace App\Http\Resources\Site\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteHomeOverviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];

        return [
            'site_config' => SiteConfigResource::make((array) ($payload['site_config'] ?? []))->resolve(),
            'hero' => SiteHomeHeroResource::make((array) ($payload['hero'] ?? []))->resolve(),
            'notices' => $this->projectArticles((array) ($payload['notices'] ?? [])),
            'help_articles' => $this->projectArticles((array) ($payload['help_articles'] ?? [])),
            'root_groups' => $this->projectRootGroups((array) ($payload['root_groups'] ?? [])),
            'group_catalog_map' => $this->projectCatalogMap((array) ($payload['group_catalog_map'] ?? [])),
        ];
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function projectArticles(array $items): array
    {
        return collect($items)
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(static fn (array $item): array => [
                'id' => (int) ($item['id'] ?? 0),
                'title' => (string) ($item['title'] ?? ''),
                'summary' => $item['summary'] ?? null,
                'excerpt' => (string) ($item['excerpt'] ?? ''),
                'cover_image' => $item['cover_image'] ?? null,
                'category' => (string) ($item['category'] ?? ''),
                'is_pinned' => (int) ($item['is_pinned'] ?? 0),
                'is_recommended' => (int) ($item['is_recommended'] ?? 0),
                'publish_at' => $item['publish_at'] ?? null,
                'updated_at' => $item['updated_at'] ?? null,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<array<string, mixed>>
     */
    private function projectRootGroups(array $items): array
    {
        return collect($items)
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(static fn (array $item): array => [
                'id' => (int) ($item['id'] ?? 0),
                'name' => (string) ($item['name'] ?? ''),
                'slogan' => (string) ($item['slogan'] ?? ''),
                'product_count' => (int) ($item['product_count'] ?? 0),
                'product_type' => (string) ($item['product_type'] ?? ''),
                'product_type_id' => (int) ($item['product_type_id'] ?? 0),
                'product_type_label' => (string) ($item['product_type_label'] ?? ''),
                'first_product_group_code' => (string) ($item['first_product_group_code'] ?? ''),
                'first_product_group_name' => (string) ($item['first_product_group_name'] ?? ''),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int|string, mixed>  $items
     */
    private function projectCatalogMap(array $items): object
    {
        $map = collect($items)
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->mapWithKeys(static fn (array $item, int|string $key): array => [
                (string) $key => [
                    'preview_products' => self::projectPreviewProducts((array) ($item['preview_products'] ?? [])),
                    'featured_product' => is_array($item['featured_product'] ?? null)
                        ? self::projectPreviewProduct((array) $item['featured_product'])
                        : null,
                ],
            ])
            ->all();

        return (object) $map;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return list<array<string, mixed>>
     */
    private static function projectPreviewProducts(array $items): array
    {
        return collect($items)
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->map(static fn (array $item): array => self::projectPreviewProduct($item))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function projectPreviewProduct(array $item): array
    {
        return [
            'id' => (int) ($item['id'] ?? 0),
            'effective_product_group_id' => (int) ($item['effective_product_group_id'] ?? 0),
            'name' => (string) ($item['name'] ?? ''),
            'display_name' => (string) ($item['display_name'] ?? ''),
            'instance_spec_text' => (string) ($item['instance_spec_text'] ?? ''),
            'instance_spec_alias' => (string) ($item['instance_spec_alias'] ?? ''),
            'primary_price' => (string) ($item['primary_price'] ?? '0.00'),
        ];
    }
}
