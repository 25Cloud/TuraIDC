<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\ZjmfBridge\Http\Middleware;

use App\Models\User;
use TuraIDC\Plugins\Addons\ZjmfBridge\Services\ZjmfResponseFactory;
use TuraIDC\Plugins\Addons\ZjmfBridge\Services\ZjmfTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateZjmfClient
{
    public function __construct(
        private readonly ZjmfTokenService $tokens,
        private readonly ZjmfResponseFactory $responses,
    ) {}

    public function handle(Request $request, Closure $next, ?string $scope = null): Response
    {
        $payload = $this->tokens->verify($this->bearerToken($request));
        if (! is_array($payload)) {
            return $this->responses->error(401, '未登录或登录已过期');
        }

        if (! $this->scopeAllowed($payload, $scope)) {
            return $this->responses->error(403, '接口 scope 未授权');
        }

        $user = User::query()->with(['account', 'memberLevel'])->find((int) ($payload['uid'] ?? 0));
        if (! $user instanceof User || (int) $user->status !== 1) {
            return $this->responses->error(401, '未登录或登录已过期');
        }

        $request->attributes->set('zjmf_actor_type', 'client');
        $request->attributes->set('zjmf_user_id', (int) $user->id);
        $request->attributes->set('zjmf_user', $user);
        $request->attributes->set('zjmf_scope', $scope);
        $request->setUserResolver(fn (): User => $user);

        return $next($request);
    }

    private function bearerToken(Request $request): string
    {
        $authorization = trim((string) $request->headers->get('Authorization', ''));
        if ($authorization === '') {
            return '';
        }

        if (preg_match('/^(?:JWT|Bearer)\s+(.+)$/i', $authorization, $matches) !== 1) {
            return '';
        }

        return trim((string) $matches[1]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function scopeAllowed(array $payload, ?string $scope): bool
    {
        if ($scope === null || $scope === '') {
            return true;
        }

        $scopes = $payload['scope'] ?? [];
        if (is_string($scopes)) {
            $scopes = array_filter(array_map('trim', explode(',', $scopes)));
        }

        return is_array($scopes) && in_array($scope, $scopes, true);
    }
}
