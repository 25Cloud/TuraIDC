<?php

declare(strict_types=1);

namespace App\Http\Resources\Site\V2;

use App\Models\Product;
use App\Services\ProductCatalog\ProductDisplayNameResolver;
use App\Services\ProductCatalog\ProductSpecHighlightService;
use App\Support\ProductGroupHierarchyFields;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteProductSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Product $product */
        $product = $this->resource;
        $display = app(ProductDisplayNameResolver::class)->resolveForProduct($product);
        $specHighlight = app(ProductSpecHighlightService::class);
        $hierarchy = ProductGroupHierarchyFields::fromProduct($product);
        $productType = (string) ($hierarchy['product_type'] ?? $hierarchy['service_type_code'] ?? $product->product_type ?? '');
        $pricing = $this->pricing($product);
        $primaryCycle = $this->primaryCycle($pricing);

        return [
            'id' => (int) $product->id,
            'name' => (string) ($display['product_display_name'] !== '' ? $display['product_display_name'] : ($product->custom_display_name ?? '')),
            'display_name' => (string) $display['product_display_name'],
            'cpu_model_name' => (string) ($product->getAttribute('cpu_model_name') ?? ''),
            'cpu_base_frequency' => (string) ($product->getAttribute('cpu_base_frequency') ?? ''),
            'cpu_turbo_frequency' => (string) ($product->getAttribute('cpu_turbo_frequency') ?? ''),
            'product_type' => $productType,
            ...$hierarchy,
            'primary_cycle' => $primaryCycle,
            'primary_price' => $primaryCycle !== '' ? $pricing[$primaryCycle] : '0.00',
            'setup_fee' => number_format((float) ($product->setup_fee ?? 0), 2, '.', ''),
            'stock' => (int) ($product->stock ?? -1),
            'auto_setup' => (int) ($product->auto_setup ?? 0),
            'spec_highlights' => $specHighlight->resolveHighlightsForProduct($product),
            'spec_highlight_text' => $specHighlight->resolveHighlightText($product),
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
            if (! in_array($cycle, ['monthly', 'quarterly', 'semiannually', 'annually'], true)) {
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
     */
    private function primaryCycle(array $pricing): string
    {
        foreach (['monthly', 'quarterly', 'semiannually', 'annually'] as $cycle) {
            if (isset($pricing[$cycle]) && (float) $pricing[$cycle] > 0) {
                return $cycle;
            }
        }

        return array_key_first($pricing) ?? '';
    }
}
