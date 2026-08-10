<?php

declare(strict_types=1);

namespace App\Http\Resources\Site\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteProductStockResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];

        return [
            'product_id' => (int) ($payload['product_id'] ?? 0),
            'stock' => (int) ($payload['stock'] ?? 0),
            'stock_status' => (string) ($payload['stock_status'] ?? ''),
            'updated_at' => $payload['updated_at'] ?? null,
        ];
    }
}
