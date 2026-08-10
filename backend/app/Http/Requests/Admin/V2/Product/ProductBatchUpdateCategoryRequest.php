<?php

namespace App\Http\Requests\Admin\V2\Product;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use Illuminate\Validation\Rule;

class ProductBatchUpdateCategoryRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'product_ids' => ['required', 'array', 'min:1', 'max:200'],
            'product_ids.*' => ['integer', 'min:1', 'distinct'],
            'target_first_product_group_id' => ['nullable', 'integer', 'min:1'],
            'target_second_product_group_id' => ['nullable', 'integer', 'min:1', Rule::exists((new SecondProductGroup)->getTable(), 'id')],
            'target_third_product_group_id' => ['required', 'integer', 'min:1', Rule::exists((new ThirdProductGroup)->getTable(), 'id')],
            ...$this->allPaginationRules(),
        ];
    }
}
