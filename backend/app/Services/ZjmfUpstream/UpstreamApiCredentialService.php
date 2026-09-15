<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use App\Exceptions\BusinessException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * 用户自助管理「魔方财务上游 API」凭据（本系统作为上游被对接）。
 *
 * 与开放接口（/api/v2/open，api_keys 表 + Bearer API Key）是两套独立鉴权：
 * 本能力对应魔方财务上游协议（/api/v2/zjmf），凭据落在 users.api_open /
 * api_username / api_password，供魔方财务用 username+password 换 JWT。
 *
 * api_username 有唯一索引，重复时给出可读提示而不是数据库异常。
 */
class UpstreamApiCredentialService
{
    public const USERNAME_PREFIX = 'zjmf_';

    public const MIN_PASSWORD_LENGTH = 12;

    /**
     * 当前状态：开关、用户名、是否已设密码（绝不返回密码本身）。
     *
     * @return array{enabled:bool, username:string, has_password:bool, login_url:string}
     */
    public function status(User $user): array
    {
        return [
            'enabled' => (int) $user->api_open === 1,
            'username' => (string) ($user->api_username ?? ''),
            'has_password' => trim((string) ($user->api_password ?? '')) !== '',
            'login_url' => rtrim((string) config('app.url'), '/').'/api/v2/zjmf/zjmf_api_login',
        ];
    }

    /**
     * 开启 API 接入并生成新凭据（明文密码仅本次返回一次）。
     *
     * @return array{username:string, password:string, login_url:string}
     */
    public function enable(User $user): array
    {
        $username = $this->generateUsername($user);
        $password = $this->generatePassword();

        $user->forceFill([
            'api_open' => 1,
            'api_username' => $username,
            'api_password' => Hash::make($password),
        ])->save();

        return [
            'username' => $username,
            'password' => $password,
            'login_url' => rtrim((string) config('app.url'), '/').'/api/v2/zjmf/zjmf_api_login',
        ];
    }

    /**
     * 关闭 API 接入：立即失效（鉴权中间件按 api_open=1 过滤），并清空凭据。
     */
    public function disable(User $user): void
    {
        $user->forceFill([
            'api_open' => 0,
            'api_username' => null,
            'api_password' => null,
        ])->save();
    }

    /**
     * 重置密码（保留用户名），明文仅本次返回一次。
     *
     * @return array{username:string, password:string, login_url:string}
     */
    public function resetPassword(User $user): array
    {
        if ((int) $user->api_open !== 1 || trim((string) $user->api_username) === '') {
            throw new BusinessException('请先开启上游 API 接入', 42200);
        }

        $password = $this->generatePassword();
        $user->forceFill(['api_password' => Hash::make($password)])->save();

        return [
            'username' => (string) $user->api_username,
            'password' => $password,
            'login_url' => rtrim((string) config('app.url'), '/').'/api/v2/zjmf/zjmf_api_login',
        ];
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
