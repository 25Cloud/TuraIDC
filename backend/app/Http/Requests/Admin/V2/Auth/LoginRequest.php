<?php

namespace App\Http\Requests\Admin\V2\Auth;

use App\Http\Requests\Admin\V2\Common\AdminFormRequest;

class LoginRequest extends AdminFormRequest
{
    /**
     * 约束管理员登录凭据及插件生成的人机验证载荷大小。
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge([
            'username' => 'required|string',
            'password' => 'required|string|min:6',
            'captcha' => ['nullable', 'array', 'max:8'],
            'captcha.lot_number' => ['nullable', 'string', 'max:2048'],
            'captcha.captcha_output' => ['nullable', 'string', 'max:2048'],
            'captcha.pass_token' => ['nullable', 'string', 'max:2048'],
            'captcha.gen_time' => ['nullable', 'string', 'max:64'],
            'captcha.token' => ['nullable', 'string', 'max:2048'],
            'captcha.knock' => ['nullable', 'string', 'max:2048'],
            'captcha.dfu' => ['nullable', 'string', 'max:2048'],
            'captcha.provider' => ['nullable', 'string', 'max:64'],
        ], [
            'per_page' => ['prohibited'],
        ]);
    }
}
