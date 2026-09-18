<?php

declare(strict_types=1);

namespace App\Services\ZjmfUpstream;

use App\Models\ApiKeyUsageLog;
use App\Models\User;
use App\Services\OpenApi\ApiKeyUsageLogService;
use App\Support\UpstreamJwt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 上游服务商 API 主服务（本系统被魔方财务作为上游对接）。
 *
 * 鉴权复用一个普通客户账号：控制台开启 api_open 并配置
 * api_username/api_password 后，魔方财务用该账号调 /zjmf_api_login 换 JWT。
 */
class ZjmfUpstreamService
{
    /** JWT 有效期（秒），对齐魔方财务 createJwt 的 7200 */
    public const JWT_TTL = 7200;

    public function __construct(
        private readonly UpstreamCredentialPolicy $policy,
        private readonly ApiKeyUsageLogService $logs,
        private readonly UpstreamApiCredentialService $credentials,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('services.zjmf_upstream.enabled', true);
    }

    public function jwtKey(): string
    {
        return (string) config('app.key');
    }

    /**
     * @param  string  $ip  调用方 IP，用于白名单判定（与鉴权中间件同一口径）
     * @return array<string, mixed> 失败返回 {status:400,msg}，成功返回 {jwt,status:200,msg}
     */
    public function login(string $username, string $password, string $ip = ''): array
    {
        if (! $this->enabled()) {
            return ['status' => 400, 'msg' => '上游 API 未开放'];
        }

        $username = trim($username);
        if ($username === '' || $password === '') {
            return ['status' => 400, 'msg' => '鉴权失败'];
        }

        $user = User::query()
            ->where(function ($query) use ($username) {
                $query->where('api_username', $username)
                    ->orWhere('email', $username);
            })
            ->first();

        // 密码校验先于策略判定，避免把「账号是否存在/是否开启接入」暴露给未通过鉴权的调用方。
        if (! $user instanceof User || ! Hash::check($password, (string) $user->api_password)) {
            return ['status' => 400, 'msg' => '鉴权失败'];
        }

        // 与鉴权中间件共用同一份准入判定（见 UpstreamCredentialPolicy）：
        // 登录放行但中间件拒绝会形成「登录成功 → 每次请求 405 → 魔方财务强制重登 →
        // 再登录成功 → 再 405」的死循环，下游表现为对接持续 405。
        $reason = $this->policy->rejectionReason($user, $ip);
        if ($reason !== '') {
            $this->recordLogin($user, $ip, 403);
            Log::warning('[zjmf-upstream] 登录被拒', [
                'reason' => $reason,
                'user_id' => (int) $user->id,
                'ip' => $ip,
            ]);

            return ['status' => 400, 'msg' => '鉴权失败：'.$reason];
        }

        $now = time();
        $claims = [
            'uid' => (int) $user->id,
            'is_api' => 1,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + self::JWT_TTL,
        ];

        $this->credentials->touchLastUsed($user);
        $this->recordLogin($user, $ip, 200);

        return [
            'jwt' => UpstreamJwt::encode($claims, $this->jwtKey()),
            'status' => 200,
            'msg' => '鉴权成功',
        ];
    }

    /**
     * 登录事件进审计：魔方链路此前没有任何调用留痕，出问题时无法回答
     * 「谁在哪个 IP 什么时候来换过 JWT」。
     */
    private function recordLogin(User $user, string $ip, int $statusCode): void
    {
        try {
            $this->logs->record(
                0,
                (int) $user->id,
                'POST',
                'api/v2/zjmf/zjmf_api_login',
                $statusCode,
                $ip,
                0,
                ApiKeyUsageLog::CHANNEL_ZJMF_UPSTREAM,
            );
        } catch (Throwable $exception) {
            Log::warning('[zjmf-upstream] 审计写入失败', ['error' => $exception->getMessage()]);
        }
    }
}
