<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Product;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

/**
 * 商品列表拖拽排序。
 *
 * 沿用列表当前筛选条件与分页位置，服务端据此校验「本页商品集合」与前端
 * 提交的顺序是否一致，避免并发改动或跨页拖拽把顺序写坏。
 */
class ProductSortOrderRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'product_ids' => ['required', 'array', 'min:2'],
            'product_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'page' => ['required', 'integer', 'min:1'],
            'page_size' => ['required', 'integer', 'min:1', 'max:200'],
            'filters' => ['sometimes', 'array'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'product_ids' => array_map('intval', (array) $this->input('product_ids', [])),
            'page' => (int) $this->input('page', 1),
            'page_size' => (int) $this->input('page_size', 20),
            'filters' => (array) ($this->input('filters') ?? []),
        ];
    }
}
