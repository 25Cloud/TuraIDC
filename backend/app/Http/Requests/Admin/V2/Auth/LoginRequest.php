<?php

namespace App\Http\Requests\Admin\V2\Auth;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class LoginRequest extends AdminFormRequest
{
    public function rules(): array
    {
        return array_merge([
            'username' => 'required|string',
            'password' => 'required|string|min:6',
        ], [
            'per_page' => ['prohibited'],
        ]);
    }
}
