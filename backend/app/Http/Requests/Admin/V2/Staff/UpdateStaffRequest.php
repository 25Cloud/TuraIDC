<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Staff;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;
use App\Models\AdminUser;
use App\Support\AdminPermissions;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends AdminFormRequest
{
    public function rules(): array
    {
        $staff = $this->route('staff');
        $staffId = $staff instanceof AdminUser ? (int) $staff->getKey() : (int) $staff;
        $canEditAccount = $this->user()?->hasPermission(AdminPermissions::ALL) === true;

        return [
            'staff' => ['required', 'integer', 'min:1'],
            'username' => $canEditAccount ? [
                'required',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9_.@-]+$/',
                Rule::unique('admin_users', 'username')->ignore($staffId),
            ] : ['missing'],
            'nickname' => ['nullable', 'string', 'max:50'],
            'email' => $canEditAccount ? ['nullable', 'email', 'max:100', Rule::unique('admin_users', 'email')->ignore($staffId)] : ['missing'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'status' => ['required', 'integer', 'in:0,1'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    public function payload(): array
    {
        $fields = ['nickname', 'role_id', 'status'];

        if ($this->user()?->hasPermission(AdminPermissions::ALL) === true) {
            array_unshift($fields, 'username');
            $fields[] = 'email';
        }

        return $this->safe()->only($fields);
    }

    public function messages(): array
    {
        return [
            'username.regex' => '登录账号仅支持字母、数字、下划线、点、横线和 @',
        ];
    }

    protected function prepareForValidation(): void
    {
        $staff = $this->route('staff');

        $this->merge([
            'staff' => $staff instanceof AdminUser ? $staff->getKey() : $staff,
        ]);
    }
}
