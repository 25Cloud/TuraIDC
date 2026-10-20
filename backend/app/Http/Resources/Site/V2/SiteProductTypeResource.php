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
            // 商品页地址里的类型段：/products/{slug}/...
            'slug' => (string) ($item['slug'] ?? ''),
            'label' => (string) ($item['label'] ?? ''),
            'first_product_group_id' => isset($item['first_product_group_id']) ? (int) $item['first_product_group_id'] : null,
            'first_product_group_code' => (string) ($item['first_product_group_code'] ?? ''),
            'first_product_group_name' => (string) ($item['first_product_group_name'] ?? ''),
            // 一级分组图标：icon 为内置图标名，image 模式为可访问绝对地址，icon_resolved 已解析优先级
            'icon' => (string) ($item['icon'] ?? ''),
            'icon_text' => $item['icon_text'] ?? null,
            'icon_image' => $item['icon_image'] ?? null,
            'icon_resolved' => $item['icon_resolved'] ?? [
                'mode' => 'abbr',
                'image' => null,
                'text' => null,
                'icon_name' => null,
                'abbr' => '',
            ],
            'group_count' => (int) ($item['group_count'] ?? 0),
            'product_count' => (int) ($item['product_count'] ?? 0),
        ];
    }
}
