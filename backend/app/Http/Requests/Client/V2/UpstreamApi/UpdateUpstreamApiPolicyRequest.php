<?php

declare(strict_types=1);

namespace App\Http\Requests\Client\V2\UpstreamApi;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 魔方财务上游凭据的安全策略（IP 白名单 / 有效期）。
 *
 * 校验规则与开放接口密钥（StoreApiKeyRequest / UpdateApiKeyRequest）保持同一口径：
 * 两个入口治理的是同一类凭据，规则分叉会让用户在两侧遇到不同的输入限制。
 */
class UpdateUpstreamApiPolicyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expires_at' => ['nullable', 'date'],
            'ip_allowlist' => ['nullable', 'array'],
            'ip_allowlist.*' => ['string', 'max:45'],
        ];
    }
}
