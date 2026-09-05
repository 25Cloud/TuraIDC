<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use App\Models\User;
use App\Support\UpstreamJwt;
use Illuminate\Support\Facades\Hash;

/**
 * 上游服务商 API 主服务（本系统被魔方财务作为上游对接）。
 *
 * 鉴权复用一个普通客户账号：管理端给客户开启 api_open 并配置
 * api_username/api_password 后，魔方财务用该账号调 /zjmf_api_login 换 JWT。
 */
class ZjmfUpstreamService
{
    /** JWT 有效期（秒），对齐魔方财务 createJwt 的 7200 */
    public const JWT_TTL = 7200;

    public function enabled(): bool
    {
        return (bool) config('services.zjmf_upstream.enabled', true);
    }

    public function jwtKey(): string
    {
        return (string) config('app.key');
    }

    /**
     * @return array<string, mixed> 失败返回 {status:400,msg}，成功返回 {jwt,status:200,msg}
     */
    public function login(string $username, string $password): array
    {
        if (! $this->enabled()) {
            return ['status' => 400, 'msg' => '上游 API 未开放'];
        }

        $username = trim($username);
        if ($username === '' || $password === '') {
            return ['status' => 400, 'msg' => '鉴权失败'];
        }

        // 与鉴权中间件保持同一账号条件（status=1 + api_open=1）：
        // 登录放行但中间件拒绝会形成「登录成功 → 每次请求 405 → 魔方财务强制重登 →
        // 再登录成功 → 再 405」的死循环，下游表现为对接持续 405。
        $user = User::query()
            ->where('status', 1)
            ->where('api_open', 1)
            ->where(function ($query) use ($username) {
                $query->where('api_username', $username)
                    ->orWhere('email', $username);
            })
            ->first();

        if (! $user instanceof User || ! Hash::check($password, (string) $user->api_password)) {
            return ['status' => 400, 'msg' => '鉴权失败'];
        }

        $now = time();
        $claims = [
            'uid' => (int) $user->id,
            'is_api' => 1,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + self::JWT_TTL,
        ];

        return [
            'jwt' => UpstreamJwt::encode($claims, $this->jwtKey()),
            'status' => 200,
            'msg' => '鉴权成功',
        ];
    }
}
