<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\ZjmfUpstream\ZjmfUpstreamService;
use App\Support\UpstreamJwt;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 上游服务商 API 认证：解析魔方财务透传的 Bearer JWT 并加载对应客户账号。
 *
 * 响应遵循魔方财务 commonCurl 约定：HTTP 层固定 200，业务状态放 body.status。
 * 401/403 语义不适用于本协议——jwt 失效返回 status=405 触发魔方财务强制重登。
 */
class EnsureZjmfUpstreamAuthenticated
{
    public function __construct(
        private readonly ZjmfUpstreamService $service,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->service->enabled()) {
            return $this->unauthorized('上游 API 未开放');
        }

        $claims = $this->resolveClaims($request);
        $userId = (int) ($claims['uid'] ?? 0);

        $user = $userId > 0
            ? User::query()->where('id', $userId)->where('api_open', 1)->where('status', 1)->first()
            : null;

        if (! $user instanceof User) {
            return $this->unauthorized('鉴权失败或登录已过期');
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

    private function unauthorized(string $message): Response
    {
        return response()->json(['status' => 405, 'msg' => $message], 200);
    }
}
