<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\ApiKey;

use Illuminate\Foundation\Http\FormRequest;

class StoreApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:64'],
            'scopes' => ['required', 'array'],
            'scopes.*' => ['string', 'in:read,write'],
            'expires_at' => ['nullable', 'date'],
            'ip_allowlist' => ['nullable', 'array'],
            'ip_allowlist.*' => ['string', 'max:45'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => '请输入密钥名称',
            'scopes.required' => '请至少选择一项权限范围',
        ];
    }
}
