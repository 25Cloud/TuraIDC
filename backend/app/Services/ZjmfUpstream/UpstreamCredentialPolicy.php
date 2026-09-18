<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use App\Models\User;
use App\Support\IpAllowlistMatcher;

/**
 * 魔方链路凭据的准入策略：账号状态、开关、有效期、IP 白名单。
 *
 * 独立成类是为了让「登录端点」与「鉴权中间件」共用同一份判定。两边条件一旦不一致，
 * 就会出现「登录成功 → 每次业务请求 405 → 魔方强制重登 → 再登录成功 → 再 405」
 * 的死循环（此前 api_open 与 status 条件不一致时踩过这个坑）。
 *
 * 密钥缺省即拒绝：判断为「不限制」只接受显式空列表；无法解析的白名单条目
 * 由 IpAllowlistMatcher 判为不匹配，绝不放行。
 */
final class UpstreamCredentialPolicy
{
    /**
     * 准入判定：返回空串表示放行，否则返回拒绝原因（由调用方映射成各自协议的响应）。
     */
    public function rejectionReason(User $user, string $ip): string
    {
        if ((int) $user->status !== 1) {
            return '账号已停用';
        }

        if ((int) $user->api_open !== 1) {
            return '未开启上游 API 接入';
        }

        if ($this->isExpired($user)) {
            return '上游 API 凭据已过期';
        }

        if (! $this->ipAllowed($user, $ip)) {
            return '当前 IP 不在凭据白名单内';
        }

        return '';
    }

    public function isExpired(User $user): bool
    {
        return $user->api_expires_at !== null && $user->api_expires_at->isPast();
    }

    public function ipAllowed(User $user, string $ip): bool
    {
        $allowlist = $this->ipAllowlist($user);

        return $allowlist === [] || IpAllowlistMatcher::matchesAny($ip, $allowlist);
    }

    /**
     * @return list<string>
     */
    public function ipAllowlist(User $user): array
    {
        $allowlist = is_array($user->api_ip_allowlist) ? $user->api_ip_allowlist : [];

        return $this->normalizeAllowlist($allowlist);
    }

    /**
     * 把请求载荷映射成可落库的列。
     *
     * 缺失的键不出现在返回值里，保证「只改一项」时另一项保留原值；
     * 语义边界与 ApiKeyService::update() 一致：缺失=保留，显式空=清空。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function fillableAttributes(array $payload): array
    {
        $attributes = [];

        if (array_key_exists('ip_allowlist', $payload)) {
            $normalized = $this->normalizeAllowlist((array) $payload['ip_allowlist']);
            $attributes['api_ip_allowlist'] = $normalized === [] ? null : $normalized;
        }

        if (array_key_exists('expires_at', $payload)) {
            $expiresAt = $payload['expires_at'];
            $attributes['api_expires_at'] = ($expiresAt === '' || $expiresAt === null) ? null : $expiresAt;
        }

        return $attributes;
    }

    /**
     * @param  array<mixed>  $allowlist
     * @return list<string>
     */
    private function normalizeAllowlist(array $allowlist): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $entry): string => trim((string) $entry),
            $allowlist
        ), static fn (string $entry): bool => $entry !== ''));
    }
}
