<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Supplier;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class ListSupplierProviderTypesRequest extends AdminFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }
}
