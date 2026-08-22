<?php

declare(strict_types=1);

namespace App\Services\ClientServiceConsole;

use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Models\User;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\System\OperationLogService;
use App\Support\CacheKey;
use App\Support\PublicUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * VNC 子服务
 * 负责：getVncUrlForUser、resolveVncToken、resolvePublicVncTokenPayload
 *       及所有 VNC URL 解析辅助方法
 */
class ServiceVncService
{
    private const VNC_TOKEN_TTL_SECONDS = 600;

    private const VNC_RELAY_TOKEN_TTL_SECONDS = 60;

    public function __construct(
        private readonly OperationLogService $operationLogService,
        private readonly ServiceDetailService $detailService,
        private readonly ServiceTransformService $transformService,
    ) {}

    public function getVncUrlForUser(User $user, int $serviceId, array $context = []): array
    {
        $service = $this->detailService->findUserService($user, $serviceId, [
            'product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires',
            'product.productGroup.secondProductGroup.firstProductGroup',
            'product.supplier',
            'order:id,order_no,status,paid_at,created_at',
        ]);
        throw_if(! $this->transformService->canExecuteConsoleActions($service), new BusinessException('当前实例状态不支持该操作', 42200));

        try {
            [$runtime, $supplier, $hostId, $jwt] = $this->detailService->resolveUpstreamContext($service);
        } catch (BusinessException $exception) {
            throw $this->normalizeVncBusinessException($exception);
        }

        $response = $this->requestUpstreamVncUrl($runtime, $supplier, $hostId, $jwt);
        if ($this->shouldRetryWithFreshJwt($response)) {
            $freshJwt = $this->refreshVncJwt($runtime, $supplier, $serviceId, $hostId);
            if ($freshJwt !== '') {
                $response = $this->requestUpstreamVncUrl($runtime, $supplier, $hostId, $freshJwt);
            }
        }
        $this->assertVncSuccess($response);

        $payload = $this->detailService->extractPayload($response);
        $upstreamVncUrl = trim((string) ($payload['url'] ?? $payload['vnc'] ?? $payload['link'] ?? ''));

        if ($upstreamVncUrl === '') {
            throw new BusinessException('上游未返回VNC链接', 50000);
        }

        $novncBaseUrl = $this->resolveNoVncBaseUrl();

        $message = trim((string) ($response['msg'] ?? '')) ?: '获取VNC链接成功';
        $vncUrl = $upstreamVncUrl;
        $noVncCredentials = [];

        if ($novncBaseUrl !== '') {
            $vncParams = $this->extractVncParams($upstreamVncUrl);

            $this->safeLog('info', '[VNC] 解析结果', [
                'service_id' => $serviceId,
                'has_host' => isset($vncParams['host']) && $vncParams['host'] !== '',
                'has_port' => isset($vncParams['port']) && $vncParams['port'] > 0,
                'has_path' => isset($vncParams['path']) && $vncParams['path'] !== '',
                'encrypt' => $vncParams['encrypt'] ?? '(空)',
                'has_password' => isset($vncParams['password']) && $vncParams['password'] !== '',
            ]);

            if (! empty($vncParams)) {
                $vncParams = $this->withCachedVncCredentials($service, $vncParams);
                $noVncCredentials = $this->buildNoVncCredentialPayload($vncParams);

                $token = bin2hex(random_bytes(24));
                $tokenPayload = $vncParams;
                // Redis 不落明文 VNC 密码：密码仅经 vnc_credentials 返回给当前认证用户
                // 供本地 noVNC 认证，token 载荷只承载 relay 建连所需参数。
                unset($tokenPayload['password']);
                Cache::store('redis_volatile')->put(CacheKey::vncToken($token), array_merge($tokenPayload, [
                    'service_id' => $serviceId,
                    'allowed_origin' => $this->resolveAllowedVncOrigin(),
                    'single_use' => ($context['actor_type'] ?? 'client') !== 'admin',
                    'token_scope' => 'public',
                ]), now()->addSeconds(self::VNC_TOKEN_TTL_SECONDS));

                $viewerUrl = $this->resolveNoVncViewerUrl($novncBaseUrl);
                $queryParams = [
                    'token' => $token,
                    'service_id' => $serviceId,
                    'relay_path' => $this->resolveVncRelayPath(),
                ];

                // noVNC 固定由 console 域名托管，API 与 WebSocket 一律由 API 域名承载。
                $queryParams['api_base'] = $this->resolveVncApiBase();

                $vncUrl = $viewerUrl.(str_contains($viewerUrl, '?') ? '&' : '?').http_build_query($queryParams);
            } else {
                $this->safeLog('warning', '[VNC] noVNC地址已配置但上游链接解析失败，已拒绝返回上游链接', [
                    'service_id' => $serviceId,
                    'novnc_base_url' => $novncBaseUrl,
                ]);

                throw new BusinessException('VNC 中转参数解析失败，请联系管理员检查上游 VNC 地址格式', 50000);
            }
        }

        $this->operationLogService->writeServiceConsoleLog($service, 'service.console.vnc.get', [
            'category' => 'vnc',
            'summary' => '获取VNC链接',
            'host_id' => $hostId,
            'message' => $message,
        ], $context);

        $result = [
            'message' => $message,
            'url' => $vncUrl,
            'detail' => $this->transformService->transformDetail($service),
        ];

        if ($noVncCredentials !== []) {
            // 仅认证后的取链接接口返回给当前用户端页面，用于本地自动认证；
            // 公开 token 换取接口仍不得返回 VNC 密码或完整上游地址。
            $result['vnc_credentials'] = $noVncCredentials;
        }

        return $result;
    }

