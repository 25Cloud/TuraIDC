<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\ProductType;

use App\Constants\ProductType;
use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use Illuminate\Validation\Rule;

class StoreProductTypeRequest extends AdminFormRequest
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
            'label' => ['required', 'string', 'max:30'],
            'product_type' => ['required', 'string', Rule::in(ProductType::businessAllowedValues())],
            'icon' => ['nullable', 'string', 'max:50'],
        ];
    }
}
