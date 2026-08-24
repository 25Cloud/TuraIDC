<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\ApiKey;

use Illuminate\Foundation\Http\FormRequest;

class UpdateApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:64'],
            'scopes' => ['sometimes', 'array'],
            'scopes.*' => ['string', 'in:read,write'],
            'expires_at' => ['nullable', 'date'],
            'ip_allowlist' => ['nullable', 'array'],
            'ip_allowlist.*' => ['string', 'max:45'],
        ];
    }
}
