<?php

declare(strict_types=1);

namespace App\Http\Requests\Site\V2\Product;

use App\Constants\ProductType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductPurchaseContextRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_type' => ['sometimes', 'string', Rule::in(ProductType::businessAllowedValues())],
            'first_product_group_code' => ['sometimes', 'string', 'max:50'],
            'root_page_size' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }
}
