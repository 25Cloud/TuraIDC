<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Constants\ProductType;
use App\Models\Product;
use App\Models\ProductUpstreamBinding;
use App\Models\Supplier;
use App\Models\SupplierPluginBinding;
use App\Services\ProductCatalog\ProductDisplayNameResolver;
use App\Support\ProductGroupHierarchyFields;
use App\Support\ProductProvisionHostname;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class AdminProductDetailResource extends JsonResource
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
        $purchaseRequires = $this->removeSensitiveKeys((array) ($product->purchase_requires ?? []));

        return [
            'id' => (int) $product->id,
            'display' => [
                'display_name' => (string) $display['product_display_name'],
                'product_spec_display' => (string) $display['product_spec_display'],
                'custom_display_name' => (string) $display['custom_display_name'],
                'cpu_memory_display' => (string) $display['cpu_memory_display'],
                'combined_display_name' => (string) $display['combined_display_name'],
                'remark' => (string) ($product->remark ?? ''),
            ],
            'classification' => [
                'product_type' => $productType,
                'product_type_label' => ProductType::businessLabelOf($productType),
                ...$hierarchy,
                'category_full_name' => $this->categoryFullName($hierarchy),
            ],
            'pricing' => [
                'items' => $this->pricing((array) ($product->pricing ?? [])),
                'primary_price' => $this->primaryPrice((array) ($product->pricing ?? [])),
                'setup_fee' => number_format((float) ($product->setup_fee ?? 0), 2, '.', ''),
            ],
            'configuration' => [
                'console_template' => $product->console_template,
                'config_options' => $this->removeSensitiveKeys((array) ($product->config_options ?? [])),
                'cpu_model' => (string) ($product->cpu_model ?? ''),
                'cpu_turbo' => (string) ($product->cpu_turbo ?? ''),
            ],
            'purchase_requirements' => [
                'require_verification' => (bool) ($purchaseRequires['require_verification'] ?? false),
                'require_phone' => (bool) ($purchaseRequires['require_phone'] ?? false),
                'provision_hostname' => ProductProvisionHostname::fromPurchaseRequires($purchaseRequires),
            ],
            'provisioning' => [
                'stock' => (int) ($product->stock ?? -1),
                'auto_setup' => (int) ($product->auto_setup ?? 0),
            ],
            'upstream_binding' => $this->upstreamBinding($product),
            'product_discount_group' => $product->productDiscountGroup ? [
                'id' => (int) $product->productDiscountGroup->id,
                'name' => (string) $product->productDiscountGroup->name,
                'code' => (string) $product->productDiscountGroup->code,
                'min_discount_rate' => (float) $product->productDiscountGroup->min_discount_rate,
                'cost_rate' => (float) $product->productDiscountGroup->cost_rate,
            ] : null,
            'statistics' => [
                'orders_count' => (int) ($product->orders_count ?? 0),
                'services_count' => (int) ($product->services_count ?? $product->total_services_count ?? 0),
                'active_services_count' => (int) ($product->active_services_count ?? 0),
            ],
            'lifecycle' => [
                'status' => (int) ($product->status ?? 0),
                'lifecycle_status' => $product->trashed() ? 'deleted' : 'active',
                'deleted_at' => $product->deleted_at?->format('Y-m-d H:i:s'),
                'sort_order' => (int) ($product->sort_order ?? 0),
            ],
            'timestamps' => [
                'created_at' => $product->created_at?->format('Y-m-d H:i:s'),
                'updated_at' => $product->updated_at?->format('Y-m-d H:i:s'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function upstreamBinding(Product $product): ?array
    {
        $bindings = $product->relationLoaded('upstreamBindings')
            ? $product->getRelation('upstreamBindings')
            : collect();

        if (! $bindings instanceof Collection || $bindings->isEmpty()) {
            return null;
        }

        $binding = $bindings
            ->sortByDesc(fn (ProductUpstreamBinding $item): array => [
                (int) ($item->status ?? 0),
                (int) $item->id,
            ])
            ->first();

        if (! $binding instanceof ProductUpstreamBinding) {
            return null;
        }

        $supplierBinding = $binding->relationLoaded('supplierPluginBinding') ? $binding->supplierPluginBinding : null;
        $supplier = $supplierBinding instanceof SupplierPluginBinding && $supplierBinding->relationLoaded('supplier')
            ? $supplierBinding->supplier
            : null;

        return [
            'id' => (int) $binding->id,
            'provider_key' => (string) ($binding->provider_key ?? ''),
            'supplier_id' => $supplierBinding instanceof SupplierPluginBinding ? (int) $supplierBinding->supplier_id : null,
            'supplier_name' => $supplier instanceof Supplier ? (string) $supplier->name : null,
            'upstream_product_id' => (string) ($binding->upstream_product_id ?? ''),
            'status' => (int) ($binding->status ?? 0),
            'auto_setup' => (bool) ($binding->auto_setup ?? false),
            'last_synced_at' => $binding->last_synced_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param  array<string, mixed>  $pricing
     * @return array<string, string>
     */
    private function pricing(array $pricing): array
    {
        return collect($pricing)
            ->mapWithKeys(fn (mixed $amount, mixed $cycle): array => [
                (string) $cycle => number_format((float) $amount, 2, '.', ''),
            ])
            ->all();
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

    private function removeSensitiveKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $clean = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                continue;
            }

            $clean[$key] = $this->removeSensitiveKeys($item);
        }

        return $clean;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower($key);

        foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
