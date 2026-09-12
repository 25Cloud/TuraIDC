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
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

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
        } catch (Throwable $exception) {
            // ValidationException / 429 / 5xx 等非业务异常也要进审计：否则无效密钥爆破
            // 与高频滥用只有限流计数兜底，usage 日志里完全不可见。记录后原样抛出，
            // 由全局异常处理器统一渲染响应。
            $this->recordUsage($apiKey, $request, $this->statusCodeForException($exception), $ip, $startedAt);

            throw $exception;
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

    private function statusCodeForException(Throwable $exception): int
    {
        if ($exception instanceof ValidationException) {
            return 422;
        }
        if ($exception instanceof ThrottleRequestsException) {
            return 429;
        }
        if ($exception instanceof AuthenticationException) {
            return 401;
        }

        return 500;
    }

    private function recordUsage(?ApiKey $key, Request $request, int $statusCode, string $ip, float $startedAt): void
    {
        try {
            // 认证失败的请求没有可用密钥，用 0 哨兵落库：审计可见「谁在哪个 IP 打了哪个路径」，
            // 这是识别无效密钥爆破的唯一依据；正常请求带真实 key 归属。
            $this->logs->record(
                $key ? (int) $key->id : 0,
                $key ? (int) $key->user_id : 0,
                (string) $request->method(),
                (string) $request->path(),
                $statusCode,
                $ip,
                (int) round((microtime(true) - $startedAt) * 1000),
            );
        } catch (Throwable $exception) {
            Log::warning('[open-api] 审计写入失败', ['error' => $exception->getMessage()]);
        }
    }
}
