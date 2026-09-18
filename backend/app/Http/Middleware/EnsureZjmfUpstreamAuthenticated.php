<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ZjmfUpstream\UpstreamCredentialPolicy;
use App\Services\ZjmfUpstream\ZjmfUpstreamService;
use App\Support\UpstreamJwt;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 上游服务商 API 认证：解析魔方财务透传的 Bearer JWT 并加载对应客户账号。
 *
 * 响应遵循魔方财务 commonCurl 约定：HTTP 层固定 200，业务状态放 body.status。
 * 401/403 语义不适用于本协议——jwt 失效返回 status=405 触发魔方财务强制重登。
 *
 * 逐请求的准入判定（账号状态 / 开关 / 有效期 / IP 白名单）委托给
 * UpstreamCredentialPolicy，与登录端点共用同一份判定。两边条件一旦不一致，
 * 就会出现「登录成功 → 业务请求 405 → 强制重登 → 再登录成功 → 再 405」的死循环。
 */
class EnsureZjmfUpstreamAuthenticated
{
    public function __construct(
        private readonly ZjmfUpstreamService $service,
        private readonly UpstreamCredentialPolicy $policy,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->service->enabled()) {
            return $this->unauthorized($request, 'disabled', 0, '上游 API 未开放');
        }

        $claims = $this->resolveClaims($request);
        if ($claims === []) {
            return $this->unauthorized($request, 'jwt_invalid', 0, 'JWT 无效或已过期');
        }

        $userId = (int) ($claims['uid'] ?? 0);
        $user = $userId > 0 ? User::query()->find($userId) : null;

        if (! $user instanceof User) {
            return $this->unauthorized($request, 'account_unavailable', $userId, '对接账号不可用');
        }

        // JWT 有效期（约 2 小时）内策略可能被改动：关闭对接、凭据过期、IP 白名单收紧。
        // 因此每个请求都要重新判定，不能只在登录时判一次。
        $reason = $this->policy->rejectionReason($user, (string) $request->ip());
        if ($reason !== '') {
            return $this->unauthorized($request, 'policy_rejected', $userId, $reason);
        }

        $request->setUserResolver(fn () => $user);
        $request->attributes->set('zjmf_upstream_user', $user);

        return $next($request);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveClaims(Request $request): array
    {
        $header = (string) $request->header('Authorization', '');
        if (! preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
            return [];
        }

        $claims = UpstreamJwt::decode(trim($matches[1]), $this->service->jwtKey());

        return is_array($claims) ? $claims : [];
    }

    /**
     * 405 触发魔方财务强制重登；细分原因并记日志，供对接双方定位持续 405 的问题。
     */
    private function unauthorized(Request $request, string $reason, int $userId, string $message): Response
    {
        Log::warning('[zjmf-upstream] 鉴权拒绝', [
            'reason' => $reason,
            'user_id' => $userId,
            'ip' => (string) $request->ip(),
            'method' => $request->method(),
            'path' => $request->path(),
        ]);

        return response()->json(['status' => 405, 'msg' => $message], 200);
    }
}
