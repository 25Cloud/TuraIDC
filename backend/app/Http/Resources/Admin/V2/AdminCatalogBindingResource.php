<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminCatalogBindingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $binding = is_array($this->resource) ? $this->resource : [];
        $price = is_array($binding['primary_price'] ?? null) ? $binding['primary_price'] : [];

        return [
            'product_id' => (int) ($binding['product_id'] ?? 0),
            'display_name' => (string) ($binding['display_name'] ?? ''),
            'custom_display_name' => (string) ($binding['custom_display_name'] ?? ''),
            'cpu_memory_display' => (string) ($binding['cpu_memory_display'] ?? ''),
            'cpu_memory_slug_display' => (string) ($binding['cpu_memory_slug_display'] ?? ''),
            'product_spec_display' => (string) ($binding['product_spec_display'] ?? ''),
            'combined_display_name' => (string) ($binding['combined_display_name'] ?? ''),
            'category_full_name' => (string) ($binding['category_full_name'] ?? ''),
            'primary_price' => [
                'cycle' => (string) ($price['cycle'] ?? ''),
                'amount' => (string) ($price['amount'] ?? '0.00'),
            ],
            'status' => (int) ($binding['status'] ?? 0),
        ];
    }
}
