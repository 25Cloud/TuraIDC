<?php

declare(strict_types=1);

namespace App\Http\Requests\Site\V2\ProductGroup;

use App\Constants\ProductType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProductGroupsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'page_size' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'product_type' => ['sometimes', 'nullable', Rule::in(ProductType::businessAllowedValues())],
            'first_product_group_code' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
