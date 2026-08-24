<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\BusinessException;
use App\Models\ApiKey;
use App\Models\User;
use App\Services\OpenApi\ApiKeyService;
use App\Services\OpenApi\ApiKeyUsageLogService;
use App\Services\OpenApi\OpenApiConfig;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiKey
{
    public function __construct(
        private readonly ApiKeyService $keys,
        private readonly ApiKeyUsageLogService $logs,
        private readonly OpenApiConfig $config,
    ) {}

    /**
     * 中间件参数为所需 scope，如 `api.key:services,write`；不传则仅认证不校验业务域。
     *
     * @param  string  $domain
     * @param  string  $level
     */
    public function handle(Request $request, Closure $next, string $domain = '', string $level = 'read'): Response
    {
        $startedAt = microtime(true);
        $ip = (string) $request->ip();
        $apiKey = null;

        try {
            if (! $this->config->enabled()) {
                throw new BusinessException('开放接口已关闭', 40300, 403);
            }

            $secret = $this->extractBearerToken($request);
            $key = $this->keys->resolve($secret);

            if ($domain !== '' && $level !== '') {
                $this->keys->assertScope($key, $domain, $level);
            }
            $this->keys->assertIpAllowed($key, $ip);

            $user = User::query()->find((int) $key->user_id);
            if (! $user || (int) $user->status !== 1) {
                throw new BusinessException('密钥所属账号不可用', 40300, 403);
            }

            $apiKey = $key;
            $request->attributes->set('api_key', $key);
            $request->attributes->set('api_key_user', $user);
            $request->setUserResolver(fn () => $user);

            $this->keys->touchLastUsed($key);

            $response = $next($request);
            $this->recordUsage($apiKey, $request, $response->getStatusCode(), $ip, $startedAt);

            return $response;
        } catch (BusinessException $exception) {
            $response = $exception->render();
            $this->recordUsage($apiKey, $request, $response->getStatusCode(), $ip, $startedAt);

            return $response;
        }
    }

    private function extractBearerToken(Request $request): string
    {
        $header = (string) $request->header('Authorization', '');
        if (preg_match('/Bearer\s+(.+)/i', $header, $matches)) {
            return trim($matches[1]);
        }

        return '';
    }

    private function recordUsage(?ApiKey $key, Request $request, int $statusCode, string $ip, float $startedAt): void
    {
        if (! $key) {
            return;
        }

        try {
            $this->logs->record(
                (int) $key->id,
                (int) $key->user_id,
                (string) $request->method(),
                (string) $request->path(),
                $statusCode,
                $ip,
                (int) (microtime(true) - $startedAt) * 1000,
            );
        } catch (\Throwable $exception) {
            Log::warning('[open-api] 审计写入失败', ['error' => $exception->getMessage()]);
        }
    }
}