    public function previewVncToken(string $token): array
    {
        return $this->loadVncTokenParams($token, false);
    }

    public function resolveVncToken(string $token): array
    {
        return $this->loadVncTokenParams($token, true);
    }

    private function assertVncSuccess(array $response): void
    {
        try {
            $this->detailService->assertSuccess($response, '获取VNC链接');
        } catch (BusinessException $exception) {
            throw $this->normalizeVncBusinessException($exception);
        }
    }

    private function normalizeVncBusinessException(BusinessException $exception): BusinessException
    {
        $message = trim($exception->getMessage());
        if (stripos($message, 'account does not exist') !== false) {
            return new BusinessException('上游账号不存在，暂时无法打开 VNC 控制台，请联系管理员同步或修复上游实例账号。', 42200);
        }

        return $exception;
    }

    private function requestUpstreamVncUrl(object $runtime, mixed $supplier, int $hostId, string $jwt): array
    {
        if (is_callable([$runtime, 'getVncUrl'])) {
            return $runtime->getVncUrl($supplier, $hostId, $jwt);
        }

        return $runtime->post(
            $supplier,
            '/provision/default',
            ['func' => 'vnc', 'id' => $hostId],
            $jwt,
            ['content-type: application/x-www-form-urlencoded']
        );
    }

    private function shouldRetryWithFreshJwt(array $response): bool
    {
        $status = (int) ($response['status'] ?? $response['code'] ?? $response['status_code'] ?? 0);
        if ($status === 401) {
            return true;
        }

        $message = mb_strtolower(trim((string) ($response['msg'] ?? $response['message'] ?? '')));
        if ($message === '') {
            return false;
        }

        return str_contains($message, 'jwt')
            || str_contains($message, 'auth')
            || str_contains($message, 'unauthorized')
            || str_contains($message, 'token expired')
            || str_contains($message, 'session')
            || str_contains($message, '认证')
            || str_contains($message, '登录已过期')
            || str_contains($message, '登陆已过期');
    }

