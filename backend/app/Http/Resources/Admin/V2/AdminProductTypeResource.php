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
            // 前台商品页地址用的 URL 标识（后台可自定义）
            'slug' => (string) ($item['slug'] ?? ''),
            'label' => (string) ($item['label'] ?? ''),
            'product_type' => (string) ($item['product_type'] ?? ''),
            'product_type_label' => (string) ($item['product_type_label'] ?? ''),
            'product_type_icon' => (string) ($item['product_type_icon'] ?? ''),
            'product_type_plugin_driven' => (bool) ($item['product_type_plugin_driven'] ?? false),
            'first_product_group_id' => isset($item['first_product_group_id']) ? (int) $item['first_product_group_id'] : null,
            'first_product_group_code' => (string) ($item['first_product_group_code'] ?? $item['value'] ?? ''),
            'first_product_group_name' => (string) ($item['first_product_group_name'] ?? $item['label'] ?? ''),
            'icon' => (string) ($item['icon'] ?? ''),
            // 一级分组图标：原始值供表单回填，icon_resolved 已按优先级解析（列表预览直接用）
            'icon_text' => $item['icon_text'] ?? null,
            'icon_image' => $item['icon_image'] ?? null,
            'icon_resolved' => $item['icon_resolved'] ?? [
                'mode' => 'abbr',
                'image' => null,
                'text' => null,
                'icon_name' => isset($item['icon']) ? (string) $item['icon'] : null,
                'abbr' => '',
            ],
            'is_builtin' => (bool) ($item['is_builtin'] ?? false),
            'is_hidden' => (bool) ($item['is_hidden'] ?? false),
            'sort_order' => (int) ($item['sort_order'] ?? 0),
            'usage_count' => (int) ($item['usage_count'] ?? 0),
            'group_count' => (int) ($item['group_count'] ?? 0),
        ];
    }
}
