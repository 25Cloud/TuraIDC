<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\ProductType;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ReorderProductTypesRequest extends AdminFormRequest
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
            'values' => ['required', 'array', 'min:2'],
            'values.*' => ['required', 'string', 'max:50', 'distinct'],
        ];
    }
}
