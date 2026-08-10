<?php

namespace App\Http\Requests\Client\V2\Auth;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;
use App\Support\AccountIdentifier;
use Closure;

class ResetPasswordRequest extends ClientFormRequest
{
    protected function prepareForValidation(): void
    {
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
            'code' => 'required|string|size:6',
            'password' => 'required|string|min:6|confirmed',
        ];
    }
}
