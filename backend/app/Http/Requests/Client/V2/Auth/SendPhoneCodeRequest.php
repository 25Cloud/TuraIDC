<?php

namespace App\Http\Requests\Client\V2\Auth;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;
use App\Support\AccountIdentifier;
use Closure;

class SendPhoneCodeRequest extends ClientFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => AccountIdentifier::normalizePhone((string) $this->input('phone')),
        ]);
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:20', function (string $attribute, mixed $value, Closure $fail) {
                if (AccountIdentifier::detectType((string) $value) !== 'phone') {
                    $fail('请输入正确的手机号');
                }
            }],
            'purpose' => 'nullable|string|in:login,register,reset,reset_password,password_reset,change_phone,phone_change,update_phone,bind_phone,new_phone,verify_bound_phone,verify_phone,generic',
        ];
    }
}
