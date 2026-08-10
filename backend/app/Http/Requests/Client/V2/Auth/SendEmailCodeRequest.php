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
            'purpose' => 'nullable|string|in:login,register,reset,generic',
        ];
    }
}
