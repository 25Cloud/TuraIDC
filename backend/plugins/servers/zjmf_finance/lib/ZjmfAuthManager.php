<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

use App\Exceptions\BusinessException;
use App\Models\Supplier;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use App\Services\Upstream\ProviderKey;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class ZjmfAuthManager
{
    private const DEFAULT_JWT_CACHE_TTL_SECONDS = 1800;

    private const MIN_JWT_CACHE_TTL_SECONDS = 300;

    private const MAX_JWT_CACHE_TTL_SECONDS = 7200;

    public function __construct(
        private readonly HostingPanelApiTransport $transport,
    ) {}

    public function login(Supplier $supplier): string
    {
        $cacheKey = $this->jwtCacheKey($supplier);
        $cachedJwt = trim((string) $this->jwtCache()->get($cacheKey, ''));

        if ($cachedJwt !== '') {
            return $cachedJwt;
        }

        $startedAt = microtime(true);
        $response = $this->loginResponse($supplier);
        $jwt = trim((string) ($response['jwt'] ?? ''));

        if ($jwt === '') {
            $this->safeLog('warning', '[ZJMF 财务接口] JWT响应缺少会话', [
                'supplier_id' => $supplier->id,
                'provider_key' => ProviderKey::ZJMF_FINANCE_API,
                'response_status' => $response['status'] ?? $response['code'] ?? null,
            ]);

            throw new BusinessException('供应商接口认证失败，请检查接口配置', 42200);
        }

        $ttlSeconds = $this->resolveJwtCacheTtlSeconds($jwt);
        $this->jwtCache()->put($cacheKey, $jwt, now()->addSeconds($ttlSeconds));

        $this->safeLog('info', '[ZJMF 财务接口] JWT缓存写入', [
            'supplier_id' => $supplier->id,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'cache_store' => $this->cacheStoreName(),
            'ttl_seconds' => $ttlSeconds,
            'duration_ms' => $this->elapsedMilliseconds($startedAt),
        ]);

        return $jwt;
    }

    public function refreshJwt(Supplier $supplier): string
    {
        $this->forget($supplier);

        $startedAt = microtime(true);
        $response = $this->loginResponse($supplier);
        $jwt = trim((string) ($response['jwt'] ?? ''));

        if ($jwt === '') {
            $this->safeLog('warning', '[ZJMF 财务接口] JWT刷新响应缺少会话', [
                'supplier_id' => $supplier->id,
                'provider_key' => ProviderKey::ZJMF_FINANCE_API,
                'response_status' => $response['status'] ?? $response['code'] ?? null,
            ]);

            throw new BusinessException('供应商接口认证刷新失败，请稍后重试', 42200);
        }

        $ttlSeconds = $this->resolveJwtCacheTtlSeconds($jwt);
        $this->jwtCache()->put($this->jwtCacheKey($supplier), $jwt, now()->addSeconds($ttlSeconds));

        $this->safeLog('info', '[ZJMF 财务接口] JWT强制刷新', [
            'supplier_id' => $supplier->id,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'cache_store' => $this->cacheStoreName(),
            'ttl_seconds' => $ttlSeconds,
            'duration_ms' => $this->elapsedMilliseconds($startedAt),
        ]);

        return $jwt;
    }

    public function loginResponse(Supplier $supplier): array
    {
        return $this->transport->request($supplier, 'POST', '/zjmf_api_login', [
            'username' => (string) $supplier->api_username,
            'password' => (string) $supplier->api_key,
        ]);
    }

    public function forget(Supplier $supplier): void
    {
        $this->jwtCache()->forget($this->jwtCacheKey($supplier));
    }

    public function forgetIfUnauthorizedResponse(Supplier $supplier, int $httpCode, mixed $decoded, ?string $jwt): void
    {
        if ($jwt === null || trim($jwt) === '') {
            return;
        }

        if ($httpCode === 401 || (is_array($decoded) && (int) ($decoded['status'] ?? $decoded['code'] ?? $decoded['status_code'] ?? 0) === 401)) {
            $this->forget($supplier);
        }
    }

    public function jwtCacheKey(Supplier $supplier): string
    {
        return 'upstream:'.ProviderKey::ZJMF_FINANCE_API.':jwt:'.$supplier->id;
    }

    private function jwtCache(): CacheRepository
    {
        $store = $this->cacheStoreName();

        try {
            return Cache::store($store !== '' ? $store : config('cache.default', 'redis'));
        } catch (\Throwable $exception) {
            $this->safeLog('warning', '[ZJMF 财务接口] JWT缓存仓库不可用，回退默认缓存仓库', [
                'store' => $store,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return Cache::store(config('cache.default', 'file'));
        }
    }

    private function cacheStoreName(): string
    {
        $hostingConfig = config('idc.hosting_panel_api', []);

        return trim((string) (
            (is_array($hostingConfig) ? ($hostingConfig['jwt_cache_store'] ?? null) : null)
            ?? 'redis'
        )) ?: 'redis';
    }

    private function resolveJwtCacheTtlSeconds(string $jwt): int
    {
        $payload = $this->decodeJwtPayload($jwt);
        $expiresAt = (int) ($payload['exp'] ?? 0);

        if ($expiresAt <= 0) {
            return self::DEFAULT_JWT_CACHE_TTL_SECONDS;
        }

        // 剩余有效期再减 MIN_JWT_CACHE_TTL_SECONDS 安全余量，避免缓存即将过期/已过期的会话；
        // 剩余不足时返回 0（不缓存），让下一个请求重新登录拿新 JWT，
        // 而不是把失效 token 缓存至少 MIN 秒导致必然先 401 再重登。
        $ttlSeconds = $expiresAt - time() - self::MIN_JWT_CACHE_TTL_SECONDS;

        if ($ttlSeconds <= 0) {
            return 0;
        }

        return min($ttlSeconds, self::MAX_JWT_CACHE_TTL_SECONDS);
    }

    private function decodeJwtPayload(string $jwt): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) < 2) {
            return [];
        }

        $decoded = json_decode($this->decodeBase64Url($parts[1]), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function decodeBase64Url(string $value): string
    {
        $remainder = strlen($value) % 4;
        if ($remainder > 0) {
            $value .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? '' : $decoded;
    }

    private function safeLog(string $level, string $message, array $context = []): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (\Throwable) {
        }
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
