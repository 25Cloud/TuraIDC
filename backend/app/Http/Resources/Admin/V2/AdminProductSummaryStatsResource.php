<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProductSummaryStatsResource extends JsonResource
{
    /**
     * @return array<string, int>
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];

        return [
            'first_product_groups_total' => (int) ($payload['first_product_groups_total'] ?? 0),
            'second_product_groups_total' => (int) ($payload['second_product_groups_total'] ?? 0),
            'third_product_groups_total' => (int) ($payload['third_product_groups_total'] ?? 0),
            'products_total' => (int) ($payload['products_total'] ?? 0),
            'products_deleted' => (int) ($payload['products_deleted'] ?? 0),
            'products_active' => (int) ($payload['products_active'] ?? 0),
            'products_low_stock' => (int) ($payload['products_low_stock'] ?? 0),
        ];
    }
}
