<?php

namespace App\Http\Requests\Client\V2\Auth;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;
use App\Support\AccountIdentifier;
use Closure;

class LoginRequest extends ClientFormRequest
{
    protected function prepareForValidation(): void
    {
        // Controller 原逻辑：$request->merge(['account' => $this->resolveSubmittedAccount($request)])
        // resolveSubmittedAccount 逻辑内联到这
        $account = $this->input('email') ?? $this->input('phone') ?? $this->input('account');
        $this->merge([
            'account' => trim((string) $account),
        ]);
    }

    public function rules(): array
    {
        return [
            'account' => ['required', 'string', 'max:100', function (string $attribute, mixed $value, Closure $fail) {
                if (AccountIdentifier::detectType((string) $value) === null) {
                    $fail('请输入正确的邮箱或手机号');
                }
            }],
            'password' => 'required|string|min:6',
        ];
    }
}
