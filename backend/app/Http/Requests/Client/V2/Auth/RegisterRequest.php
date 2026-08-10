<?php

namespace App\Http\Requests\Client\V2\Auth;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;
use App\Support\AccountIdentifier;
use Closure;

class RegisterRequest extends ClientFormRequest
{
    protected function prepareForValidation(): void
    {
        $account = $this->input('email') ?? $this->input('phone') ?? $this->input('account');
        $this->merge([
            'account' => trim((string) $account),
            'email' => AccountIdentifier::normalizeOptionalEmail((string) $this->input('email')),
            'phone' => AccountIdentifier::normalizeOptionalPhone((string) $this->input('phone')),
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
            'nickname' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:100',
            'phone' => ['nullable', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail) {
                if ($value !== null && $value !== '' && AccountIdentifier::detectType((string) $value) !== 'phone') {
                    $fail('请输入正确的手机号');
                }
            }],
            'referral_code' => 'nullable|string|max:24',
        ];
    }
}
