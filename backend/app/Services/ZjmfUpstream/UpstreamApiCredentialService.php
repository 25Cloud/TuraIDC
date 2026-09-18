<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use App\Exceptions\BusinessException;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 用户自助管理「魔方财务上游 API」凭据（本系统作为上游被对接）。
 *
 * 与开放接口（/api/v2/open，api_keys 表 + Bearer API Key）是两套协议：
 * 本能力对应魔方财务上游协议（/api/v2/zjmf），凭据落在 users.api_open /
 * api_username / api_password，供魔方财务用 username+password 换 JWT。
 *
 * 协议无法合并——魔方侧只送 username/password 换 JWT（见 zjmf376/app/zjmf.php
 * 的 zjmfCurl），不会把 API Key 当 Bearer 直接送过来。但凭据治理必须与开放接口
 * 拉平：这里给魔方凭据补齐 IP 白名单、有效期与调用审计，并让「关闭」对两条
 * 链路的凭据一起生效，避免留下没人记得的全权凭据。
 *
 * api_username 有唯一索引，重复时给出可读提示而不是数据库异常。
 */
class UpstreamApiCredentialService
{
    public const USERNAME_PREFIX = 'zjmf_';

    public const MIN_PASSWORD_LENGTH = 12;

    public function __construct(
        private readonly UpstreamCredentialPolicy $policy,
    ) {}

    /**
     * 当前状态：开关、用户名、是否已设密码（绝不返回密码本身）与安全策略。
     *
     * @return array<string, mixed>
     */
    public function status(User $user): array
    {
        return [
            'enabled' => (int) $user->api_open === 1,
            'username' => (string) ($user->api_username ?? ''),
            'has_password' => trim((string) ($user->api_password ?? '')) !== '',
            'ip_allowlist' => $this->policy->ipAllowlist($user),
            'expires_at' => $user->api_expires_at?->format('Y-m-d H:i:s'),
            'is_expired' => $this->policy->isExpired($user),
            'last_used_at' => $user->api_last_used_at?->format('Y-m-d H:i:s'),
            'login_url' => $this->loginUrl(),
        ];
    }

    /**
     * 开启 API 接入并生成新凭据（明文密码仅本次返回一次）。
     *
     * @param  array<string, mixed>  $policyPayload  ip_allowlist / expires_at
     * @return array<string, mixed>
     */
    public function enable(User $user, array $policyPayload = []): array
    {
        $username = $this->generateUsername($user);
        $password = $this->generatePassword();

        $user->forceFill(array_merge([
            'api_open' => 1,
            'api_username' => $username,
            'api_password' => Hash::make($password),
        ], $this->policy->fillableAttributes($policyPayload)))->save();

        return [
            'username' => $username,
            'password' => $password,
            'login_url' => $this->loginUrl(),
        ];
    }

    /**
     * 关闭 API 接入：立即失效（鉴权中间件按 api_open=1 过滤），并清空凭据与策略。
     *
     * 同时停用该用户全部开放接口密钥：两条链路都能操作同一账号的资产，
     * 只关一边会留下一个没人记得、也没有界面入口的全权凭据。
     */
    public function disable(User $user): void
    {
        $user->forceFill([
            'api_open' => 0,
            'api_username' => null,
            'api_password' => null,
            'api_ip_allowlist' => null,
            'api_expires_at' => null,
        ])->save();

        ApiKey::query()
            ->where('user_id', (int) $user->id)
            ->where('status', ApiKey::STATUS_ENABLED)
            ->update(['status' => ApiKey::STATUS_DISABLED, 'updated_at' => now()]);
    }

    /**
     * 重置密码（保留用户名与安全策略），明文仅本次返回一次。
     *
     * @return array<string, mixed>
     */
    public function resetPassword(User $user): array
    {
        $this->assertEnabled($user);

        $password = $this->generatePassword();
        $user->forceFill(['api_password' => Hash::make($password)])->save();

        return [
            'username' => (string) $user->api_username,
            'password' => $password,
            'login_url' => $this->loginUrl(),
        ];
    }

    /**
     * 更新安全策略（IP 白名单 / 有效期）。
     *
     * @param  array<string, mixed>  $payload
     */
    public function updatePolicy(User $user, array $payload): User
    {
        $this->assertEnabled($user);

        $user->forceFill($this->policy->fillableAttributes($payload))->save();

        return $user;
    }

    /**
     * 记录最近一次成功换取 JWT 的时间。
     *
     * 只在登录时调用，不在每个业务请求上写库：JWT 生命周期约 2 小时，
     * 逐请求回写 users 行会成为新的写放大来源。
     */
    public function touchLastUsed(User $user): void
    {
        $user->forceFill(['api_last_used_at' => now()])->saveQuietly();
    }

    private function assertEnabled(User $user): void
    {
        if ((int) $user->api_open !== 1 || trim((string) $user->api_username) === '') {
            throw new BusinessException('请先开启上游 API 接入', 42200);
        }
    }

    private function loginUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/v2/zjmf/zjmf_api_login';
    }

    /**
     * 生成唯一 API 用户名（唯一索引冲突时重试）。
     */
    private function generateUsername(User $user): string
    {
        if (trim((string) $user->api_username) !== '' && (int) $user->api_open === 1) {
            return (string) $user->api_username;
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $candidate = self::USERNAME_PREFIX.$user->id.'_'.Str::lower(Str::random(6));

            $exists = User::query()
                ->where('api_username', $candidate)
                ->whereKeyNot($user->getKey())
                ->exists();

            if (! $exists) {
                return $candidate;
            }
        }

        throw new BusinessException('API 用户名生成失败，请稍后重试', 42200);
    }

    private function generatePassword(): string
    {
        return Str::random(self::MIN_PASSWORD_LENGTH);
    }
}
