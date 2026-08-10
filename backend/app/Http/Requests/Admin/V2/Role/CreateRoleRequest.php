<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Role;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class CreateRoleRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_.-]+$/', 'unique:roles,name'],
            'label' => ['required', 'string', 'max:50'],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', 'max:100'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    public function payload(): array
    {
        return $this->safe()->only(['name', 'label', 'permissions']);
    }
}