    private function refreshVncJwt(object $runtime, mixed $supplier, int $serviceId, int $hostId): string
    {
        if (! is_callable([$runtime, 'refreshJwt'])) {
            return '';
        }

        try {
            $jwt = trim((string) $runtime->refreshJwt($supplier));
        } catch (\Throwable $exception) {
            $this->safeLog('warning', '[VNC] 上游认证刷新失败', [
                'service_id' => $serviceId,
                'supplier_id' => (int) ($supplier->id ?? 0),
                'host_id' => $hostId,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return '';
        }

        if ($jwt !== '') {
            $this->safeLog('info', '[VNC] 上游认证已刷新，重试获取链接', [
                'service_id' => $serviceId,
                'supplier_id' => (int) ($supplier->id ?? 0),
                'host_id' => $hostId,
            ]);
        }

        return $jwt;
    }

    public function resolvePublicVncTokenPayload(string $token): array
    {
        $params = $this->consumeVncLaunchToken($token);
        $relayToken = bin2hex(random_bytes(24));
        $relayPayload = $params;
        // relay 建连仅需 host/port/path/encrypt/origin，不落明文 VNC 密码到 Redis。
        unset($relayPayload['password']);
        Cache::store('redis_volatile')->put(CacheKey::vncToken($relayToken), array_merge($relayPayload, [
            'token_scope' => 'relay',
            'single_use' => false,
            'public_token_hash' => hash('sha256', $token),
        ]), now()->addSeconds(self::VNC_RELAY_TOKEN_TTL_SECONDS));

        $this->safeLog('info', '[VNC] 公开 token 已换取 relay token', [
            'service_id' => (int) ($params['service_id'] ?? 0),
            'token_hash' => hash('sha256', $token),
            'relay_token_hash' => hash('sha256', $relayToken),
            'has_password' => trim((string) ($params['password'] ?? '')) !== '',
        ]);

        return [
            'token' => $relayToken,
            'service_id' => (int) ($params['service_id'] ?? 0),
            'relay_path' => $this->resolveVncRelayPath(),
            'username' => (string) ($params['username'] ?? ''),
            'target' => (string) ($params['target'] ?? ''),
            'expires_in' => self::VNC_RELAY_TOKEN_TTL_SECONDS,
        ];
    }

    private function withCachedVncCredentials(Service $service, array $vncParams): array
    {
        $legacy = (array) ($service->provision_data ?? []);
        $projection = app(PluginBindingResolver::class)->serviceProvisionProjection($service, includeSecrets: true);
        $provisionData = app(PluginBindingResolver::class)->sanitizeServiceProvisionData(
            $projection === [] ? $legacy : array_replace($legacy, $projection)
        );
        $cachedConnection = $this->transformService->readCachedConnection($provisionData);

        if (trim((string) ($vncParams['username'] ?? '')) === '') {
            $username = trim((string) ($cachedConnection['username'] ?? ''));
            if ($username !== '') {
                $vncParams['username'] = $username;
            }
        }

        if (trim((string) ($vncParams['password'] ?? '')) === '') {
            $password = (string) ($cachedConnection['password'] ?? '');
            if ($password !== '') {
                $vncParams['password'] = $password;
            }
        }

        return $vncParams;
    }

    private function buildNoVncCredentialPayload(array $vncParams): array
    {
        $credentials = [];

        $username = trim((string) ($vncParams['username'] ?? ''));
        if ($username !== '') {
            $credentials['username'] = $username;
        }

        $target = trim((string) ($vncParams['target'] ?? ''));
        if ($target !== '') {
            $credentials['target'] = $target;
        }

        $password = trim((string) ($vncParams['password'] ?? ''));
        if ($password !== '') {
            $credentials['password'] = $password;
        }

        return $credentials;
    }

    private function safeLog(string $level, string $message, array $context = []): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (\Throwable) {
        }
    }

    private function loadVncTokenParams(string $token, bool $consumeSingleUse): array
    {
        $cacheKey = CacheKey::vncToken($token);
        $params = Cache::store('redis_volatile')->get($cacheKey);

        if (! is_array($params) || empty($params)) {
            throw new BusinessException('VNC 链接已过期或无效，请重新获取', 40400, 404);
        }

        throw_if(! is_array($params) || empty($params), new BusinessException('VNC 链接已过期或无效，请重新获取', 40400, 404));

        if ($consumeSingleUse && (bool) ($params['single_use'] ?? true)) {
            $params = Cache::store('redis_volatile')->pull(CacheKey::vncToken($token));
            if (! is_array($params) || empty($params)) {
                throw new BusinessException('VNC 链接已过期或无效，请重新获取', 40400, 404);
            }
            throw_if(! is_array($params) || empty($params), new BusinessException('VNC 链接已过期或无效，请重新获取', 40400, 404));
        }

        return $params;
    }

    // VNC URL 解析辅助方法

    private function consumeVncLaunchToken(string $token): array
    {
        $cacheKey = CacheKey::vncToken($token);
        $params = Cache::store('redis_volatile')->get($cacheKey);

        if (! is_array($params) || empty($params) || ($params['token_scope'] ?? 'public') !== 'public') {
            throw new BusinessException('VNC 链接已过期或无效，请重新获取', 40400, 404);
        }

        $params = Cache::store('redis_volatile')->pull(CacheKey::vncToken($token));
        if (! is_array($params) || empty($params)) {
            throw new BusinessException('VNC 链接已过期或无效，请重新获取', 40400, 404);
        }

        return $params;
    }

    private function resolveVncRelayPath(): string
    {
        $path = trim((string) config('idc.vnc_relay.path', '/ws/vnc'));
        if ($path === '') {
            return '/ws/vnc';
        }

        return str_starts_with($path, '/') ? $path : '/'.$path;
    }

    private function extractVncParams(string $upstreamUrl): array
    {
        $upstreamUrl = trim($upstreamUrl);
        if ($upstreamUrl === '') {
            return [];
        }

        $parsedUrl = parse_url($upstreamUrl);
        if (! is_array($parsedUrl)) {
            return [];
        }

        $queryStr = trim((string) ($parsedUrl['query'] ?? ''));
        $queryParams = [];
        if ($queryStr !== '') {
            parse_str($queryStr, $queryParams);
        }

        // 情形一：含 base64 WSS（ZJMF/美得云格式）
        $encodedWssUrl = trim((string) ($queryParams['url'] ?? ''));
        if ($encodedWssUrl !== '') {
            $wssUrl = base64_decode($encodedWssUrl, true);
            if ($wssUrl !== false && preg_match('#^wss?://#i', trim($wssUrl))) {
                return $this->parseWssUrlToParams(trim($wssUrl), $queryParams);
            }
        }

        // 情形二：上游 URL 本身是 WSS
        $scheme = strtolower(trim((string) ($parsedUrl['scheme'] ?? '')));
        if (in_array($scheme, ['ws', 'wss'], true)) {
            return $this->parseWssUrlToParams($upstreamUrl, $queryParams);
        }

        // 情形三：query 直接含 host
        if (isset($queryParams['host']) && $queryParams['host'] !== '') {
            return $this->normalizeUpstreamVncParams($queryParams);
        }

        // 情形四：直接 host:port
        $host = trim((string) ($parsedUrl['host'] ?? ''));
        $port = (int) ($parsedUrl['port'] ?? 0);
        if ($host !== '' && $port > 0) {
            return $this->normalizeUpstreamVncParams(['host' => $host, 'port' => $port, 'encrypt' => '0']);
        }

        return [];
    }

    private function parseWssUrlToParams(string $wssUrl, array $upstreamParams = []): array
    {
        $parsed = parse_url($wssUrl);
        if (! is_array($parsed)) {
            return [];
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? 'wss'));
        $host = trim((string) ($parsed['host'] ?? ''));
        $port = (int) ($parsed['port'] ?? ($scheme === 'wss' ? 443 : 80));
        $path = ltrim((string) ($parsed['path'] ?? ''), '/');
        $wssQuery = trim((string) ($parsed['query'] ?? ''));

        if ($host === '') {
            return [];
        }

        $novncPath = $path;
        if ($wssQuery !== '') {
            $novncPath .= '?'.$wssQuery;
        }

        $params = [
            'host' => $host,
            'port' => $port,
            'encrypt' => $scheme === 'wss' ? '1' : '0',
            'origin' => $this->buildWebsocketOrigin($host, $port, $scheme === 'wss'),
        ];

        if ($novncPath !== '') {
            $params['path'] = $novncPath;
        }

        return $this->normalizeUpstreamVncParams(array_merge($upstreamParams, $params));
    }

