<?php

declare(strict_types=1);

namespace App\Http\Resources\Site\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SiteProductTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : [];

        return [
            'id' => (int) ($item['id'] ?? 0),
            'value' => (string) ($item['value'] ?? ''),
            'label' => (string) ($item['label'] ?? ''),
            'first_product_group_id' => isset($item['first_product_group_id']) ? (int) $item['first_product_group_id'] : null,
            'first_product_group_code' => (string) ($item['first_product_group_code'] ?? ''),
            'first_product_group_name' => (string) ($item['first_product_group_name'] ?? ''),
            'icon' => (string) ($item['icon'] ?? ''),
            'group_count' => (int) ($item['group_count'] ?? 0),
            'product_count' => (int) ($item['product_count'] ?? 0),
        ];
    }
}
