<?php

declare(strict_types=1);

namespace App\Services\ClientServiceConsole;

use App\Exceptions\BusinessException;
use App\Models\Supplier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 上游面板静态资源反代
 *
 * 上游渲染的自定义面板片段里，CSS/JS 用的是上游自己的绝对地址
 * （如 https://ww.stay33.cn/vendor/dcimcloud/css/01setting.css），
 * 直接下发等于把上游地址暴露给终端用户。这里把这类资源经本系统反代 + 缓存后下发：
 *
 *   - 目标地址由「服务绑定的供应商根域名 + 校验过的路径」拼出，路径不接受协议头、
 *     主机或 `..`，因此不可能被当成任意 URL 代理（防 SSRF / 开放反代）；
 *   - CSS 内部的相对 url() 会被改写成本站地址：跨源反代换掉了相对引用的解析基准，
 *     原样透传会让 CSS 里的图片解析到本站一个不存在的路径（详见 rewriteCssRelativeUrls）；
 *   - 命中缓存直接返回，不重复回源上游；
 *   - 回传的 Content-Type 做白名单收敛，避免把可执行内容当同源资源下发。
 */
final class ServiceConsoleAssetService
{
    private const CACHE_PREFIX = 'service_console:asset:v1:';

    private const CACHE_TTL_SECONDS = 86400;

    /** 回源失败（如主题没有该文件）的短缓存，避免每次开面板都重复回源 + 刷日志 */
    private const NEGATIVE_CACHE_TTL_SECONDS = 600;

    private const TIMEOUT_SECONDS = 8;

    /** 单个资源上限，超过不缓存也不回传（正常面板资源都在百 KB 级） */
    private const MAX_BYTES = 2 * 1024 * 1024;

    /** 响应头上限，避免异常上游用超长 Location/头字段耗尽内存。 */
    private const MAX_HEADER_BYTES = 64 * 1024;

    /** 手动跟随跳转的上限；每一跳都会重新校验目标地址。 */
    private const MAX_REDIRECTS = 3;

    /** DNS 解析和连接目标必须是可从公网访问的地址。 */
    private const BLOCKED_HOSTNAMES = [
        'localhost',
        'localhost.localdomain',
        'metadata',
        'metadata.google.internal',
        'instance-data',
        'instance-data.ec2.internal',
    ];

    /** 允许原样回传的 Content-Type 前缀；不在表内的一律降级为 text/plain */
    private const ALLOWED_CONTENT_TYPES = [
        'text/css',
        'text/javascript',
        'text/plain',
        'application/javascript',
        'application/x-javascript',
        'application/json',
        'image/',
        'font/',
        'application/font',
        'application/vnd.ms-fontobject',
        'application/octet-stream',
    ];

    /**
     * 取上游静态资源（带缓存）。
     *
     * @param  callable(string):string  $proxyUrlFactory  把「上游站点内的绝对路径」包装成本站可取到的地址。
     *                                                    用于修 CSS 内部的相对引用，见 rewriteCssRelativeUrls()。
     * @return array{body: string, content_type: string}
     */
    public function fetch(Supplier $supplier, string $rootUrl, string $path, callable $proxyUrlFactory): array
    {
        $path = $this->assertSafePath($path);
        $url = $this->buildUpstreamUrl($rootUrl, $path);
        $cacheKey = self::CACHE_PREFIX.$supplier->id.':'.sha1($path);

        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            if (($cached['missing'] ?? false) === true) {
                throw new BusinessException('面板资源加载失败', 50000);
            }

            if (is_string($cached['body'] ?? null)) {
                return $this->prepareResponse($cached, $path, $proxyUrlFactory);
            }
        }

        try {
            $asset = $this->download($url, $rootUrl, $supplier, $path);
        } catch (BusinessException $exception) {
            Cache::put($cacheKey, ['missing' => true], now()->addSeconds(self::NEGATIVE_CACHE_TTL_SECONDS));
            throw $exception;
        }

        Cache::put($cacheKey, $asset, now()->addSeconds(self::CACHE_TTL_SECONDS));

