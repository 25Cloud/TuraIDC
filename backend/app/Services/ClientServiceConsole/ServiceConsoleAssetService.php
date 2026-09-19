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
     * @return array{body: string, content_type: string}
     */
    public function fetch(Supplier $supplier, string $rootUrl, string $path): array
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
                return [
                    'body' => $cached['body'],
                    'content_type' => (string) ($cached['content_type'] ?? 'text/plain; charset=UTF-8'),
                ];
            }
        }

        try {
            $asset = $this->download($url, $rootUrl, $supplier, $path);
        } catch (BusinessException $exception) {
            Cache::put($cacheKey, ['missing' => true], now()->addSeconds(self::NEGATIVE_CACHE_TTL_SECONDS));
            throw $exception;
        }

        Cache::put($cacheKey, $asset, now()->addSeconds(self::CACHE_TTL_SECONDS));

        return $asset;
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
