<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\ProductType;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class DeleteProductTypeRequest extends AdminFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_type' => ['required', 'string', 'max:50'],
            'per_page' => ['prohibited'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    public function validationData(): array
    {
        return array_merge(parent::validationData(), [
            'product_type' => $this->route('productType'),
        ]);
    }
}
