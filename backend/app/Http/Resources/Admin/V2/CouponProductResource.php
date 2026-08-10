<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Models\Product;
use App\Services\ProductCatalog\ProductDisplayNameResolver;
use App\Support\ProductGroupHierarchyFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class CouponProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $displayName = app(ProductDisplayNameResolver::class)->resolveForProduct($this->resource);
        $productDisplayName = $this->firstFilled([
            $displayName['custom_display_name'] ?? '',
            $displayName['cpu_memory_slug_display'] ?? '',
            $displayName['product_spec_display'] ?? '',
            $displayName['cpu_memory_display'] ?? '',
            $displayName['product_display_name'] ?? '',
            $displayName['combined_display_name'] ?? '',
            '未配置规格 #'.(int) $this->id,
        ]);
        $hierarchyFields = ProductGroupHierarchyFields::fromProduct($this->resource);
        $groupPath = $this->groupPath($hierarchyFields);
        $productType = (string) ($hierarchyFields['service_type_code'] ?? $this->product_type ?? '');

        return [
            'id' => (int) $this->id,
            'product_id' => (int) $this->id,
            'node_type' => 'product',
            'label' => $productDisplayName,
            'product_display_name' => $productDisplayName,
            'custom_display_name' => (string) ($displayName['custom_display_name'] ?? ''),
            'cpu_memory_display' => (string) ($displayName['cpu_memory_display'] ?? ''),
            'cpu_memory_slug_display' => (string) ($displayName['cpu_memory_slug_display'] ?? ''),
            'product_spec_display' => (string) ($displayName['product_spec_display'] ?? ''),
            'combined_display_name' => (string) ($displayName['combined_display_name'] ?? ''),
            'product_type' => $productType,
            'service_type_code' => $productType,
            'category_full_name' => $groupPath,
            'first_product_group_id' => $hierarchyFields['first_product_group_id'] ?? null,
            'first_product_group_name' => $hierarchyFields['first_product_group_name'] ?? '',
            'second_product_group_id' => $hierarchyFields['second_product_group_id'] ?? null,
            'second_product_group_name' => $hierarchyFields['second_product_group_name'] ?? '',
            'third_product_group_id' => $hierarchyFields['third_product_group_id'] ?? null,
            'third_product_group_name' => $hierarchyFields['third_product_group_name'] ?? null,
            'effective_product_group_id' => $hierarchyFields['effective_product_group_id'] ?? null,
            'effective_product_group_level' => $hierarchyFields['effective_product_group_level'] ?? null,
            'primary_price' => $this->resolvePrimaryPrice((array) ($this->pricing ?? [])),
            'status' => (int) ($this->status ?? 0),
            'sort_order' => (int) ($this->sort_order ?? 0),
        ];
    }

    /**
     * @param  array<int, mixed>  $candidates
     */
    private function firstFilled(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function groupPath(array $hierarchyFields): string
    {
        return collect([
            $hierarchyFields['first_product_group_name'] ?? '',
            $hierarchyFields['second_product_group_name'] ?? '',
            $hierarchyFields['third_product_group_name'] ?? '',
        ])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->implode(' / ');
    }

    /**
     * @return array{cycle: string, amount: string}
     */
    private function resolvePrimaryPrice(array $pricing): array
    {
        foreach ($pricing as $cycle => $amount) {
            if ((float) $amount > 0) {
                return [
                    'cycle' => (string) $cycle,
                    'amount' => number_format((float) $amount, 2, '.', ''),
                ];
            }
        }

        return [
            'cycle' => '',
            'amount' => '0.00',
        ];
    }
}
