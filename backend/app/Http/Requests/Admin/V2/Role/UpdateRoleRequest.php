<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Role;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Models\Role;
use Illuminate\Validation\Rule;

class UpdateRoleRequest extends AdminFormRequest
{
    public function rules(): array
    {
        $role = $this->route('role');
        $roleId = $role instanceof Role ? (int) $role->getKey() : (int) $role;

        return [
            'role' => ['required', 'integer', 'min:1'],
            'name' => [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9_.-]+$/',
                Rule::unique('roles', 'name')->ignore($roleId),
            ],
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

    protected function prepareForValidation(): void
    {
        $role = $this->route('role');

        $this->merge([
            'role' => $role instanceof Role ? $role->getKey() : $role,
        ]);
    }
}