        // 改写放在这里、而不是 download() 里：缓存里留上游原文（与票据无关，可跨用户复用），
        // 改写结果依赖当前票据，必须每次返回时重新施加，否则票据过期后改写出的地址会失效。
        return $this->prepareResponse($asset, $path, $proxyUrlFactory);
    }

    /**
     * @param  array{body?: mixed, content_type?: mixed}  $asset
     * @param  callable(string):string  $proxyUrlFactory
     * @return array{body: string, content_type: string}
     */
    private function prepareResponse(array $asset, string $path, callable $proxyUrlFactory): array
    {
        $body = (string) ($asset['body'] ?? '');
        $contentType = (string) ($asset['content_type'] ?? 'text/plain; charset=UTF-8');

        return [
            'body' => $this->isStylesheet($contentType)
                ? $this->rewriteCssRelativeUrls($body, $path, $proxyUrlFactory)
                : $body,
            'content_type' => $contentType,
        ];
    }

    private function isStylesheet(string $contentType): bool
    {
        return str_starts_with(strtolower(trim(explode(';', $contentType)[0])), 'text/css');
    }

    /**
     * 把 CSS 里相对「上游原始路径」写的 url() 改写成本站可取到的地址。
     *
     * 跨源反代会换掉相对引用的解析基准：浏览器是按**反代后的 URL** 解析 CSS 内的 url() 的，
     * 而上游写的是相对它自己原始 URL 的。例如上游
     *   /vendor/dcimcloud/css/selectFilter.css  →  url(../img/icon_arrow_down.png)
     * 本应解析成 /vendor/dcimcloud/img/icon_arrow_down.png；经 ?path= 形式的反代 URL 加载后，
     * 基准目录变成了反代端点所在目录，最终落到本站一个不存在的路径 → 404，图标空白。
     *
     * 只处理 url()：CSS 里相对引用的声明式写法只有这一种，能静态可靠解析。
     * JS 里的相对路径（字符串拼接、new Image().src 等）没有可枚举的可靠形态，不在这里猜。
     */
    private function rewriteCssRelativeUrls(string $css, string $resourcePath, callable $proxyUrlFactory): string
    {
        // dirname('/a/b/c.css') = '/a'；根下文件退化成 '/'，下面统一归一成空串
        $baseDir = rtrim(dirname($resourcePath), '/');

        return (string) preg_replace_callback(
            '~url\(\s*(?<quote>["\']?)(?<url>[^"\'\)\s]*)\k<quote>\s*\)~i',
            function (array $matches) use ($baseDir, $proxyUrlFactory): string {
                $quote = (string) $matches['quote'];
                $url = (string) $matches['url'];

                // 绝对地址、协议相对、data:、片段引用都不是相对引用，保持原样
                if ($url === '' || preg_match('~^(?:[a-z][a-z0-9+.\-]*:|//|\#)~i', $url) === 1) {
                    return 'url('.$quote.$url.$quote.')';
                }

                // 先摘出查询串与片段，否则 ? 会被当成路径的一段，rawurlencode 后上游收不到
                $suffix = '';
                if (preg_match('~^(?<path>[^?\#]*)(?<suffix>[?\#].*)?$~', $url, $parts) === 1) {
                    $url = $parts['path'];
                    $suffix = (string) ($parts['suffix'] ?? '');
                }

                $absolute = $this->resolveRelativePath($baseDir, $url);
                if ($absolute === '') {
                    return 'url('.$quote.$url.$quote.')';
                }

                return 'url('.$quote.$proxyUrlFactory($absolute.$suffix).$quote.')';
            },
            $css
        );
    }

    /**
     * 把 ./ 与 ../ 逐段解析成站点内绝对路径；越出站点根目录（或本身没有路径段）返回空串。
     */
    private function resolveRelativePath(string $baseDir, string $relative): string
    {
        $segments = $baseDir === '' ? [] : explode('/', trim($baseDir, '/'));

        foreach (explode('/', $relative) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    return '';
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return $segments === [] ? '' : '/'.implode('/', $segments);
    }

    /**
     * 只接受「路径」形态的输入：必须以 / 开头，且不得携带协议、主机、上级目录或控制字符。
     */
    private function assertSafePath(string $path): string
    {
        $path = trim($path);

        $invalid = $path === ''
            || strlen($path) > 500
            || ! str_starts_with($path, '/')
            || str_starts_with($path, '//')          // 协议相对地址 = 自带主机
            || str_contains($path, '://')
            || str_contains($path, '..')
            || str_contains($path, '\\')
            || preg_match('~[\x00-\x20\x7F"\'\<\>]~', $path) === 1;

        throw_if($invalid, new BusinessException('资源地址无效', 42200));

        return $path;
    }

    /**
     * 用供应商根域名拼出上游地址，并复核拼出来的主机没被路径带偏（双保险）。
     */
    private function buildUpstreamUrl(string $rootUrl, string $path): string
    {
        $root = rtrim($rootUrl, '/');
        $url = $root.$path;

        $rootParts = parse_url($root);
        $urlParts = parse_url($url);
        $rootHost = is_array($rootParts) ? strtolower((string) ($rootParts['host'] ?? '')) : '';
        $urlHost = is_array($urlParts) ? strtolower((string) ($urlParts['host'] ?? '')) : '';

        throw_if(
            $rootHost === '' || $urlHost !== $rootHost,
            new BusinessException('资源地址无效', 42200)
        );

        return $url;
    }

    /**
     * @return array{body: string, content_type: string}
     */
    private function download(string $url, string $rootUrl, Supplier $supplier, string $path): array
    {
        $currentUrl = $url;
        $redirects = 0;
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;
        $rootParts = parse_url($rootUrl);
        $allowedHost = is_array($rootParts) ? strtolower(rtrim((string) ($rootParts['host'] ?? ''), '.')) : '';
        if (str_starts_with($allowedHost, '[') && str_ends_with($allowedHost, ']')) {
            $allowedHost = substr($allowedHost, 1, -1);
        }
        $allowedScheme = is_array($rootParts) ? strtolower((string) ($rootParts['scheme'] ?? '')) : '';
        $allowedPort = is_array($rootParts) && isset($rootParts['port'])
            ? (int) $rootParts['port']
            : ($allowedScheme === 'https' ? 443 : 80);
        $body = false;
        $responseHeaders = [];
        $httpCode = 0;
        $contentType = '';
        $lastError = null;

        while (true) {
            $remainingMilliseconds = (int) ceil(($deadline - microtime(true)) * 1000);

            if ($remainingMilliseconds < 1) {
                $lastError = ['message' => '请求超时'];
                break;
            }

            $target = $this->assertSafeUpstreamUrl($currentUrl, $allowedHost, $allowedScheme, $allowedPort);

            [$body, $responseHeaders, $httpCode, $lastError] = $this->requestUrl(
                $currentUrl,
                $rootUrl,
                $target,
                min($remainingMilliseconds, self::TIMEOUT_SECONDS * 1000)
            );

            if (in_array($httpCode, [301, 302, 303, 307, 308], true)) {
                if ($redirects >= self::MAX_REDIRECTS) {
                    $lastError = ['message' => '重定向次数超过限制'];
                    break;
                }

                $location = $this->resolveHeaderValue($responseHeaders, 'Location');
                if ($location === '') {
                    $lastError = ['message' => '重定向缺少目标地址'];
                    break;
                }

                try {
                    $currentUrl = $this->resolveRedirectUrl($currentUrl, $location);
                    // 下一轮会再次校验 scheme、host、port 和 DNS 地址。
                } catch (BusinessException $exception) {
                    $lastError = ['message' => $exception->getMessage()];
                    break;
                }

                $redirects++;

                continue;
            }

            $contentType = $this->resolveHeaderValue($responseHeaders, 'Content-Type');

            break;
        }

        $failed = $body === false
            || $httpCode !== 200
            || trim((string) $body) === ''
            || strlen((string) $body) > self::MAX_BYTES;

        if ($failed) {
            Log::warning('[服务控制台] 面板静态资源回源失败', [
                'supplier_id' => (int) $supplier->id,
                'http_code' => $httpCode,
                'bytes' => $body === false ? 0 : strlen((string) $body),
                'error' => (string) ($lastError['message'] ?? ''),
            ]);

            throw new BusinessException('面板资源加载失败', 50000);
        }

        return [
            'body' => (string) $body,
            'content_type' => $this->sanitizeContentType($contentType),
        ];
    }

    /**
     * 发起一次不跟随重定向的请求，并在写入回调中限制响应体大小。
     *
     * @param  array{host: string, port: int, ip: string, is_ip: bool}  $target
     * @return array{0: string|false, 1: array<int, string>, 2: int, 3: array{message: string}|null}
     */
    private function requestUrl(string $url, string $rootUrl, array $target, int $timeoutMilliseconds): array
    {
        if (! function_exists('curl_init')) {
            return [false, [], 0, ['message' => '服务器未启用 cURL 扩展']];
        }

        $body = '';
        $headers = [];
        $headerBytes = 0;
        $curl = curl_init($url);

        if ($curl === false) {
            return [false, [], 0, ['message' => '无法初始化网络请求']];
        }

        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_CONNECTTIMEOUT_MS => max(1, min(3000, $timeoutMilliseconds)),
            CURLOPT_TIMEOUT_MS => max(1, $timeoutMilliseconds),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; TuraIDC-ConsolePanel/1.0)',
            CURLOPT_HTTPHEADER => [
                'Accept: text/css,application/javascript,image/*,font/*,*/*;q=0.8',
                // 少数上游按 Referer 防盗链，这里带上供应商自身的来源。
                'Referer: '.rtrim($rootUrl, '/').'/',
            ],
            // 代理会绕过本地 DNS/IP 校验，资源回源必须直连已校验的目标。
            CURLOPT_PROXY => '',
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > self::MAX_BYTES) {
                    return 0;
                }

                $body .= $chunk;

                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($handle, string $headerLine) use (&$headers, &$headerBytes): int {
                if ($headerBytes + strlen($headerLine) > self::MAX_HEADER_BYTES) {
                    return 0;
                }

                $headerBytes += strlen($headerLine);
                $headers[] = rtrim($headerLine, "\r\n");

                return strlen($headerLine);
            },
        ];

        if (! $target['is_ip']) {
            $options[CURLOPT_RESOLVE] = [
                $target['host'].':'.$target['port'].':'.$target['ip'],
            ];
        }

        if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTP') && defined('CURLPROTO_HTTPS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        }

        curl_setopt_array($curl, $options);
        $result = curl_exec($curl);
        $error = curl_error($curl);
        $errorNumber = curl_errno($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($result === false || $errorNumber !== 0) {
            return [false, $headers, $httpCode, ['message' => $error !== '' ? $error : '网络请求失败']];
        }

        return [$body, $headers, $httpCode, null];
    }

    /**
     * 校验一个回源或跳转地址，并返回用于固定 DNS 解析结果的目标信息。
     *
     * @return array{host: string, port: int, ip: string, is_ip: bool}
     */
    private function assertSafeUpstreamUrl(string $url, string $expectedHost = '', string $expectedScheme = '', int $expectedPort = 0): array
    {
        if (preg_match('~[\x00-\x20\x7F]~', $url) === 1) {
            throw new BusinessException('资源地址无效', 42200);
        }

        try {
            $parts = parse_url($url);
        } catch (\Throwable $exception) {
            $parts = false;
        }

        if (! is_array($parts)) {
            throw new BusinessException('资源地址无效', 42200);
        }

        $scheme = strtolower(trim((string) ($parts['scheme'] ?? '')));
        $rawHost = trim((string) ($parts['host'] ?? ''));

        if (! in_array($scheme, ['http', 'https'], true)
            || $rawHost === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || preg_match('~[\x00-\x20\x7F\\/\"\'<>]~', $rawHost) === 1) {
            throw new BusinessException('资源地址无效', 42200);
        }

        $host = $rawHost;
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if (str_ends_with($host, '.')) {
            throw new BusinessException('资源地址无效', 42200);
        }

        $host = strtolower($host);
        if ($host === '' || strlen($host) > 253) {
            throw new BusinessException('资源地址无效', 42200);
        }

        // 资源代理只能跟随供应商自身的跳转，不能把供应商响应变成任意公网
        // 探测器；这也确保后续 DNS 解析仍绑定在同一供应商主机上。
        if ($expectedHost !== '' && $host !== $expectedHost) {
            throw new BusinessException('资源地址无效', 42200);
        }
        // 允许 HTTP 上游升级到 HTTPS，但不允许 HTTPS 资源被重定向降级到明文。
        if ($expectedScheme === 'https' && $scheme !== 'https') {
            throw new BusinessException('资源地址无效', 42200);
        }

        $port = isset($parts['port'])
            ? (int) $parts['port']
            : ($scheme === 'https' ? 443 : 80);

        if ($port < 1 || $port > 65535) {
            throw new BusinessException('资源地址无效', 42200);
        }
        $httpsUpgrade = $expectedScheme === 'http'
            && $scheme === 'https'
            && $expectedPort === 80
            && $port === 443;
        if ($expectedPort > 0 && $port !== $expectedPort && ! $httpsUpgrade) {
            throw new BusinessException('资源地址无效', 42200);
        }

        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        if ($isIp) {
            if ($this->isPrivateOrReservedIp($host)) {
                throw new BusinessException('资源地址无效', 42200);
            }

            return [
                'host' => $host,
                'port' => $port,
                'ip' => $host,
                'is_ip' => true,
            ];
        }

        if (in_array($host, self::BLOCKED_HOSTNAMES, true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.internal')) {
            throw new BusinessException('资源地址无效', 42200);
        }

        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw new BusinessException('资源地址无效', 42200);
        }

        $addresses = $this->resolveHostAddresses($host);
        if ($addresses === []) {
            throw new BusinessException('资源地址无效', 42200);
        }

        foreach ($addresses as $address) {
            if ($this->isPrivateOrReservedIp($address)) {
                throw new BusinessException('资源地址无效', 42200);
            }
        }

        // 优先固定 IPv4；只有纯 IPv6 目标时才使用 IPv6，避免双栈服务器因本机 IPv6
        // 不可达而误判为上游不可用。所有候选地址都已经在上面完成安全校验。
        $ip = $addresses[0];
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $ip = $address;
                break;
            }
        }

        return [
            'host' => $host,
            'port' => $port,
            'ip' => $ip,
            'is_ip' => false,
        ];
    }

    /** @return list<string> */
    private function resolveHostAddresses(string $host): array
    {
        $addresses = [];
        $ipv4 = @gethostbynamel($host);
        if (is_array($ipv4)) {
            $addresses = array_merge($addresses, $ipv4);
        }

        if (function_exists('dns_get_record')) {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($records)) {
                foreach ($records as $record) {
                    $address = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
                    if ($address !== '') {
                        $addresses[] = $address;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter(
            $addresses,
            static fn (string $address): bool => filter_var($address, FILTER_VALIDATE_IP) !== false
        )));
    }

    /**
     * 拒绝所有不能作为公网资源回源目标的地址，包括 IPv4 映射/兼容 IPv6。
     */
    private function isPrivateOrReservedIp(string $ip): bool
    {
        $ip = $this->normalizeIpForPolicyCheck(trim($ip));
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return true;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }

        $networks = strlen($packed) === 4
            ? [
                ['0.0.0.0', 8],
                ['10.0.0.0', 8],
                ['100.64.0.0', 10],
                ['127.0.0.0', 8],
                ['169.254.0.0', 16],
                ['172.16.0.0', 12],
                ['192.0.0.0', 24],
                ['192.0.2.0', 24],
                ['192.88.99.0', 24],
                ['192.168.0.0', 16],
                ['198.18.0.0', 15],
                ['198.51.100.0', 24],
                ['203.0.113.0', 24],
                ['224.0.0.0', 4],
                ['240.0.0.0', 4],
            ]
            : [
                ['::', 128],
                ['::1', 128],
                ['100::', 64],
                ['2001::', 32],
                ['2001:2::', 48],
                ['2001:3::', 32],
                ['2001:4:112::', 48],
                ['2001:10::', 28],
                ['2001:20::', 28],
                ['2001:db8::', 32],
                ['2002::', 16],
                ['3fff::', 20],
                ['fc00::', 7],
                ['fe80::', 10],
                ['ff00::', 8],
            ];

        foreach ($networks as [$network, $prefixLength]) {
            $networkPacked = @inet_pton($network);
            if ($networkPacked !== false && $this->ipInNetwork($packed, $networkPacked, $prefixLength)) {
                return true;
            }
        }

        return false;
    }

    private function ipInNetwork(string $ip, string $network, int $prefixLength): bool
    {
        $fullBytes = intdiv($prefixLength, 8);
        if ($fullBytes > 0 && substr($ip, 0, $fullBytes) !== substr($network, 0, $fullBytes)) {
            return false;
        }

        $remainingBits = $prefixLength % 8;
        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainingBits)) & 0xFF;

        return (ord($ip[$fullBytes]) & $mask) === (ord($network[$fullBytes]) & $mask);
    }

    private function normalizeIpForPolicyCheck(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }

        $mappedPrefix = str_repeat("\x00", 10)."\xff\xff";
        $compatiblePrefix = str_repeat("\x00", 12);
        if (! str_starts_with($packed, $mappedPrefix) && ! str_starts_with($packed, $compatiblePrefix)) {
            return $ip;
        }

        $ipv4 = @inet_ntop(substr($packed, 12));

        return is_string($ipv4) && filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            ? $ipv4
            : $ip;
    }

    private function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        $location = trim($location);
        if ($location === ''
            || preg_match('~[\x00-\x20\x7F]~', $location) === 1
            || str_contains($location, '\\')) {
            throw new BusinessException('资源地址无效', 42200);
        }

        if (str_starts_with($location, '#')) {
            return $baseUrl;
        }

        try {
            $base = parse_url($baseUrl);
            $target = parse_url($location);
        } catch (\Throwable $exception) {
            throw new BusinessException('资源地址无效', 42200);
        }

        if (! is_array($base) || ! is_array($target)) {
            throw new BusinessException('资源地址无效', 42200);
        }

        $baseScheme = strtolower((string) ($base['scheme'] ?? ''));
        $baseHost = (string) ($base['host'] ?? '');
        if ($baseScheme === '' || $baseHost === '') {
            throw new BusinessException('资源地址无效', 42200);
        }

        if (isset($target['scheme'])) {
            $resolved = $location;
        } elseif (str_starts_with($location, '//')) {
            $resolved = $baseScheme.':'.$location;
        } else {
            $origin = $baseScheme.'://'.$baseHost;
            if (isset($base['port'])) {
                $origin .= ':'.$base['port'];
            }

            $targetPath = (string) ($target['path'] ?? '');
            if (str_starts_with($location, '?')) {
                $targetPath = (string) ($base['path'] ?? '/');
            } elseif (! str_starts_with($location, '/')) {
                $basePath = (string) ($base['path'] ?? '/');
                $directory = str_ends_with($basePath, '/') ? $basePath : dirname($basePath).'/';
                $targetPath = $this->normalizeRedirectPath($directory.$targetPath);
            }

            if ($targetPath === '') {
                $targetPath = '/';
            }

            $resolved = $origin.$targetPath;
            if (isset($target['query'])) {
                $resolved .= '?'.$target['query'];
            } elseif (str_starts_with($location, '?')) {
                $resolved = $origin.$targetPath.'?'.substr($location, 1);
            }
        }

        return $resolved;
    }

    private function normalizeRedirectPath(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/'.implode('/', $segments);
    }

    /**
     * 收敛回传的 Content-Type：只放行静态资源类型，其余降级为 text/plain，
     * 避免上游返回 text/html 之类的可执行内容被当成同源资源加载。
     */
    private function sanitizeContentType(string $contentType): string
    {
        $contentType = trim(str_replace(["\r", "\n"], '', $contentType));
        $bareType = strtolower(trim(explode(';', $contentType)[0]));

        foreach (self::ALLOWED_CONTENT_TYPES as $allowed) {
            if ($bareType !== '' && str_starts_with($bareType, $allowed)) {
                return $contentType !== '' ? $contentType : $bareType;
            }
        }

        return 'text/plain; charset=UTF-8';
    }

    /**
     * @param  array<int, string>  $headers
     */
    private function resolveHeaderValue(array $headers, string $name): string
    {
        $value = '';
        foreach ($headers as $header) {
            $position = strpos((string) $header, ':');
            if ($position === false) {
                continue;
            }

            if (strcasecmp(trim(substr((string) $header, 0, $position)), $name) === 0) {
                $value = trim(substr((string) $header, $position + 1));
            }
        }

        return $value;
    }
}
