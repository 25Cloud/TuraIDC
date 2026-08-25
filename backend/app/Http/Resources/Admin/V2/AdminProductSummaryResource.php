<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Constants\ProductType;
use App\Models\Product;
use App\Services\ProductCatalog\ProductDisplayNameResolver;
use App\Support\ProductGroupHierarchyFields;
use App\Support\ProductProvisionHostname;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProductSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;
        $display = app(ProductDisplayNameResolver::class)->resolveForProduct($product);
        $hierarchy = ProductGroupHierarchyFields::fromProduct($product);
        $productType = (string) ($hierarchy['product_type'] ?? $hierarchy['service_type_code'] ?? $product->product_type ?? '');
        $pricing = (array) ($product->pricing ?? []);
        $primaryPrice = $this->primaryPrice($pricing);
        $provisionHostname = ProductProvisionHostname::fromPurchaseRequires((array) ($product->purchase_requires ?? []));

        return [
            'id' => (int) $product->id,
            'name' => (string) $display['product_display_name'],
            'display_name' => (string) $display['product_display_name'],
            'product_spec_display' => (string) $display['product_spec_display'],
            'custom_display_name' => (string) $display['custom_display_name'],
            'product_display_name' => (string) $display['product_display_name'],
            'cpu_memory_display' => (string) ($display['cpu_memory_display'] ?? ''),
            'combined_display_name' => (string) ($display['combined_display_name'] ?? ''),
            'product_type' => $productType,
            'type' => $productType,
            'product_type_label' => ProductType::businessLabelOf($productType),
            'type_label' => ProductType::businessLabelOf($productType),
            'category_full_name' => $this->categoryFullName($hierarchy),
            'effective_product_group_full_name' => $this->categoryFullName($hierarchy),
            'first_product_group_id' => $hierarchy['first_product_group_id'],
            'first_product_group_code' => $hierarchy['first_product_group_code'] ?? '',
            'first_product_group_name' => $hierarchy['first_product_group_name'] ?? '',
            'second_product_group_id' => $hierarchy['second_product_group_id'],
            'second_product_group_name' => $hierarchy['second_product_group_name'] ?? '',
            'third_product_group_id' => $hierarchy['third_product_group_id'],
            'third_product_group_name' => $hierarchy['third_product_group_name'] ?? '',
            'effective_product_group_id' => $hierarchy['effective_product_group_id'],
            'effective_product_group_level' => $hierarchy['effective_product_group_level'],
            'primary_price' => $primaryPrice,
            'monthly_price' => $this->monthlyPrice($pricing),
            'primary_cycle' => $primaryPrice['cycle'] ?? '',
            'stock' => (int) ($product->stock ?? -1),
            'status' => (int) ($product->status ?? 0),
            'is_deleted' => $product->trashed(),
            'lifecycle_status' => $product->trashed() ? 'deleted' : 'active',
            'deleted_at' => $product->deleted_at?->format('Y-m-d H:i:s'),
            'auto_setup' => (int) ($product->auto_setup ?? 0),
            'provision_hostname' => $provisionHostname,
            'provision_hostname_mode' => (string) ($provisionHostname['mode'] ?? ProductProvisionHostname::MODE_SYSTEM),
            'provision_hostname_summary' => ProductProvisionHostname::summary($provisionHostname),
            'services_count' => (int) ($product->services_count ?? $product->total_services_count ?? 0),
            'total_services_count' => (int) ($product->total_services_count ?? 0),
            'active_services_count' => (int) ($product->services_count ?? 0),
            'sort_order' => (int) ($product->sort_order ?? 0),
            'cpu_model' => (string) ($product->cpu_model ?? ''),
            'cpu_turbo' => (string) ($product->cpu_turbo ?? ''),
            'product_discount_group_id' => $product->product_discount_group_id !== null ? (int) $product->product_discount_group_id : null,
            'product_discount_group_name' => (string) ($product->productDiscountGroup?->name ?? ''),
            'product_discount_group_min_discount_rate' => $product->productDiscountGroup?->min_discount_rate !== null
                ? (string) $product->productDiscountGroup->min_discount_rate
                : null,
            'updated_at' => $product->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param  array<string, mixed>  $pricing
     * @return array{cycle: string, amount: string}|null
     */
    private function primaryPrice(array $pricing): ?array
    {
        foreach (['monthly', 'quarterly', 'semiannually', 'annually'] as $cycle) {
            if (! array_key_exists($cycle, $pricing)) {
                continue;
            }

            return [
                'cycle' => $cycle,
                'amount' => number_format((float) $pricing[$cycle], 2, '.', ''),
            ];
        }

        foreach ($pricing as $cycle => $amount) {
            return [
                'cycle' => (string) $cycle,
                'amount' => number_format((float) $amount, 2, '.', ''),
            ];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $pricing
     */
    private function monthlyPrice(array $pricing): string
    {
        $amount = $pricing['monthly'] ?? null;

        return is_numeric($amount) ? number_format((float) $amount, 2, '.', '') : '0.00';
    }

    /**
     * @param  array<string, mixed>  $hierarchy
     */
    private function categoryFullName(array $hierarchy): string
    {
        return collect([
            $hierarchy['first_product_group_name'] ?? '',
            $hierarchy['second_product_group_name'] ?? '',
            $hierarchy['third_product_group_name'] ?? '',
        ])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter(fn (string $value): bool => $value !== '')
            ->implode(' / ');
    }
}
