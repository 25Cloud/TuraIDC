<?php

namespace App\Http\Resources\Product;

use App\Constants\ProductType;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\ProductCatalog\ProductDisplayNameResolver;
use App\Services\Upstream\ProviderRegistry;
use App\Support\ProductGroupHierarchyFields;
use App\Support\ProductProvisionHostname;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Product */
class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $bindingResolver = app(PluginBindingResolver::class);
        $supplier = $bindingResolver->supplierForProduct($this->resource)
            ?? ($this->resource->relationLoaded('supplier') ? $this->supplier : null);
        $providerKey = $bindingResolver->providerKeyForProduct($this->resource) ?? '';
        $supplierId = (int) (($bindingResolver->supplierIdForProduct($this->resource) ?? 0) ?: 0);
        $upstreamProductId = $bindingResolver->upstreamProductIdForProduct($this->resource);
        $productType = (string) $this->product_type;
        $hierarchyFields = ProductGroupHierarchyFields::fromProduct($this->resource);
        $primaryPrice = $this->resolvePrimaryPrice((array) $this->pricing);
        $provisionHostname = ProductProvisionHostname::fromPurchaseRequires((array) ($this->purchase_requires ?? []));
        $displayName = (new ProductDisplayNameResolver)->resolveForProduct($this->resource);
        $adminSpecDisplay = $this->resolveAdminProductSpecDisplayName($displayName);
        $adminCustomDisplayName = $this->resolveAdminCustomDisplayName($displayName, $adminSpecDisplay);
        $adminDisplayName = $this->resolveAdminProductDisplayName($displayName, $adminSpecDisplay, $adminCustomDisplayName);

        return [
            'id' => $this->id,
            'effective_product_group_name' => $hierarchyFields['third_product_group_name'] ?? $hierarchyFields['second_product_group_name'] ?? '',
            'effective_product_group_parent_name' => $hierarchyFields['second_product_group_name'] ?? '',
            'effective_product_group_full_name' => $this->resolveHierarchyFullName($hierarchyFields),
            'name' => $adminDisplayName,
            'display_name' => $adminDisplayName,
            'custom_display_name' => $adminCustomDisplayName,
            'product_spec_display' => $adminSpecDisplay,
            'product_display_name' => $adminDisplayName,
            'cpu_memory_display' => (string) ($displayName['cpu_memory_display'] ?? ''),
            'combined_display_name' => (string) ($displayName['combined_display_name'] ?? ''),
            'product_type' => $productType,
            'type' => $productType,
            'type_label' => ProductType::businessLabelOf($productType),
            ...$hierarchyFields,
            'remark' => (string) ($this->remark ?? ''),
            'pricing' => (array) $this->pricing,
            'product_prices' => (array) $this->pricing,
            'primary_price' => $primaryPrice,
            'primary_cycle' => $primaryPrice['cycle'] ?? '',
            'setup_fee' => (string) $this->setup_fee,
            'config_options' => $this->config_options ?? [],
            'product_options' => $this->config_options ?? [],
            'purchase_requires' => [
                'require_verification' => (bool) (($this->purchase_requires ?? [])['require_verification'] ?? false),
                'require_phone' => (bool) (($this->purchase_requires ?? [])['require_phone'] ?? false),
                'provision_hostname' => $provisionHostname,
            ],
            'stock' => (int) $this->stock,
            'status' => (int) $this->status,
            'is_deleted' => $this->resource->trashed(),
            'lifecycle_status' => $this->resource->trashed() ? 'deleted' : 'active',
            'deleted_at' => $this->resource->deleted_at?->format('Y-m-d H:i:s'),
            'sort_order' => (int) $this->sort_order,
            'auto_setup' => (int) $this->auto_setup,
            'provision_hostname' => $provisionHostname,
            'upstream_binding' => [
                'provider_key' => $providerKey,
                'provider_label' => $this->providerLabel($providerKey),
                'supplier_id' => $supplierId > 0 ? $supplierId : null,
                'supplier_name' => $supplier instanceof Supplier ? $supplier->name : null,
                'upstream_product_id' => $upstreamProductId,
            ],
            'orders_count' => (int) ($this->orders_count ?? 0),
            'services_count' => (int) ($this->services_count ?? 0),
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function resolveHierarchyFullName(array $hierarchyFields): string
    {
        return collect([
            $hierarchyFields['first_product_group_name'] ?? '',
            $hierarchyFields['second_product_group_name'] ?? '',
            $hierarchyFields['third_product_group_name'] ?? '',
        ])
            ->map(fn ($value): string => trim((string) $value))
            ->filter()
            ->implode(' / ');
    }

    private function providerLabel(string $providerKey): string
    {
        if ($providerKey === '') {
            return '';
        }

        return app(ProviderRegistry::class)->descriptor($providerKey)?->label ?? $providerKey;
    }

    private function resolveAdminProductDisplayName(array $displayName, string $specDisplay, string $customDisplayName): string
    {
        foreach ([
            $customDisplayName,
            $specDisplay,
            $displayName['product_display_name'] ?? '',
        ] as $candidate) {
            $normalized = trim((string) $candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '未配置规格 #'.(int) $this->id;
    }

    private function resolveAdminProductSpecDisplayName(array $displayName): string
    {
        foreach ([
            $displayName['cpu_memory_display'] ?? '',
            $displayName['product_spec_display'] ?? '',
            $displayName['product_display_name'] ?? '',
        ] as $candidate) {
            $normalized = trim((string) $candidate);
            if ($this->isMeaningfulProductDisplaySegment($normalized)) {
                return $normalized;
            }
        }

        return '未配置规格 #'.(int) $this->id;
    }

    private function resolveAdminCustomDisplayName(array $displayName, string $specDisplay): string
    {
        $customDisplayName = trim((string) ($displayName['custom_display_name'] ?? ''));
        if ($customDisplayName === '') {
            return '';
        }

        $defaultCandidates = [
            $specDisplay,
            $displayName['cpu_memory_display'] ?? '',
            $displayName['product_spec_display'] ?? '',
        ];

        $customComparable = $this->normalizeDisplayComparable($customDisplayName);
        foreach ($defaultCandidates as $candidate) {
            if ($customComparable !== '' && $customComparable === $this->normalizeDisplayComparable((string) $candidate)) {
                return '';
            }
        }

        return $customDisplayName;
    }

    private function normalizeDisplayComparable(string $value): string
    {
        $normalized = strtolower((string) preg_replace('/[\s_-]+/u', '', trim($value)));
        $normalized = (string) preg_replace('/(\d+(?:\.\d+)?)gb/i', '$1gib', $normalized);
        $normalized = (string) preg_replace('/(\d+(?:\.\d+)?)g(?![a-z])/i', '$1gib', $normalized);

        return $normalized;
    }

    private function isMeaningfulProductDisplaySegment(string $value): bool
    {
        return $value !== '' && ! str_starts_with($value, '未配置规格 #');
    }

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
