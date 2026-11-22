<?php

namespace App\Http\Requests\Client\V2\Auth;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;
use App\Support\AccountIdentifier;

class SendEmailCodeRequest extends ClientFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => AccountIdentifier::normalizeEmail((string) $this->input('email')),
        ]);
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email|max:100',
            // 用途口径与 SendPhoneCodeRequest 对齐：
            // verify_bound_email = 发往当前已绑定邮箱（旧邮箱校验），
            // bind_email = 发往待绑定的新邮箱（绑定/换绑第二步），
            // reset_password/password_reset = 邮箱验证码重置密码。
            'purpose' => 'nullable|string|in:login,register,reset,reset_password,password_reset,generic,verify_bound_email,change_email,bind_email',
        ];
    }
}
