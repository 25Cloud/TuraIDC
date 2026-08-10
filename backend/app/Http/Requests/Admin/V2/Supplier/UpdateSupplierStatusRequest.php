<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Supplier;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class UpdateSupplierStatusRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'page' => ['prohibited'],
            'page_size' => ['prohibited'],
            'pageSize' => ['prohibited'],
            'per_page' => ['prohibited'],
        ];
    }

    public function enabled(): bool
    {
        return (bool) $this->validated()['enabled'];
    }
}