    private function normalizeUpstreamVncParams(array $params): array
    {
        $host = trim((string) ($params['host'] ?? ''));
        if ($host === '') {
            return [];
        }

        $normalized = ['host' => $host];

        $port = (int) ($params['port'] ?? 0);
        if ($port > 0) {
            $normalized['port'] = $port;
        }

        if (array_key_exists('encrypt', $params)) {
            $encrypt = $params['encrypt'];
            $normalized['encrypt'] = ($encrypt === true || in_array(strtolower(trim((string) $encrypt)), ['1', 'true', 'yes', 'on'], true))
                ? '1'
                : '0';
        }

        $path = ltrim(trim((string) ($params['path'] ?? '')), '/');
        if ($path !== '') {
            $normalized['path'] = $path;
        }

        $password = trim((string) ($params['password'] ?? $params['pass'] ?? ''));
        if ($password !== '') {
            $normalized['password'] = $password;
        }

        $username = trim((string) ($params['username'] ?? $params['user'] ?? ''));
        if ($username !== '') {
            $normalized['username'] = $username;
        }

        $target = trim((string) ($params['target'] ?? ''));
        if ($target !== '') {
            $normalized['target'] = $target;
        }

        $origin = trim((string) ($params['origin'] ?? ''));
        if ($origin === '') {
            $origin = $this->buildWebsocketOrigin(
                $host,
                $port > 0 ? $port : ((($normalized['encrypt'] ?? '0') === '1') ? 443 : 80),
                (($normalized['encrypt'] ?? '0') === '1')
            );
        }

        if ($origin !== '') {
            $normalized['origin'] = $origin;
        }

        return $normalized;
    }

