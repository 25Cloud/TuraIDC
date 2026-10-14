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
     *                                                   用于修 CSS 内部的相对引用，见 rewriteCssRelativeUrls()。
     *
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
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT_SECONDS,
                'ignore_errors' => true,
                // 上游可能有跳转（如带版本号的 CDN 路径），跟随少量跳转
                'follow_location' => 1,
                'max_redirects' => 3,
                'user_agent' => 'Mozilla/5.0 (compatible; TuraIDC-ConsolePanel/1.0)',
                'header' => implode("\r\n", [
                    'Accept: text/css,application/javascript,image/*,font/*,*/*;q=0.8',
                    // 少数上游按 Referer 防盗链，这里带上供应商自身的来源
                    'Referer: '.rtrim($rootUrl, '/').'/',
                ]),
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        error_clear_last();
        $body = @file_get_contents($url, false, $context);
        $lastError = error_get_last();
        $responseHeaders = is_array($http_response_header) ? $http_response_header : [];
        $httpCode = $this->resolveHttpCode($responseHeaders);
        $contentType = $this->resolveHeaderValue($responseHeaders, 'Content-Type');

        $failed = $body === false || $httpCode !== 200 || trim((string) $body) === '' || strlen((string) $body) > self::MAX_BYTES;

        if ($failed) {
            Log::warning('[服务控制台] 面板静态资源回源失败', [
                'supplier_id' => (int) $supplier->id,
                'path' => $path,
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
    private function resolveHttpCode(array $headers): int
    {
        foreach ($headers as $header) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~i', (string) $header, $matches) === 1) {
                return (int) $matches[1];
            }
        }

        return 0;
    }

    /**
     * @param  array<int, string>  $headers
     */
    private function resolveHeaderValue(array $headers, string $name): string
    {
        foreach ($headers as $header) {
            $position = strpos((string) $header, ':');
            if ($position === false) {
                continue;
            }

            if (strcasecmp(trim(substr((string) $header, 0, $position)), $name) === 0) {
                return trim(substr((string) $header, $position + 1));
            }
        }

        return '';
    }
}
