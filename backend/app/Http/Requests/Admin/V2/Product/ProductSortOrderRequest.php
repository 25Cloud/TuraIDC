<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Product;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use Illuminate\Validation\Rule;

/**
 * 商品列表拖拽排序。
 *
 * 沿用列表当前筛选条件与分页位置，服务端据此校验「本页商品集合」与前端
 * 提交的顺序是否一致，避免并发改动或跨页拖拽把顺序写坏。
 *
 * filters 的可接受键必须与 ProductAdminService::applyAdminProductFilters()
 * 实际读取的键保持一致：多传未知键不会报错，却会让服务端按未过滤列表
 * 切页并把顺序写错，因此这里用白名单严格拒绝。
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
            'filters.keyword' => ['sometimes', 'nullable', 'string', 'max:191'],
            'filters.product_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'filters.type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'filters.first_product_group_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'filters.lifecycle_status' => ['sometimes', 'nullable', 'string', Rule::in(['active', 'deleted', 'all'])],
            'filters.status' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1'],
            'filters.first_product_group_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'filters.second_product_group_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'filters.third_product_group_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'filters.supplier_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'filters.provider_key' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    /**
     * 只从 validated() 取值，未声明的键不会进入 payload。
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $validated = $this->validated();

        return [
            'product_ids' => array_map('intval', (array) ($validated['product_ids'] ?? [])),
            'page' => (int) ($validated['page'] ?? 1),
            'page_size' => (int) ($validated['page_size'] ?? 20),
            'filters' => (array) ($validated['filters'] ?? []),
        ];
    }
}