    private function buildWebsocketOrigin(string $host, int $port, bool $secure): string
    {
        $host = trim($host);
        if ($host === '') {
            return '';
        }

        $scheme = $secure ? 'https' : 'http';
        $defaultPort = $secure ? 443 : 80;

        if ($port > 0 && $port !== $defaultPort) {
            return sprintf('%s://%s:%d', $scheme, $host, $port);
        }

        return sprintf('%s://%s', $scheme, $host);
    }

    private function resolveNoVncBaseUrl(): string
    {
        return PublicUrl::console('/vnc');
    }

    private function resolveNoVncViewerUrl(string $novncBaseUrl): string
    {
        $novncBaseUrl = trim($novncBaseUrl);
        if ($novncBaseUrl === '') {
            return '';
        }

        $parsedUrl = parse_url($novncBaseUrl);
        if ($parsedUrl === false) {
            return '';
        }

        $path = trim((string) ($parsedUrl['path'] ?? ''));
        if (preg_match('#/vnc(?:_auto)?\.html$#i', $path) === 1) {
            return rtrim($novncBaseUrl, '/');
        }

        return rtrim($novncBaseUrl, '/').'/vnc.html';
    }

    private function resolveVncApiBase(): string
    {
        return $this->resolvePublicApiBase();
    }

    private function resolveAllowedVncOrigin(): string
    {
        return PublicUrl::console();
    }

    private function resolvePublicApiBase(): string
    {
        return PublicUrl::api();
    }
}
