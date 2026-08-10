<?php

namespace App\Services\Site;

use App\Services\ProductCatalog\ProductCatalogService;

class SiteProductReadService
{
    public function __construct(
        private ProductCatalogService $productCatalogService,
    ) {}

    public function productsInit(?string $productType = null): array
    {
        $types = $this->productCatalogService->siteProductTypes();

        if ($types === []) {
            return [
                'types' => [],
                'root_groups' => [],
                'catalog' => null,
            ];
        }

        $resolvedType = $productType;
        if ($resolvedType === null || $resolvedType === '') {
            $resolvedType = (string) ($types[0]['value'] ?? '');
        }

        $rootGroups = $resolvedType !== ''
            ? $this->productCatalogService->siteRootGroups($resolvedType)
            : [];

        $firstGroupId = (int) ($rootGroups[0]['id'] ?? 0);
        $catalog = $firstGroupId > 0
            ? $this->productCatalogService->siteGroupCatalog($firstGroupId)
            : null;

        return [
            'types' => $types,
            'root_groups' => $rootGroups,
            'catalog' => $catalog,
        ];
    }

    public function productTypes(): array
    {
        return [
            'list' => $this->productCatalogService->siteProductTypes(),
        ];
    }

    public function productGroups(?string $productType = null): array
    {
        return [
            'list' => $this->productCatalogService->siteRootGroups($productType),
        ];
    }

    public function childGroups(int $groupId): array
    {
        return [
            'list' => $this->productCatalogService->siteChildGroups($groupId),
        ];
    }

    public function groupCatalog(int $groupId): array
    {
        return $this->productCatalogService->siteGroupCatalog($groupId);
    }

    /**
     * @param  array<int, int>  $groupIds
     * @return array<int, array<string, mixed>>
     */
    public function groupCatalogMap(array $groupIds): array
    {
        $normalizedGroupIds = collect($groupIds)
            ->map(fn ($groupId) => (int) $groupId)
            ->filter(fn (int $groupId) => $groupId > 0)
            ->unique()
            ->values()
            ->all();

        if ($normalizedGroupIds === []) {
            return [];
        }

        $rootGroups = collect($this->productCatalogService->siteRootGroups())
            ->keyBy(fn (array $group) => (int) ($group['id'] ?? 0));

        $selectedGroups = collect($normalizedGroupIds)
            ->map(fn (int $groupId) => $rootGroups->get($groupId))
            ->filter(fn ($group) => is_array($group) && (int) ($group['id'] ?? 0) > 0)
            ->values();

        if ($selectedGroups->isEmpty()) {
            return [];
        }

        $childGroupRows = $selectedGroups
            ->flatMap(function (array $group): array {
                $groupId = (int) ($group['id'] ?? 0);

                return array_map(
                    static fn (array $child): array => [
                        ...$child,
                        'root_effective_product_group_id' => $groupId,
                    ],
                    $this->productCatalogService->siteChildGroups($groupId)
                );
            })
            ->filter(fn (array $group) => (int) ($group['id'] ?? 0) > 0)
            ->values();

        $catalogGroupIds = $selectedGroups
            ->pluck('id')
            ->merge($childGroupRows->pluck('id'))
            ->map(fn ($groupId) => (int) $groupId)
            ->filter(fn (int $groupId) => $groupId > 0)
            ->unique()
            ->values()
            ->all();

        $itemsByGroup = collect($this->productCatalogService->siteProductsByGroupIds($catalogGroupIds))
            ->keyBy(fn (array $item) => (int) ($item['effective_product_group_id'] ?? 0));

        $childrenByRoot = $childGroupRows
            ->groupBy(fn (array $group) => (int) ($group['root_effective_product_group_id'] ?? 0));

        return $selectedGroups
            ->mapWithKeys(function (array $group) use ($childrenByRoot, $itemsByGroup): array {
                $groupId = (int) ($group['id'] ?? 0);
                $children = $childrenByRoot->get($groupId, collect())
                    ->map(function (array $child) use ($itemsByGroup): array {
                        $childId = (int) ($child['id'] ?? 0);

                        return [
                            'id' => $childId,
                            'products' => array_values((array) ($itemsByGroup->get($childId)['products'] ?? [])),
                        ];
                    })
                    ->values()
                    ->all();

                $groupProducts = array_values((array) ($itemsByGroup->get($groupId)['products'] ?? []));
                $previewProducts = collect([$groupProducts, ...array_column($children, 'products')])
                    ->flatten(1)
                    ->filter(fn (array $product) => (int) ($product['id'] ?? 0) > 0)
                    ->map(function (array $product) use ($groupId): array {
                        $resolvedGroupId = (int) ($product['effective_product_group_id'] ?? $groupId);
                        $displayName = trim((string) (
                            $product['display_name']
                            ?? $product['instance_spec_text']
                            ?? $product['name']
                            ?? ''
                        ));

                        return [
                            'id' => (int) ($product['id'] ?? 0),
                            'effective_product_group_id' => $resolvedGroupId > 0 ? $resolvedGroupId : $groupId,
                            'name' => (string) ($product['name'] ?? ''),
                            'display_name' => $displayName,
                            'instance_spec_text' => (string) ($product['instance_spec_text'] ?? ''),
                            'instance_spec_alias' => (string) ($product['instance_spec_alias'] ?? ''),
                            'primary_price' => (string) ($product['primary_price'] ?? '0.00'),
                        ];
                    })
                    ->take(3)
                    ->values()
                    ->all();

                $featuredProduct = $previewProducts[0] ?? null;

                return [
                    $groupId => [
                        'preview_products' => $previewProducts,
                        'featured_product' => $featuredProduct,
                    ],
                ];
            })
            ->all();
    }

    public function products(array $validated): array
    {
        return [
            'items_by_group' => $this->productCatalogService->siteProductsByGroupIds(
                $this->normalizeCategoryIds($validated)
            ),
        ];
    }

    public function productDetail(int $productId): ?array
    {
        return $this->productCatalogService->siteProductDetail($productId);
    }

    public function productStock(int $productId): ?array
    {
        return $this->productCatalogService->siteProductStock($productId);
    }

    /**
     * @return array<int, int>
     */
    private function normalizeCategoryIds(array $validated): array
    {
        return collect([
            isset($validated['effective_product_group_id']) ? [(int) $validated['effective_product_group_id']] : [],
            (array) ($validated['effective_product_group_ids'] ?? []),
            isset($validated['second_product_group_id']) ? [(int) $validated['second_product_group_id']] : [],
            (array) ($validated['second_product_group_ids'] ?? []),
            isset($validated['third_product_group_id']) ? [(int) $validated['third_product_group_id']] : [],
            (array) ($validated['third_product_group_ids'] ?? []),
        ])
            ->flatten()
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
