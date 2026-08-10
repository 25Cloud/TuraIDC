<?php

declare(strict_types=1);

namespace App\Http\Resources\Site\V2;

use App\Models\Product;
use App\Services\ProductCatalog\ProductDisplayNameResolver;
use App\Support\ProductGroupHierarchyFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteProductCardResource extends JsonResource
{
    private const BILLING_CYCLES = [
        'monthly' => '月付',
        'quarterly' => '季付',
        'semiannually' => '半年付',
        'annually' => '年付',
    ];

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
        $pricing = $this->pricing($product);
        $primaryCycle = $this->primaryCycle($pricing);

        return [
            'id' => (int) $product->id,
            'name' => (string) ($display['product_display_name'] !== '' ? $display['product_display_name'] : ($product->custom_display_name ?? '')),
            'display_name' => (string) $display['product_display_name'],
            'product_display_name' => (string) $display['product_display_name'],
            'combined_display_name' => (string) $display['combined_display_name'],
            'cpu_memory_display' => (string) $display['cpu_memory_display'],
            'cpu_model_name' => (string) ($product->getAttribute('cpu_model_name') ?? ''),
            'cpu_base_frequency' => (string) ($product->getAttribute('cpu_base_frequency') ?? ''),
            'cpu_turbo_frequency' => (string) ($product->getAttribute('cpu_turbo_frequency') ?? ''),
            'product_type' => $productType,
            ...$hierarchy,
            'pricing' => $pricing,
            'pricing_entries' => $this->pricingEntries($pricing, $product),
            'primary_cycle' => $primaryCycle,
            'primary_price' => $primaryCycle !== '' ? $pricing[$primaryCycle] : '0.00',
            'setup_fee' => number_format((float) ($product->setup_fee ?? 0), 2, '.', ''),
            'stock' => (int) ($product->stock ?? -1),
            'auto_setup' => (int) ($product->auto_setup ?? 0),
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
}
