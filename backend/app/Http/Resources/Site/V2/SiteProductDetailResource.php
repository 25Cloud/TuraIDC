<?php

declare(strict_types=1);

namespace App\Http\Resources\Site\V2;

use App\Constants\BillingCycle;
use App\Constants\ProductType;
use App\Models\Product;
use App\Services\ProductCatalog\ProductDisplayNameResolver;
use App\Services\ProductCatalog\ProductSiteService;
use App\Support\ProductGroupHierarchyFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteProductDetailResource extends JsonResource
{
    private const BILLING_CYCLES = BillingCycle::RENEWABLE_LABELS;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;
        $display = app(ProductDisplayNameResolver::class)->resolveForProduct($product);
        $hierarchy = ProductGroupHierarchyFields::fromProduct($product);
        $cpuModelPayload = app(ProductSiteService::class)->cpuModelPayloadForProduct($product);
        $productType = (string) ($hierarchy['product_type'] ?? $hierarchy['service_type_code'] ?? $product->product_type ?? '');
        $pricing = $this->pricing($product);
        $primaryCycle = $this->primaryCycle($pricing);

        return [
            'id' => (int) $product->id,
            'name' => (string) ($display['product_display_name'] !== '' ? $display['product_display_name'] : ($product->custom_display_name ?? '')),
            'display_name' => (string) $display['product_display_name'],
            'product_display_name' => (string) $display['product_display_name'],
            'product_spec_display' => (string) $display['product_spec_display'],
            'combined_display_name' => (string) $display['combined_display_name'],
            'cpu_memory_display' => (string) $display['cpu_memory_display'],
            'cpu_display' => (string) ($display['cpu_display'] ?? ''),
            'memory_display' => (string) ($display['memory_display'] ?? ''),
            ...$cpuModelPayload,
            'product_type' => $productType,
            'type' => $productType,
            'type_label' => ProductType::businessLabelOf($productType),
            ...$hierarchy,
            'pricing' => $pricing,
            'pricing_entries' => $this->pricingEntries($pricing, $product),
            'primary_cycle' => $primaryCycle,
            'primary_price' => $primaryCycle !== '' ? $pricing[$primaryCycle] : '0.00',
            'setup_fee' => number_format((float) ($product->setup_fee ?? 0), 2, '.', ''),
            'stock' => (int) ($product->stock ?? -1),
            'auto_setup' => (int) ($product->auto_setup ?? 0),
            'group' => $this->groupPayload($hierarchy, $productType, $display),
            'config_options' => $this->trimConfigOptions($product->config_options),
            'siblings' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $hierarchy
     * @param  array<string, mixed>  $display
     * @return array<string, mixed>
     */
    private function groupPayload(array $hierarchy, string $productType, array $display): array
    {
        return [
            'id' => $hierarchy['effective_product_group_id'],
            'product_type' => $productType,
            'product_type_id' => (int) ($hierarchy['first_product_group_id'] ?? 0),
            'product_type_label' => ProductType::businessLabelOf($productType),
            'name' => $hierarchy['third_product_group_name'] ?? $hierarchy['second_product_group_name'] ?? '',
            'display_name' => (string) ($display['product_display_name'] ?? ''),
            'slogan' => $hierarchy['third_product_group_description'] ?? $hierarchy['second_product_group_description'] ?? '',
            'parent_id' => $hierarchy['second_product_group_id'],
            'parent_product_type' => $productType,
            'parent_product_type_id' => (int) ($hierarchy['first_product_group_id'] ?? 0),
            'parent_name' => $hierarchy['second_product_group_name'] ?? '',
            'parent_display_name' => $hierarchy['second_product_group_name'] ?? '',
            'parent_slogan' => $hierarchy['second_product_group_description'] ?? '',
            ...$hierarchy,
            'full_name' => $this->categoryFullName($hierarchy),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function pricing(Product $product): array
    {
        $pricing = [];

        foreach ((array) ($product->pricing ?? []) as $cycle => $amount) {
            $cycle = (string) $cycle;
            if (! array_key_exists($cycle, self::BILLING_CYCLES)) {
                continue;
            }

            $pricing[$cycle] = is_numeric($amount)
                ? number_format((float) $amount, 2, '.', '')
                : '0.00';
        }

        return $pricing;
    }

    /**
     * @param  array<string, string>  $pricing
     * @return array<int, array<string, string>>
     */
    private function pricingEntries(array $pricing, Product $product): array
    {
        $setupFee = number_format((float) ($product->setup_fee ?? 0), 2, '.', '');
        $entries = [];

        foreach (self::BILLING_CYCLES as $cycle => $label) {
            if (! array_key_exists($cycle, $pricing)) {
                continue;
            }

            $amount = $pricing[$cycle];
            $entries[] = [
                'cycle' => $cycle,
                'label' => $label,
                'amount' => $amount,
                'setup_fee' => $setupFee,
                'total_amount' => number_format((float) $amount + (float) $setupFee, 2, '.', ''),
            ];
        }

        return $entries;
    }

    /**
     * @param  array<string, string>  $pricing
     */
    private function primaryCycle(array $pricing): string
    {
        foreach (self::BILLING_CYCLES as $cycle => $_label) {
            if (isset($pricing[$cycle]) && (float) $pricing[$cycle] > 0) {
                return $cycle;
            }
        }

        return array_key_first($pricing) ?? '';
    }

    private function trimConfigOptions(mixed $configOptions): array
    {
        if (! is_array($configOptions)) {
            return [];
        }

        return collect($configOptions)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item, int $index): array {
                $clean = $this->removeSensitiveKeys($item);

                return [
                    'id' => isset($clean['id']) ? (int) $clean['id'] : 0,
                    'field' => trim((string) ($clean['field'] ?? '')),
                    'spec_key' => trim((string) ($clean['spec_key'] ?? '')),
                    'name' => trim((string) ($clean['name'] ?? $clean['option_name'] ?? '')),
                    'description' => trim((string) ($clean['description'] ?? '')),
                    'hidden' => (int) ($clean['hidden'] ?? 0),
                    'required' => (int) ($clean['required'] ?? 0),
                    'sort_order' => (int) ($clean['sort_order'] ?? $clean['order'] ?? ($index + 1)),
                    'option_type' => isset($clean['option_type']) ? (int) $clean['option_type'] : null,
                    'option_mode' => trim((string) ($clean['option_mode'] ?? '')),
                    'parameter' => trim((string) ($clean['parameter'] ?? '')),
                    'qty_minimum' => $clean['qty_minimum'] ?? null,
                    'qty_maximum' => $clean['qty_maximum'] ?? null,
                    'qty_step' => $clean['qty_step'] ?? null,
                    'qty_stage' => $clean['qty_stage'] ?? null,
                    'suffix_text' => trim((string) ($clean['suffix_text'] ?? '')),
                    'sub' => $this->trimSubOptions($clean['sub'] ?? []),
                ];
            })
            ->values()
            ->all();
    }

    private function trimSubOptions(mixed $subOptions): array
    {
        if (! is_array($subOptions)) {
            return [];
        }

        return collect($subOptions)
            ->filter(fn (mixed $item): bool => is_array($item))
            ->map(function (array $item, int $index): array {
                $clean = $this->removeSensitiveKeys($item);

                return [
                    'id' => isset($clean['id']) ? (string) $clean['id'] : '',
                    'label' => trim((string) ($clean['label'] ?? '')),
                    'version' => trim((string) ($clean['version'] ?? '')),
                    'option_name' => trim((string) ($clean['option_name'] ?? '')),
                    'hidden' => (int) ($clean['hidden'] ?? 0),
                    'sort_order' => (int) ($clean['sort_order'] ?? $clean['order'] ?? $index),
                    'qty_minimum' => $clean['qty_minimum'] ?? null,
                    'qty_maximum' => $clean['qty_maximum'] ?? null,
                ];
            })
            ->values()
            ->all();
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
