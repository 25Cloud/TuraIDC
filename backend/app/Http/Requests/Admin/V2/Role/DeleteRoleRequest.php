<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Role;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Models\Role;

class DeleteRoleRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'role' => ['required', 'integer', 'min:1'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $role = $this->route('role');

        $this->merge([
            'role' => $role instanceof Role ? $role->getKey() : $role,
        ]);
    }
}
