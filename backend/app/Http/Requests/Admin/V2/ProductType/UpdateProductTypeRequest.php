<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\ProductType;

use App\Constants\ProductType;
use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use Illuminate\Validation\Rule;

class UpdateProductTypeRequest extends AdminFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_type_code' => ['required', 'string', 'max:50'],
            'per_page' => ['prohibited'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'label' => ['required', 'string', 'max:30'],
            'product_type' => ['required', 'string', Rule::in(ProductType::businessAllowedValues())],
            'is_hidden' => ['nullable', 'boolean'],
            'icon' => ['nullable', 'string', 'max:50'],
            // 前台地址里的类型段；留空时按 value 生成
            'slug' => ['nullable', 'string', 'min:2', 'max:60', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', 'not_regex:/^\d+$/'],
        ];
    }

    public function validationData(): array
    {
        return array_merge(parent::validationData(), [
            'product_type_code' => $this->route('productType'),
        ]);
    }
}
