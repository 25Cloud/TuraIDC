<?php

namespace App\Http\Requests\Client\V2\Auth;

use App\Http\Requests\Client\V2\Common\ClientFormRequest;
use App\Support\AccountIdentifier;

class UpdateEmailRequest extends ClientFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => AccountIdentifier::normalizeEmail((string) $this->input('email')),
        ]);
    }

    public function rules(): array
    {
        $hasBoundEmail = trim((string) ($this->user()?->email ?? '')) !== '';

        return [
            'email' => 'required|email|max:100|unique:users,email,'.$this->user()?->id,
            'code' => 'required|string|size:6',
            'old_code' => $hasBoundEmail ? 'required|string|size:6' : 'nullable|string|size:6',
        ];
    }
}
