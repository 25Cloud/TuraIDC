<?php

declare(strict_types=1);

namespace App\Services\Upstream\Drivers\HostingPanelApi;

use App\Exceptions\BusinessException;
use App\Models\Supplier;
use App\Services\Upstream\Contracts\ProvidesConsoleAccess;
use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\Contracts\ProvidesConsoleNetwork;
use App\Services\Upstream\Contracts\ProvidesConsoleRuntime;
use App\Services\Upstream\Contracts\ProvidesConsoleSecurity;
use App\Services\Upstream\Contracts\ProvidesProvisioning;
use App\Services\Upstream\Contracts\ProvidesRenewal;
use App\Services\Upstream\Contracts\ProvidesScheduledAuthRefresh;
use App\Services\Upstream\Contracts\ProvidesStatusSync;
use App\Services\Upstream\Drivers\HostingPanelApi\Concerns\HandlesApiConfigOptions;
use App\Services\Upstream\Drivers\HostingPanelApi\Concerns\HandlesCatalogNormalization;
use App\Services\Upstream\ProviderKey;
use App\Services\Upstream\Support\CloudConfigTemplate;
use App\Services\Upstream\Support\WebSessionCookieParser;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class HostingPanelApiTransport implements ProvidesConsoleAccess, ProvidesConsoleCatalog, ProvidesConsoleNetwork, ProvidesConsoleRuntime, ProvidesConsoleSecurity, ProvidesProvisioning, ProvidesRenewal, ProvidesScheduledAuthRefresh, ProvidesStatusSync
{
    use HandlesApiConfigOptions, HandlesCatalogNormalization;

    private const DEFAULT_JWT_CACHE_TTL_SECONDS = 1800;

    private const MIN_JWT_CACHE_TTL_SECONDS = 300;

    private const MAX_JWT_CACHE_TTL_SECONDS = 7200;

    private const DNS_CACHE_TTL_SECONDS = 300;

    private const DEFAULT_DNS_RESOLVER_TIMEOUT_SECONDS = 3;

    private const CONFIG_OPTION_FIELD_MAP = [
        1 => null,
        2 => null,
        3 => null,
        4 => 'ip_num',
        5 => 'os',
        6 => 'cpu',
        7 => 'cpu',
        8 => 'memory',
        9 => 'memory',
        10 => 'bw',
        11 => 'bw',
        12 => 'area',
        13 => 'system_disk_size',
        14 => 'system_disk_size',
        15 => null,
        16 => 'cpu',
        17 => 'memory',
        18 => 'bw',
        19 => 'system_disk_size',
        20 => null,
    ];

    private const RANGE_OPTION_TYPES = [4, 7, 9, 11, 14, 15, 16, 17, 18, 19];

    private const CONFIG_PRICING_CYCLE_MAP = [
        'hour' => 'hour',
        'day' => 'day',
        'ontrial' => 'ontrial',
        'monthly' => 'monthly',
        'quarterly' => 'quarterly',
        'semiannually' => 'semiannually',
        'annually' => 'annually',
        'biennially' => 'biennially',
        'triennially' => 'triennially',
        'fourly' => 'fourly',
        'fively' => 'fively',
        'sixly' => 'sixly',
        'sevenly' => 'sevenly',
        'eightly' => 'eightly',
        'ninely' => 'ninely',
        'tenly' => 'tenly',
        'onetime' => 'one_time',
        'one_time' => 'one_time',
    ];

    private array $serviceConfig;

    public function __construct(
        private readonly ?WebSessionCookieParser $webSessionCookieParser = null,
    ) {
        $defaultConfig = config('idc.hosting_panel_api', []);
        $this->serviceConfig = [
            'user_agent' => (string) ($defaultConfig['user_agent'] ?? 'mozilla/5.0 (compatible; msie 5.01; windows nt 5.0)'),
            'ssl_verify' => $this->normalizeBoolean($defaultConfig['ssl_verify'] ?? true),
            'ca_bundle' => (string) ($defaultConfig['ca_bundle'] ?? ''),
            'allowed_hosts' => array_values(array_filter(array_map(
                static fn (string $item): string => strtolower(trim($item)),
                explode(',', (string) ($defaultConfig['allowed_hosts'] ?? ''))
            ))),
            'jwt_cache_store' => trim((string) ($defaultConfig['jwt_cache_store'] ?? 'redis')) ?: 'redis',
            'dns_resolver_timeout' => max(
                (int) ($defaultConfig['dns_resolver_timeout'] ?? self::DEFAULT_DNS_RESOLVER_TIMEOUT_SECONDS),
                1
            ),
            'timeout' => max((int) ($defaultConfig['timeout'] ?? 30), 1),
            'connect_timeout' => max((int) ($defaultConfig['connect_timeout'] ?? 10), 1),
        ];
    }

    /**
     * 使用供应商 API 账号登录上游主机面板，获取 JWT。
     */
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
            $this->safeLog('warning', '[主机面板接口] JWT响应缺少会话', [
                'supplier_id' => $supplier->id,
                'response' => $this->summarizeLogResponse($response),
            ]);

            throw new BusinessException('供应商接口认证失败，请检查接口配置', 42200);
        }

        $ttlSeconds = $this->resolveJwtCacheTtlSeconds($jwt);
        $this->jwtCache()->put($cacheKey, $jwt, now()->addSeconds($ttlSeconds));

        $this->safeLog('info', '[主机面板接口] JWT缓存写入', [
            'supplier_id' => $supplier->id,
            'cache_store' => $this->serviceConfig['jwt_cache_store'],
            'ttl_seconds' => $ttlSeconds,
            'duration_ms' => $this->elapsedMilliseconds($startedAt),
        ]);

        return $jwt;
    }

    /**
     * 强制刷新 JWT（忽略缓存，重新登录上游）。
     */
    public function refreshJwt(Supplier $supplier): string
    {
        $cacheKey = $this->jwtCacheKey($supplier);
        $this->jwtCache()->forget($cacheKey);

        $startedAt = microtime(true);
        $response = $this->loginResponse($supplier);
        $jwt = trim((string) ($response['jwt'] ?? ''));

        if ($jwt === '') {
            $this->safeLog('warning', '[主机面板接口] JWT刷新响应缺少会话', [
                'supplier_id' => $supplier->id,
                'response' => $this->summarizeLogResponse($response),
            ]);

            throw new BusinessException('供应商接口认证刷新失败，请稍后重试', 42200);
        }

        $ttlSeconds = $this->resolveJwtCacheTtlSeconds($jwt);
        $this->jwtCache()->put($cacheKey, $jwt, now()->addSeconds($ttlSeconds));

        $this->safeLog('info', '[主机面板接口] JWT强制刷新', [
            'supplier_id' => $supplier->id,
            'cache_store' => $this->serviceConfig['jwt_cache_store'],
            'ttl_seconds' => $ttlSeconds,
            'duration_ms' => $this->elapsedMilliseconds($startedAt),
        ]);

        return $jwt;
    }

    /**
     * 使用供应商 API 账号登录上游主机面板，返回完整响应体。
     */
    public function loginResponse(Supplier $supplier): array
    {
        return $this->request($supplier, 'POST', '/v1/login_api', [
            'account' => (string) $supplier->api_username,
            'password' => (string) $supplier->api_key,
        ]);
    }

    /**
     * 获取上游账号基础资料。
     */
    public function getUserProfile(Supplier $supplier): array
    {
        $response = $this->get($supplier, '/v1/user', $this->login($supplier));
        $data = $response['data'] ?? null;

        if (! is_array($data) || ! isset($data['client']) || ! is_array($data['client'])) {
            throw new BusinessException((string) ($response['msg'] ?? '获取会员基础资料失败'), 42200);
        }

        return $data;
    }

    /**
     * 获取上游账号余额。
     */
    public function getBalance(Supplier $supplier): array
    {
        $response = $this->getUserProfile($supplier);
        $client = $response['client'];

        return [
            'balance' => (string) ($client['credit'] ?? '0.00'),
            'client' => $client,
            'country' => is_array($response['country'] ?? null) ? $response['country'] : [],
        ];
    }

    public function getHostRenewInfo(Supplier $supplier, int $hostId, ?string $billingCycle = null): array
    {
        $jwt = $this->login($supplier);
        $query = [];

        if ($billingCycle !== null && trim($billingCycle) !== '') {
            $query['billingcycle'] = trim($billingCycle);
        }

        return $this->get($supplier, "/v1/hosts/{$hostId}/renew", $jwt, $query);
    }

    public function renewHost(Supplier $supplier, int $hostId, string $billingCycle): array
    {
        $jwt = $this->login($supplier);

        return $this->post($supplier, "/v1/hosts/{$hostId}/renew", [
            'billingcycle' => trim($billingCycle),
        ], $jwt);
    }

    public function getHostDetail(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        $resolvedJwt = $jwt !== null && trim($jwt) !== ''
            ? trim($jwt)
            : $this->login($supplier);

        return $this->get($supplier, "/v1/hosts/{$hostId}", $resolvedJwt);
    }

    public function getHostUpgradeConfigOptions(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        $resolvedJwt = $jwt !== null && trim($jwt) !== ''
            ? trim($jwt)
            : $this->login($supplier);
        $response = $this->get($supplier, "/v1/hosts/{$hostId}/actions/upgradeconfig", $resolvedJwt);
        $payload = $this->extractResponsePayload($response);

        return [
            'response' => $response,
            'payload' => $payload,
            'currency' => is_array($payload['currency'] ?? null) ? $payload['currency'] : [],
            'options' => $this->normalizeHostUpgradeConfigOptions(
                is_array($payload['host'] ?? null) ? $payload['host'] : []
            ),
        ];
    }

    public function previewHostConfigUpgrade(Supplier $supplier, int $hostId, array $configOption, ?string $jwt = null): array
    {
        $resolvedJwt = $jwt !== null && trim($jwt) !== ''
            ? trim($jwt)
            : $this->login($supplier);

        return $this->post($supplier, "/v1/hosts/{$hostId}/actions/upgradeconfig", [
            'configoption' => $configOption,
        ], $resolvedJwt);
    }

    public function checkoutHostConfigUpgrade(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        $resolvedJwt = $jwt !== null && trim($jwt) !== ''
            ? trim($jwt)
            : $this->login($supplier);

        return $this->post($supplier, "/v1/hosts/{$hostId}/actions/upgradeconfig/checkout", [], $resolvedJwt);
    }

    public function getHostUpgradePromoPreview(Supplier $supplier, int $hostId, string $promoCode, ?string $jwt = null): array
    {
        $resolvedJwt = $jwt !== null && trim($jwt) !== ''
            ? trim($jwt)
            : $this->login($supplier);

        return $this->put($supplier, "/v1/hosts/{$hostId}/actions/upgradeconfig/promo", [
            'promo_code' => trim($promoCode),
        ], $resolvedJwt);
    }

    public function removeHostUpgradePromoCode(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        $resolvedJwt = $jwt !== null && trim($jwt) !== ''
            ? trim($jwt)
            : $this->login($supplier);

        return $this->request($supplier, 'DELETE', "/v1/hosts/{$hostId}/actions/upgradeconfig/promo", [], $resolvedJwt);
    }

    public function getHostUpgradeOptions(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        $resolvedJwt = $jwt !== null && trim($jwt) !== ''
            ? trim($jwt)
            : $this->login($supplier);

        return $this->get($supplier, "/v1/hosts/{$hostId}/actions/upgrade", $resolvedJwt);
    }

    public function previewHostUpgrade(Supplier $supplier, int $hostId, int $productId, string $billingCycle, ?string $jwt = null): array
    {
        $resolvedJwt = $jwt !== null && trim($jwt) !== ''
            ? trim($jwt)
            : $this->login($supplier);

        return $this->post($supplier, "/v1/hosts/{$hostId}/actions/upgrade", [
            'product_id' => $productId,
            'billingcycle' => trim($billingCycle),
        ], $resolvedJwt);
    }

    public function applyHostUpgradePromoCode(Supplier $supplier, int $hostId, string $promoCode, ?string $jwt = null): array
    {
        $resolvedJwt = $jwt !== null && trim($jwt) !== ''
            ? trim($jwt)
            : $this->login($supplier);

        return $this->put($supplier, "/v1/hosts/{$hostId}/actions/upgrade/promo", [
            'promo_code' => trim($promoCode),
        ], $resolvedJwt);
    }

    public function checkoutHostUpgrade(Supplier $supplier, int $hostId, ?string $jwt = null): array
    {
        $resolvedJwt = $jwt !== null && trim($jwt) !== ''
            ? trim($jwt)
            : $this->login($supplier);

        return $this->post($supplier, "/v1/hosts/{$hostId}/actions/upgrade/checkout", [], $resolvedJwt);
    }

    /**
     * 通过上游客户端面板的 servicedetail?action=flowpacket 页面购买流量包。
     * 部分上游仍保留 /dcim/buy_flow_packet 接口，因此页面动作失败时保留旧接口回退。
     */
    public function buyFlowPacket(Supplier $supplier, string $rootUrl, int $flowPacketId, int $hostId, ?string $jwt = null): array
    {
        $resolvedJwt = $jwt !== null && trim($jwt) !== '' ? trim($jwt) : '';
        $headers = [];

        if ($resolvedJwt === '') {
            $webSessionCookie = $this->resolveSupplierWebSessionCookie($supplier);
            if ($webSessionCookie !== '') {
                $headers[] = 'Cookie: '.$webSessionCookie;
            } else {
                $resolvedJwt = $this->login($supplier);
            }
        }

        $rootUrl = rtrim($rootUrl, '/');
        $payload = [
            'flow_packet_id' => $flowPacketId,
            'service_id' => $hostId,
            'id' => $hostId,
        ];

        $pageUrl = $rootUrl.'/servicedetail?'.http_build_query([
            'id' => $hostId,
            'action' => 'flowpacket',
        ]);

        $pageResponse = $this->post($supplier, $pageUrl, $payload, $resolvedJwt !== '' ? $resolvedJwt : null, $headers);
        if ((int) ($pageResponse['code'] ?? -1) === 0) {
            return $pageResponse;
        }

        $url = $rootUrl.'/dcim/buy_flow_packet';

        $legacyResponse = $this->post($supplier, $url, $payload, $resolvedJwt !== '' ? $resolvedJwt : null, $headers);
        if ((int) ($legacyResponse['code'] ?? -1) === 0) {
            return $legacyResponse;
        }

        return $pageResponse;
    }

    public function post(Supplier $supplier, string $uri, array|string $payload = [], ?string $jwt = null, array $headers = [], array $query = []): array
    {
        return $this->request($supplier, 'POST', $uri, $payload, $jwt, $headers, $query);
    }

    public function get(Supplier $supplier, string $uri, ?string $jwt = null, array $query = [], array $headers = []): array
    {
        return $this->request($supplier, 'GET', $uri, [], $jwt, $headers, $query);
    }

    public function getText(Supplier $supplier, string $uri, ?string $jwt = null, array $query = [], array $headers = []): string
    {
        return $this->requestText($supplier, 'GET', $uri, [], $jwt, $headers, $query);
    }

    public function parallelGet(Supplier $supplier, array $requests, ?string $jwt = null, array $headers = []): array
    {
        $baseUrl = trim((string) $supplier->api_url);

        if ($baseUrl === '') {
            throw new BusinessException('供应商接口地址未配置', 42200);
        }

        $jwt = $jwt !== null && trim($jwt) !== ''
            ? trim($jwt)
            : $this->login($supplier);
        $requestHeaders = $this->buildHeaders($jwt, $headers);
        $headerMap = $this->buildHeaderMap($requestHeaders);
        $httpOptions = $this->buildHttpClientOptions();
        $requestsByAlias = [];

        foreach ($requests as $key => $request) {
            $rawAlias = is_string($key) ? $key : (string) ($request['key'] ?? $key);
            $alias = (string) $rawAlias;
            $query = is_array($request['query'] ?? null) ? $request['query'] : [];
            $urlsAlias = $this->normalizePoolAlias($alias);
            $requestsByAlias[$alias] = [
                'pool_alias' => $urlsAlias,
                'url' => $this->buildUrl($baseUrl, (string) ($request['uri'] ?? ''), $query),
                'connect_timeout' => max((int) ($request['connect_timeout'] ?? $this->serviceConfig['connect_timeout']), 1),
                'timeout' => max((int) ($request['timeout'] ?? $this->serviceConfig['timeout']), 1),
            ];
        }

        $startedAt = microtime(true);
        $responses = Http::pool(function (Pool $pool) use ($requestsByAlias, $headerMap, $httpOptions) {
            $pendingRequests = [];

            foreach ($requestsByAlias as $requestMeta) {
                $request = $pool->as($requestMeta['pool_alias'])
                    ->withHeaders($headerMap)
                    ->connectTimeout((int) $requestMeta['connect_timeout'])
                    ->timeout((int) $requestMeta['timeout'])
                    ->withOptions($httpOptions);

                if ($this->serviceConfig['user_agent'] !== '') {
                    $request = $request->withUserAgent($this->serviceConfig['user_agent']);
                }

                $pendingRequests[] = $request->get($requestMeta['url']);
            }

            return $pendingRequests;
        });

        $normalized = [];
        $errors = [];
        $statusCodes = [];

        foreach ($requestsByAlias as $alias => $requestMeta) {
            $response = $responses[$requestMeta['pool_alias']] ?? null;
            $decoded = [];
            $error = '';
            $status = 0;
            $contentType = '';

            if ($response instanceof Response) {
                $status = $response->status();
                $body = trim((string) $response->body(), "\xEF\xBB\xBF");
                $contentType = trim((string) $response->header('Content-Type', ''));
                $decoded = json_decode($body, true);

                if (! is_array($decoded)) {
                    $decoded = [];
                    $error = $this->buildInvalidJsonMessage($status, $contentType, $body);
                }
            } elseif ($response instanceof \Throwable) {
                $error = '并发请求连接失败：'.$response->getMessage();
            } else {
                $error = '并发请求未返回有效响应';
            }

            if ($status === 401 && $jwt !== null && trim($jwt) !== '') {
                $this->forgetJwtCache($supplier);
            }

            $normalized[$alias] = [
                'status_code' => $status,
                'response' => $decoded,
                'error' => $error,
                'content_type' => $contentType,
            ];
            $statusCodes[$alias] = $status;
            if ($error !== '') {
                $errors[$alias] = $error;
            }
        }

        $totalMs = $this->elapsedMilliseconds($startedAt);
        $logContext = [
            'supplier_id' => $supplier->id,
            'count' => count($requestsByAlias),
            'duration_ms' => $totalMs,
            'status_codes' => $statusCodes,
        ];

        return $normalized;
    }

    private function normalizePoolAlias(string $alias): string
    {
        $trimmed = trim($alias);

        if ($trimmed === '') {
            return 'request_'.bin2hex(random_bytes(4));
        }

        return preg_match('/^\d+$/', $trimmed) === 1
            ? 'request_'.$trimmed
            : $trimmed;
    }

    public function put(Supplier $supplier, string $uri, array|string $payload = [], ?string $jwt = null, array $headers = [], array $query = []): array
    {
        return $this->request($supplier, 'PUT', $uri, $payload, $jwt, $headers, $query);
    }

    public function delete(Supplier $supplier, string $uri, array|string $payload = [], ?string $jwt = null, array $headers = [], array $query = []): array
    {
        return $this->request($supplier, 'DELETE', $uri, $payload, $jwt, $headers, $query);
    }

    public function requestText(
        Supplier $supplier,
        string $method,
        string $uri,
        array|string $payload = [],
        ?string $jwt = null,
        array $headers = [],
        array $query = []
    ): string {
        $baseUrl = trim((string) $supplier->api_url);

        if ($baseUrl === '') {
            throw new BusinessException('供应商接口地址未配置', 42200);
        }

        $jwt = $this->resolveRequestJwt($supplier, $jwt, $uri, $headers);
        $method = strtoupper(trim($method));
        $url = $this->buildUrl(
            $baseUrl,
            $uri,
            $method === 'GET' ? array_merge($query, is_array($payload) ? $payload : []) : $query
        );
        $requestHeaders = $this->buildHeaders($jwt, $headers);
        $body = $this->buildRequestBody($method, $payload);

        $this->applyUserAgent();

        $startedAt = microtime(true);
        $context = stream_context_create($this->buildContextOptions($method, $requestHeaders, $body));
        error_clear_last();
        $output = @file_get_contents($url, false, $context);
        $lastError = error_get_last();
        $responseHeaders = is_array($http_response_header) ? $http_response_header : [];
        $httpCode = $this->resolveHttpCode($responseHeaders);

        if ($output === false) {
            $this->safeLog('error', '[主机面板接口] 文本接口请求失败', [
                'supplier_id' => $supplier->id,
                'method' => $method,
                'url' => $this->maskUrlForLog($url),
                'http_code' => $httpCode,
                'duration_ms' => $this->elapsedMilliseconds($startedAt),
                'error' => $lastError['message'] ?? 'unknown error',
            ]);

            throw new BusinessException('供应商接口请求失败，请稍后重试或联系管理员', 50000);
        }

        if ($this->shouldForgetJwtCacheForResponse($httpCode, null, $jwt)) {
            $this->forgetJwtCache($supplier);
        }

        $this->safeLog('info', '[主机面板接口] 文本接口响应', [
            'supplier_id' => $supplier->id,
            'method' => $method,
            'url' => $this->maskUrlForLog($url),
            'http_code' => $httpCode,
            'duration_ms' => $this->elapsedMilliseconds($startedAt),
            'response_preview' => $this->truncateLogValue(trim((string) $output, "\xEF\xBB\xBF")),
        ]);

        return (string) $output;
    }

    /**
     * 发送主机面板接口请求。
     */
    public function request(
        Supplier $supplier,
        string $method,
        string $uri,
        array|string $payload = [],
        ?string $jwt = null,
        array $headers = [],
        array $query = []
    ): array {
        return $this->requestWithMeta($supplier, $method, $uri, $payload, $jwt, $headers, $query)['response'];
    }

    /**
     * 发送主机面板接口请求，并返回响应头等元信息。
     *
     * @return array{response: array, headers: array<int, string>, http_code: int, content_type: string}
     */
    public function requestWithMeta(
        Supplier $supplier,
        string $method,
        string $uri,
        array|string $payload = [],
        ?string $jwt = null,
        array $headers = [],
        array $query = []
    ): array {
        $baseUrl = trim((string) $supplier->api_url);

        if ($baseUrl === '') {
            throw new BusinessException('供应商接口地址未配置', 42200);
        }

        $jwt = $this->resolveRequestJwt($supplier, $jwt, $uri, $headers);
        $method = strtoupper(trim($method));
        $url = $this->buildUrl(
            $baseUrl,
            $uri,
            $method === 'GET' ? array_merge($query, is_array($payload) ? $payload : []) : $query
        );
        $requestHeaders = $this->buildHeaders($jwt, $headers);
        $body = $this->buildRequestBody($method, $payload);

        $this->applyUserAgent();

        $startedAt = microtime(true);
        $context = stream_context_create($this->buildContextOptions($method, $requestHeaders, $body));
        error_clear_last();
        $output = @file_get_contents($url, false, $context);
        $lastError = error_get_last();
        $responseHeaders = is_array($http_response_header) ? $http_response_header : [];
        $httpCode = $this->resolveHttpCode($responseHeaders);
        $contentType = $this->resolveHeaderValue($responseHeaders, 'Content-Type');

        // WAF 挑战页自动会话：部分上游（如 idcxl 系）在无 cookie 时返回
        // <script>document.cookie = 'xxx_token=...; path=/'</script> 形式的 JS 挑战页，
        // 携带该 cookie 重试一次即可正常返回 JSON。仅重试一次，避免死循环。
        // 重试前提收紧为“WAF 标记 + 典型状态码”双条件：
        // - extractChallengeCookie 命中 document.cookie 赋值语句（WAF JS 挑战页强特征，
        //   业务异常页不会携带该标记），说明请求被 WAF 拦截在业务处理之前；
        // - 状态码仅 200/403（挑战页典型响应），排除 4xx/5xx 业务错误页被误重放。
        if ($output !== false
            && ! is_array(json_decode($output, true))
            && in_array($httpCode, [200, 403], true)
            && $this->looksLikeHtmlResponse((string) $output, $contentType)
            && ! $this->hasCookieHeader($requestHeaders)
        ) {
            $challengeCookie = $this->extractChallengeCookie((string) $output);
            if ($challengeCookie !== '') {
                $this->safeLog('info', '[主机面板接口] 检测到 WAF 挑战页，携带会话 cookie 重试', [
                    'supplier_id' => $supplier->id,
                    'method' => $method,
                    'url' => $this->maskUrlForLog($url),
                    'cookie_name' => $this->maskChallengeCookieName($challengeCookie),
                ]);

                $retryHeaders = [...$requestHeaders, 'Cookie: '.$challengeCookie];
                $retryContext = stream_context_create($this->buildContextOptions($method, $retryHeaders, $body));
                error_clear_last();
                $retryOutput = @file_get_contents($url, false, $retryContext);
                $retryHeadersRaw = is_array($http_response_header) ? $http_response_header : [];

                if ($retryOutput !== false) {
                    $output = $retryOutput;
                    $responseHeaders = $retryHeadersRaw;
                    $httpCode = $this->resolveHttpCode($responseHeaders);
                    $contentType = $this->resolveHeaderValue($responseHeaders, 'Content-Type');
                }
            }
        }

        if ($output === false) {
            $this->safeLog('error', '[主机面板接口] 接口请求失败', [
                'supplier_id' => $supplier->id,
                'method' => $method,
                'url' => $this->maskUrlForLog($url),
                'http_code' => $httpCode,
                'duration_ms' => $this->elapsedMilliseconds($startedAt),
                'error' => $lastError['message'] ?? 'unknown error',
            ]);

            throw new BusinessException('供应商接口请求失败，请稍后重试或联系管理员', 50000);
        }

        $output = trim($output, "\xEF\xBB\xBF");
        $decoded = json_decode($output, true);

        if ($this->shouldForgetJwtCacheForResponse($httpCode, $decoded, $jwt)) {
            $this->forgetJwtCache($supplier);
        }

        $this->safeLog('info', '[主机面板接口] 接口响应', [
            'supplier_id' => $supplier->id,
            'method' => $method,
            'url' => $this->maskUrlForLog($url),
            'http_code' => $httpCode,
            'duration_ms' => $this->elapsedMilliseconds($startedAt),
            'response' => is_array($decoded) ? $this->summarizeLogResponse($decoded) : $this->truncateLogValue($output),
        ]);

        if (! is_array($decoded)) {
            throw new BusinessException($this->buildInvalidJsonMessage($httpCode, $contentType, $output), 50000);
        }

        return [
            'response' => $decoded,
            'headers' => $responseHeaders,
            'http_code' => $httpCode,
            'content_type' => $contentType,
        ];
    }

    /**
     * 从 WAF JS 挑战页中提取会话 cookie（如 document.cookie = 'yxd_token=xxx; path=/'）。
     */
    private function extractChallengeCookie(string $body): string
    {
        if (preg_match("/document\.cookie\s*=\s*['\"]?([A-Za-z0-9_\-]+)=([^;'\"\s]+)/", $body, $matches) === 1) {
            $name = trim((string) $matches[1]);
            $value = trim((string) $matches[2]);

            if ($name !== '' && $value !== '') {
                return $name.'='.$value;
            }
        }

        return '';
    }

    private function maskChallengeCookieName(string $cookie): string
    {
        $name = strtok($cookie, '=') ?: 'cookie';

        return substr($name, 0, 2).'***'.substr($name, -2);
    }

    private function applyUserAgent(): void
    {
        if ($this->serviceConfig['user_agent'] === '') {
            return;
        }

        @ini_set('user_agent', $this->serviceConfig['user_agent']);
    }

    private function buildHeaders(?string $jwt, array $headers = []): array
    {
        $requestHeaders = [];

        if ($jwt !== null && trim($jwt) !== '' && ! $this->hasAuthorizationHeader($headers)) {
            $requestHeaders[] = 'authorization: JWT '.trim($jwt);
        }

        foreach ($headers as $header) {
            $header = trim((string) $header);
            if ($header !== '') {
                $requestHeaders[] = $header;
            }
        }

        return $requestHeaders;
    }

    private function hasAuthorizationHeader(array $headers): bool
    {
        foreach ($headers as $header) {
            if (preg_match('/^authorization\s*:/i', trim((string) $header)) === 1) {
                return true;
            }
        }

        return false;
    }

    private function buildRequestBody(string $method, array|string $payload): ?string
    {
        if ($method === 'GET') {
            return null;
        }

        if (is_array($payload)) {
            return http_build_query($payload);
        }

        return (string) $payload;
    }

    private function extractResponsePayload(array $response): array
    {
        return is_array($response['data'] ?? null) ? $response['data'] : $response;
    }

    private function normalizeHostUpgradeConfigOptions(array $options): array
    {
        return collect($options)
            ->filter(fn ($item) => is_array($item))
            ->values()
            ->map(function (array $item, int $index) {
                $type = (int) ($item['option_type'] ?? 0);
                $name = trim((string) ($item['option_name'] ?? $item['name'] ?? ''));
                $nameParts = explode('|', $name, 2);
                $displayName = trim((string) (count($nameParts) > 1 ? $nameParts[1] : $name));
                $field = $this->resolveConfigOptionField(
                    $item,
                    self::CONFIG_OPTION_FIELD_MAP[$type] ?? null,
                    count($nameParts) > 1 ? trim($nameParts[0]) : '',
                    $displayName
                );
                $subOptions = $this->normalizeRemoteConfigSubOptions($item['sub'] ?? [], $type);
                $currentSubId = (int) ($item['subid'] ?? 0);
                $currentQty = is_numeric($item['qty'] ?? null) ? (int) $item['qty'] : null;
                $currentSub = $currentSubId > 0
                    ? collect($subOptions)->first(fn (array $sub) => (int) ($sub['id'] ?? 0) === $currentSubId)
                    : null;
                $sortOrder = (int) ($item['sort_order'] ?? $item['order'] ?? ($index + 1));

                return [
                    ...$item,
                    'id' => (int) ($item['id'] ?? $item['oid'] ?? 0),
                    'oid' => (int) ($item['oid'] ?? $item['id'] ?? 0),
                    'field' => $field,
                    'name' => $displayName !== '' ? $displayName : $name,
                    'option_name' => $name,
                    'option_type' => $type,
                    'current_sub_id' => $currentSubId > 0 ? $currentSubId : null,
                    'current_qty' => $currentQty,
                    'current_label' => trim((string) (
                        $item['suboption_name']
                        ?? $item['suboption_name_first']
                        ?? $currentSub['version']
                        ?? $currentSub['option_name']
                        ?? ''
                    )),
                    'qty_minimum' => (int) ($item['qty_minimum'] ?? 0),
                    'qty_maximum' => (int) ($item['qty_maximum'] ?? 0),
                    'qty_stage' => max(0, (int) ($item['qty_stage'] ?? 0)),
                    'unit' => trim((string) ($item['unit'] ?? '')),
                    'sort_order' => $sortOrder,
                    'order' => $sortOrder,
                    'sub' => $subOptions,
                ];
            })
            ->all();
    }

    private function buildContextOptions(string $method, array $headers, ?string $body): array
    {
        $sslVerify = $this->serviceConfig['ssl_verify'];
        $httpOptions = [
            'method' => $method,
            // 保留错误响应体，便于解析上游返回的 JSON 错误。
            'ignore_errors' => true,
            'timeout' => $this->serviceConfig['timeout'],
            'follow_location' => 0,
            'max_redirects' => 0,
        ];

        if ($headers !== []) {
            $httpOptions['header'] = implode("\r\n", $headers);
        }

        if ($body !== null) {
            $httpOptions['content'] = $body;
        }

        $sslOptions = [
            'capture_peer_cert' => true,
            'verify_peer' => $sslVerify,
            'verify_peer_name' => $sslVerify,
        ];

        if ($sslVerify && $this->serviceConfig['ca_bundle'] !== '' && is_file($this->serviceConfig['ca_bundle'])) {
            $sslOptions['cafile'] = $this->serviceConfig['ca_bundle'];
        }

        return [
            'http' => $httpOptions,
            'ssl' => $sslOptions,
        ];
    }

    private function buildHeaderMap(array $headers): array
    {
        $headerMap = [];

        foreach ($headers as $header) {
            [$name, $value] = array_pad(explode(':', (string) $header, 2), 2, null);
            $name = trim((string) $name);
            $value = trim((string) $value);

            if ($name === '' || $value === '') {
                continue;
            }

            $headerMap[$name] = $value;
        }

        return $headerMap;
    }

    private function buildHttpClientOptions(): array
    {
        $verify = $this->serviceConfig['ssl_verify'];

        if ($verify && $this->serviceConfig['ca_bundle'] !== '' && is_file($this->serviceConfig['ca_bundle'])) {
            $verify = $this->serviceConfig['ca_bundle'];
        }

        return [
            'allow_redirects' => false,
            'verify' => $verify,
        ];
    }

    private function buildUrl(string $baseUrl, string $uri, array $query = []): string
    {
        $this->assertTrustedBaseUrl($baseUrl);

        $baseUrl = rtrim($baseUrl, '/');
        $uri = trim($uri);
        $basePath = trim((string) parse_url($baseUrl, PHP_URL_PATH));
        $normalizedBasePath = $basePath === '' ? '' : '/'.trim($basePath, '/');

        $url = preg_match('#^https?://#i', $uri)
            ? $uri
            : $baseUrl.'/'.ltrim($this->normalizeRelativeUri($uri, $normalizedBasePath), '/');

        if ($query === []) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.http_build_query($query);
    }

    private function normalizeRelativeUri(string $uri, string $normalizedBasePath): string
    {
        $normalizedUri = '/'.ltrim($uri, '/');

        if ($normalizedBasePath === '' || $normalizedBasePath === '/') {
            return $normalizedUri;
        }

        if ($normalizedUri === $normalizedBasePath || str_starts_with($normalizedUri, $normalizedBasePath.'/')) {
            return substr($normalizedUri, strlen($normalizedBasePath)) ?: '/';
        }

        return $normalizedUri;
    }

    private function assertTrustedBaseUrl(string $baseUrl): void
    {
        $parsed = parse_url(trim($baseUrl));
        if (! is_array($parsed)) {
            throw new BusinessException('供应商接口地址格式不正确', 42200);
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        $host = strtolower(trim((string) ($parsed['host'] ?? '')));

        if ($scheme === '' || $host === '') {
            throw new BusinessException('供应商接口地址格式不正确', 42200);
        }

        if ($scheme !== 'https' && ! app()->environment('local')) {
            throw new BusinessException('供应商接口地址必须使用 HTTPS', 42200);
        }

        $shouldBypassLocalTestDns = $this->shouldBypassDnsLookupForLocalTestHost($host);
        $allowedHosts = (array) ($this->serviceConfig['allowed_hosts'] ?? []);
        if ($allowedHosts !== []) {
            $matched = collect($allowedHosts)->contains(
                fn (string $allowedHost): bool => $host === $allowedHost || str_ends_with($host, '.'.$allowedHost)
            );

            if (! $matched) {
                throw new BusinessException('供应商接口域名不在允许范围内', 42200);
            }
        }

        // 本地开发常通过代理把上游域名映射到保留地址；生产与测试环境仍保留 SSRF 防护。
        if ($shouldBypassLocalTestDns || app()->environment('local')) {
            return;
        }

        foreach ($this->resolveHostAddresses($host) as $ipAddress) {
            $publicIp = filter_var($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            if ($publicIp === false) {
                throw new BusinessException('供应商接口地址禁止解析到内网或保留地址', 42200);
            }
        }
    }

    private function resolveHostAddresses(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        if ($this->shouldBypassDnsLookupForLocalTestHost($host)) {
            return ['127.0.0.1'];
        }

        // 域名本身是合法 Redis key 字符（允许 . 分隔），无需 md5
        $cacheKey = 'upstream:hosting_panel_api:dns:'.strtolower($host);
        $cached = Cache::get($cacheKey);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        $addresses = $this->lookupHostAddresses($host);
        Cache::put($cacheKey, $addresses, now()->addSeconds(self::DNS_CACHE_TTL_SECONDS));

        return $addresses;
    }

    /**
     * @return array<int, string>
     */
    protected function lookupHostAddresses(string $host): array
    {
        $addresses = [];

        try {
            $addresses = $this->runDnsLookupProcess($host, (int) $this->serviceConfig['dns_resolver_timeout']);
        } catch (\Throwable $e) {
            if ($e instanceof ProcessTimedOutException) {
                $this->safeLog('warning', '[主机面板接口] 异步DNS解析超时', ['host' => $host, 'message' => $e->getMessage()]);
            } else {
                $this->safeLog('warning', '[主机面板接口] 异步DNS解析失败', ['host' => $host, 'message' => $e->getMessage()]);
            }
        }

        if ($addresses === []) {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);

            if (is_array($records)) {
                foreach ($records as $record) {
                    $ipAddress = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
                    if ($ipAddress !== '') {
                        $addresses[] = $ipAddress;
                    }
                }
            }
        }

        if ($addresses === []) {
            $fallback = @gethostbynamel($host);

            if (is_array($fallback)) {
                foreach ($fallback as $ipAddress) {
                    $ipAddress = (string) $ipAddress;
                    if ($ipAddress !== '') {
                        $addresses[] = $ipAddress;
                    }
                }
            }
        }

        throw_if($addresses === [], new BusinessException('无法解析供应商接口域名', 42200));

        return array_values(array_unique($addresses));
    }

    private function shouldBypassDnsLookupForLocalTestHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return false;
        }

        if (app()->environment('local', 'testing')) {
            return str_ends_with($host, '.test')
                || str_ends_with($host, '.example.test')
                || str_ends_with($host, '.localhost');
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    protected function runDnsLookupProcess(string $host, int $timeoutSeconds): array
    {
        $phpBinary = trim((string) PHP_BINARY);
        if ($phpBinary === '') {
            return [];
        }

        $script = <<<'PHP'
$host = (string) ($argv[1] ?? '');
$addresses = [];

if ($host !== '') {
    $records = @dns_get_record($host, DNS_A + DNS_AAAA);

    if (is_array($records)) {
        foreach ($records as $record) {
            $ipAddress = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
            if ($ipAddress !== '') {
                $addresses[] = $ipAddress;
            }
        }
    }

    if ($addresses === []) {
        $fallback = @gethostbynamel($host);
        if (is_array($fallback)) {
            foreach ($fallback as $ipAddress) {
                $ipAddress = (string) $ipAddress;
                if ($ipAddress !== '') {
                    $addresses[] = $ipAddress;
                }
            }
        }
    }
}

echo json_encode(array_values(array_unique($addresses)));
PHP;

        $process = new Process([$phpBinary, '-r', $script, $host]);
        $process->setTimeout(max($timeoutSeconds, 1));
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        $decoded = json_decode((string) $process->getOutput(), true);
        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->filter(fn ($ipAddress) => is_string($ipAddress) && trim($ipAddress) !== '')
            ->values()
            ->all();
    }

    private function maskUrlForLog(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])) {
            return '[upstream-url]';
        }

        $scheme = isset($parts['scheme']) ? $parts['scheme'].'://' : '';
        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';
        $path = ! empty($parts['path']) ? '/***' : '';
        $query = ! empty($parts['query']) ? '?***' : '';

        return $scheme.substr($host, 0, 2).'***'.substr($host, -2).$port.$path.$query;
    }

    private function resolveHttpCode(array $responseHeaders): int
    {
        foreach ($responseHeaders as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', (string) $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    private function resolveHeaderValue(array $responseHeaders, string $headerName): string
    {
        $normalizedHeaderName = strtolower(trim($headerName));

        foreach ($responseHeaders as $header) {
            $line = trim((string) $header);
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            if (strtolower(trim($name)) === $normalizedHeaderName) {
                return trim($value);
            }
        }

        return '';
    }

    private function buildInvalidJsonMessage(int $httpCode, string $contentType, string $body): string
    {
        $contentType = trim($contentType);
        $body = trim($body);

        if ($body === '') {
            return $httpCode > 0
                ? "供应商接口返回空响应（HTTP {$httpCode}）"
                : '供应商接口返回空响应';
        }

        if ($this->looksLikeHtmlResponse($body, $contentType)) {
            return $httpCode > 0
                ? "供应商接口返回异常页面，未解析到有效数据（HTTP {$httpCode}）"
                : '供应商接口返回异常页面，未解析到有效数据';
        }

        $contentTypeSuffix = $contentType !== '' ? "，Content-Type: {$contentType}" : '';

        return $httpCode > 0
            ? "供应商接口返回异常，未解析到有效数据（HTTP {$httpCode}{$contentTypeSuffix}）"
            : "供应商接口返回异常，未解析到有效数据{$contentTypeSuffix}";
    }

    private function looksLikeHtmlResponse(string $body, string $contentType = ''): bool
    {
        if ($contentType !== '' && str_contains(strtolower($contentType), 'text/html')) {
            return true;
        }

        $prefix = strtolower(ltrim($body));

        return str_starts_with($prefix, '<!doctype html')
            || str_starts_with($prefix, '<html')
            || str_starts_with($prefix, '<script')
            || str_starts_with($prefix, '<body');
    }

    private function sanitizeLogPayload(array|string $payload): array
    {
        if (is_array($payload)) {
            return $this->sanitizeLogArray($payload);
        }

        $decoded = json_decode($payload, true);
        if (is_array($decoded)) {
            return ['raw' => $this->sanitizeLogArray($decoded)];
        }

        return ['raw' => $this->sanitizeLogValue($payload)];
    }

    private function sanitizeLogArray(array $data): array
    {
        if (array_is_list($data)) {
            $preview = array_map(
                fn ($item) => is_array($item) ? $this->sanitizeLogArray($item) : $this->sanitizeLogValue($item),
                array_slice($data, 0, 6)
            );

            return [
                'count' => count($data),
                'preview' => $preview,
            ];
        }

        $sanitized = [];
        $index = 0;

        foreach ($data as $key => $value) {
            if ($index >= 20) {
                $sanitized['_truncated_keys'] = count($data) - 20;
                break;
            }

            $normalizedKey = strtolower((string) $key);

            if (in_array($normalizedKey, ['password', 'api_key', 'jwt', 'authorization'], true)) {
                $sanitized[$key] = '***';
                $index++;

                continue;
            }

            if (in_array($normalizedKey, ['author_url', 'pay_html'], true)) {
                $sanitized[$key] = '[omitted]';
                $index++;

                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeLogArray($value);
                $index++;

                continue;
            }

            $sanitized[$key] = $this->sanitizeLogValue($value, (string) $key);
            $index++;
        }

        return $sanitized;
    }

    private function summarizeLogResponse(mixed $response): mixed
    {
        if (! is_array($response)) {
            return $this->sanitizeLogValue($response);
        }

        $summary = $this->sanitizeLogArray($response);

        $payload = is_array($response['data'] ?? null) ? $response['data'] : [];
        if ($payload !== [] && is_array($payload['list'] ?? null)) {
            $summary['chart_summary'] = [
                'chart_type' => trim((string) ($payload['chart_type'] ?? '')),
                'unit' => trim((string) ($payload['unit'] ?? '')),
                'labels' => $this->sanitizeLogArray(is_array($payload['label'] ?? null) ? $payload['label'] : [$payload['label'] ?? '']),
                'series_count' => count($payload['list']),
                'point_counts' => collect($payload['list'])
                    ->map(fn ($series) => is_array($series) ? count($series) : 0)
                    ->values()
                    ->all(),
            ];
        }

        return $summary;
    }

    private function sanitizeLogValue(mixed $value, ?string $field = null): mixed
    {
        $value = $this->truncateLogValue($value);

        return SensitiveDataSanitizer::sanitize($value, $field);
    }

    private function truncateLogValue(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        return strlen($value) > 240
            ? substr($value, 0, 237).'...'
            : $value;
    }

    private function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOL);
        }

        return (bool) $value;
    }

    private function elapsedMilliseconds(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function resolveResponseDurationMs(mixed $response): ?int
    {
        if (! $response || ! method_exists($response, 'handlerStats')) {
            return null;
        }

        $stats = (array) $response->handlerStats();
        $seconds = $stats['total_time'] ?? null;

        return is_numeric($seconds) ? (int) round(((float) $seconds) * 1000) : null;
    }

    private function jwtCacheKey(Supplier $supplier): string
    {
        $providerKey = trim((string) ($supplier->provider_key ?? '')) ?: ProviderKey::HOSTING_PANEL_API;

        return "upstream:{$providerKey}:jwt:".$supplier->id;
    }

    private function jwtCache(): CacheRepository
    {
        $store = trim((string) ($this->serviceConfig['jwt_cache_store'] ?? 'redis'));

        try {
            return Cache::store($store !== '' ? $store : config('cache.default', 'redis'));
        } catch (\Throwable $exception) {
            $this->safeLog('warning', '[主机面板接口] JWT缓存仓库不可用，回退默认缓存仓库', [
                'store' => $store,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return Cache::store(config('cache.default', 'file'));
        }
    }

    private function shouldForgetJwtCacheForResponse(int $httpCode, mixed $decoded, ?string $jwt): bool
    {
        if ($jwt === null || trim($jwt) === '') {
            return false;
        }

        if ($httpCode === 401) {
            return true;
        }

        if (! is_array($decoded)) {
            return false;
        }

        $status = (int) ($decoded['status'] ?? $decoded['code'] ?? $decoded['status_code'] ?? 0);

        return $status === 401;
    }

    private function safeLog(string $level, string $message, array $context = []): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (\Throwable) {
        }
    }

    private function forgetJwtCache(Supplier $supplier): void
    {
        $this->jwtCache()->forget($this->jwtCacheKey($supplier));
    }

    private function resolveRequestJwt(Supplier $supplier, ?string $jwt, string $uri, array $headers = []): ?string
    {
        $jwt = trim((string) $jwt);
        if ($jwt !== '') {
            return $jwt;
        }

        if ($this->isLoginEndpoint($uri)) {
            return null;
        }

        if ($this->hasCookieHeader($headers)) {
            return null;
        }

        return $this->login($supplier);
    }

    private function hasCookieHeader(array $headers): bool
    {
        foreach ($headers as $header) {
            if (str_starts_with(strtolower(trim((string) $header)), 'cookie:')) {
                return true;
            }
        }

        return false;
    }

    private function resolveSupplierWebSessionCookie(Supplier $supplier): string
    {
        return $this->webSessionCookieParser()->parse((string) ($supplier->notes ?? ''));
    }

    private function webSessionCookieParser(): WebSessionCookieParser
    {
        return $this->webSessionCookieParser ?? new WebSessionCookieParser;
    }

    private function isLoginEndpoint(string $uri): bool
    {
        $uri = strtolower(trim($uri));

        return $uri === '/v1/login_api'
            || str_contains($uri, '/v1/login_api?')
            || $uri === '/zjmf_api_login'
            || str_contains($uri, '/zjmf_api_login?');
    }

    private function resolveJwtCacheTtlSeconds(string $jwt): int
    {
        $payload = $this->decodeJwtPayload($jwt);
        $expiresAt = (int) ($payload['exp'] ?? 0);

        if ($expiresAt <= 0) {
            return self::DEFAULT_JWT_CACHE_TTL_SECONDS;
        }

        $ttlSeconds = $expiresAt - time() - 300;

        return max(
            self::MIN_JWT_CACHE_TTL_SECONDS,
            min($ttlSeconds, self::MAX_JWT_CACHE_TTL_SECONDS)
        );
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

    private function supportsConfigTemplate(array $product): bool
    {
        return (new CloudConfigTemplate)->supports($product);
    }

    private function buildCloudConfigTemplate(array $product): array
    {
        return (new CloudConfigTemplate)->build($product);
    }
}
