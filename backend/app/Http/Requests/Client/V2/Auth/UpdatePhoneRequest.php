<?php

namespace App\Http\Requests\Client\V2\Auth;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;
use App\Support\AccountIdentifier;
use Closure;

class UpdatePhoneRequest extends ClientFormRequest
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
                    $fail('手机号格式不正确');
                }
            }],
            'code' => 'required|string|size:6',
        ];
    }
}
