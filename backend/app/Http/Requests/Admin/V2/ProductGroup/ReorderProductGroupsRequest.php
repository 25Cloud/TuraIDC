<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\ProductGroup;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use Illuminate\Validation\Rule;

class ReorderProductGroupsRequest extends AdminFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'per_page' => ['prohibited'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'effective_product_group_level' => ['required', 'integer', Rule::in([1, 2, 3])],
            'first_product_group_id' => ['nullable', 'integer', 'min:1', 'required_if:effective_product_group_level,2'],
            'second_product_group_id' => ['nullable', 'integer', 'min:1', 'required_if:effective_product_group_level,3'],
            'first_product_group_ids' => ['nullable', 'array', 'min:2', 'required_if:effective_product_group_level,1'],
            'first_product_group_ids.*' => ['integer', 'min:1'],
            'second_product_group_ids' => ['nullable', 'array', 'min:2', 'required_if:effective_product_group_level,2'],
            'second_product_group_ids.*' => ['integer', 'min:1'],
            'third_product_group_ids' => ['nullable', 'array', 'min:2', 'required_if:effective_product_group_level,3'],
            'third_product_group_ids.*' => ['integer', 'min:1'],
        ];
    }
}
