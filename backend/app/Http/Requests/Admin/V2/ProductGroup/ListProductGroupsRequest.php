<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\ProductGroup;

use App\Constants\ProductType;
use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use Illuminate\Validation\Rule;

class ListProductGroupsRequest extends AdminFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'keyword' => ['sometimes', 'nullable', 'string', 'max:100'],
            'service_type_code' => ['prohibited'],
            'first_product_group_code' => ['sometimes', 'nullable', 'string', 'max:50'],
            'product_type' => ['sometimes', 'nullable', 'string', Rule::in(ProductType::businessAllowedValues())],
            'status' => ['sometimes', 'nullable', 'integer', Rule::in([0, 1])],
        ];
    }
}
