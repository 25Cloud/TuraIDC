<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\ProductGroup;

use App\Constants\ProductType;
use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use Illuminate\Validation\Rule;

class UpdateProductGroupRequest extends AdminFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'group' => ['required', 'integer', 'min:1'],
            'per_page' => ['prohibited'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'effective_product_group_level' => ['required', 'integer', Rule::in([1, 2, 3])],
            'service_type_code' => ['prohibited'],
            'first_product_group_code' => ['nullable', 'string', 'max:50'],
            'product_type' => ['nullable', 'string', Rule::in(ProductType::businessAllowedValues())],
            'first_product_group_id' => ['nullable', 'integer', 'min:1'],
            'second_product_group_id' => ['nullable', 'integer', 'min:1'],
            'name' => ['sometimes', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'slogan' => ['nullable', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:120'],
            'banner_image' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999999'],
            'is_visible' => ['nullable', Rule::in([0, 1, '0', '1'])],
            'is_system' => ['nullable', Rule::in([0, 1, '0', '1'])],
        ];
    }

    public function validationData(): array
    {
        return array_merge(parent::validationData(), [
            'group' => $this->route('group'),
        ]);
    }
}
