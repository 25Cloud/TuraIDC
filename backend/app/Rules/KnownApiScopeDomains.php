<?php

declare(strict_types=1);

namespace App\Rules;

use App\Services\OpenApi\ApiKeyService;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * 校验 API 密钥 scopes 的「键」（业务域）都在白名单内。
 *
 * FormRequest 里 scopes.* 只校验值（read/write），不校验键。缺了这层，把域名拼错
 * （如 products 写成 product）时值仍合法，请求通过，而 ApiKeyService::normalizeScopes()
 * 只遍历已知域、会把这个未知键静默丢弃——用户以为权限设好了，实则没生效。
 * 这条规则把未知域名在入口挡成 422，消除这种「静默降权」。
 */
class KnownApiScopeDomains implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        $unknown = array_diff(array_keys($value), ApiKeyService::SCOPE_DOMAINS);
        if ($unknown !== []) {
            $fail('包含未知的权限域：'.implode('、', $unknown));
        }
    }
}
