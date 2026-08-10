<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminProductTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : [];

        return [
            'internal_id' => (int) ($item['internal_id'] ?? 0),
            'value' => (string) ($item['value'] ?? ''),
            'label' => (string) ($item['label'] ?? ''),
            'product_type' => (string) ($item['product_type'] ?? ''),
            'product_type_label' => (string) ($item['product_type_label'] ?? ''),
            'product_type_icon' => (string) ($item['product_type_icon'] ?? ''),
            'product_type_plugin_driven' => (bool) ($item['product_type_plugin_driven'] ?? false),
            'first_product_group_id' => isset($item['first_product_group_id']) ? (int) $item['first_product_group_id'] : null,
            'first_product_group_code' => (string) ($item['first_product_group_code'] ?? $item['value'] ?? ''),
            'first_product_group_name' => (string) ($item['first_product_group_name'] ?? $item['label'] ?? ''),
            'icon' => (string) ($item['icon'] ?? ''),
            'is_builtin' => (bool) ($item['is_builtin'] ?? false),
            'is_hidden' => (bool) ($item['is_hidden'] ?? false),
            'sort_order' => (int) ($item['sort_order'] ?? 0),
            'usage_count' => (int) ($item['usage_count'] ?? 0),
            'group_count' => (int) ($item['group_count'] ?? 0),
        ];
    }
}
