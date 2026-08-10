<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\V2\Staff;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class CreateStaffRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'max:50', 'regex:/^[A-Za-z0-9_.@-]+$/', 'unique:admin_users,username'],
            'password' => ['required', 'string', 'min:8', 'max:64'],
            'nickname' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:100', 'unique:admin_users,email'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'status' => ['nullable', 'integer', 'in:0,1'],
            'per_page' => ['prohibited'],
            'pageSize' => ['prohibited'],
        ];
    }

    public function payload(): array
    {
        return $this->safe()->only(['username', 'password', 'nickname', 'email', 'role_id', 'status']);
    }

    public function messages(): array
    {
        return [
            'username.regex' => '登录账号仅支持字母、数字、下划线、点、横线和 @',
        ];
    }
}
