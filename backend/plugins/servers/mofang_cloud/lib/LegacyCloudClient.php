<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\MofangCloud\Lib;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * 魔方云老版本接口（legacy `/v1`）客户端。
 *
 * 逐条对齐魔方财务 3.7.6 的传输实现（`DcimCloud::basecurl/curl/login`）：
 * - 普通账号：`POST {url}/v1/login?a=a` 表单提交账号密码，响应体原文就是 token，去引号即用；
 * - 代理账号：`POST {url}/index.php?path=token`，取 `data.token`；
 * - 业务请求带 `access-token` 头，token 缓存 6 小时；
 * - 命中 401 时强制重登一次并重放当次请求（只重放一次，避免死循环）；
 * - 写操作用表单编码（`application/x-www-form-urlencoded`），不是 JSON；
 * - 响应体是平的：错误表现为顶层 `error` 字段，其余字段直接在顶层，列表在 `data` 里。
 */
final class LegacyCloudClient implements CloudApiClient
{
    private const TOKEN_TTL_SECONDS = 21600;

    private const DEFAULT_TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly CloudCredentials $credentials,
    ) {}

    public function mode(): CloudApiMode
    {
        return CloudApiMode::Legacy;
    }

    public function credentials(): CloudCredentials
    {
        return $this->credentials;
    }

    public function request(string $method, string $path, array $payload = [], array $query = []): array
    {
        $method = strtoupper($method);
        $token = $this->token();
        $response = $this->dispatch($method, $path, $payload, $query, $token);

        if ($response->status() === 401) {
            $this->forgetToken();
            $token = $this->token(forceRefresh: true);
            $response = $this->dispatch($method, $path, $payload, $query, $token);
        }

        return $this->decode($response);
    }

    /**
     * 登录换 access-token。
     */
    public function token(bool $forceRefresh = false): string
    {
        $cacheKey = $this->credentials->tokenCacheKey();
        if (! $forceRefresh) {
            $cached = Cache::get($cacheKey);
            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
        }

        $token = $this->login();
        Cache::put($cacheKey, $token, self::TOKEN_TTL_SECONDS);

        return $token;
    }

    public function forgetToken(): void
    {
        Cache::forget($this->credentials->tokenCacheKey());
    }

    private function login(): string
    {
        try {
            if ($this->credentials->agentAccount) {
                $response = $this->http()->asForm()
                    ->timeout(self::DEFAULT_TIMEOUT_SECONDS)
                    ->post($this->url('/index.php', ['path' => 'token']), [
                        'username' => $this->credentials->account,
                        'password' => $this->credentials->secret,
                    ]);
            } else {
                $response = $this->http()->asForm()
                    ->timeout(self::DEFAULT_TIMEOUT_SECONDS)
                    ->post($this->url('/login', ['a' => 'a']), [
                        'username' => $this->credentials->account,
                        'password' => $this->credentials->secret,
                    ]);
            }
        } catch (ConnectionException $exception) {
            throw new CloudApiException('无法连接魔方云管理系统：'.$exception->getMessage(), null, CloudApiMode::Legacy);
        }

        if ($response->status() === 404) {
            throw new CloudApiException(
                '无法连接魔方云管理系统，请检查魔方云访问地址是否填写正确（也可能是该地址用的是新版本接口，请把接口代际改成「新版本」）',
                404,
                CloudApiMode::Legacy
            );
        }

        if ($response->status() < 200 || $response->status() >= 300) {
            throw new CloudApiException(
                '魔方云登录失败：HTTP 状态码 '.$response->status(),
                $response->status(),
                CloudApiMode::Legacy
            );
        }

        $raw = trim($response->body());
        $decoded = json_decode($raw, true);

        // 代理账号返回 JSON，普通账号返回「带引号的 token 原文」。
        if (is_array($decoded)) {
            $token = $decoded['data']['token'] ?? $decoded['token'] ?? null;
            if (is_string($token) && trim($token) !== '') {
                return trim($token);
            }

            $message = $decoded['msg'] ?? $decoded['error'] ?? null;
            throw new CloudApiException(
                is_string($message) && $message !== '' ? $message : '魔方云登录失败：未返回 token',
                $response->status(),
                CloudApiMode::Legacy
            );
        }

        $token = trim($raw, "\" \t\n\r\0\x0B");
        if ($token === '') {
            throw new CloudApiException('魔方云登录失败：账号或密码错误', $response->status(), CloudApiMode::Legacy);
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $query
     */
    private function dispatch(string $method, string $path, array $payload, array $query, string $token): Response
    {
        $headers = ['access-token' => $token];

        try {
            if ($method === 'GET') {
                return $this->http()
                    ->withHeaders($headers)
                    ->timeout(self::DEFAULT_TIMEOUT_SECONDS)
                    ->get($this->url($path, $query));
            }

            return $this->http()
                ->withHeaders($headers)
                ->withBody(http_build_query($payload), 'application/x-www-form-urlencoded')
                ->timeout(self::DEFAULT_TIMEOUT_SECONDS)
                ->send($method, $this->url($path, $query));
        } catch (ConnectionException $exception) {
            throw new CloudApiException('魔方云请求失败：'.$exception->getMessage(), null, CloudApiMode::Legacy);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $raw = $response->body();
        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new CloudApiException(
                '魔方云返回的不是有效 JSON（HTTP '.$response->status().'）',
                $response->status(),
                CloudApiMode::Legacy
            );
        }

        $error = $decoded['error'] ?? null;
        if (is_string($error) && $error !== '') {
            throw new CloudApiException($error, $response->status(), CloudApiMode::Legacy);
        }

        if ($response->status() < 200 || $response->status() >= 300) {
            $message = $decoded['msg'] ?? null;

            throw new CloudApiException(
                is_string($message) && $message !== ''
                    ? $message
                    : '请求失败,HTTP状态码:'.$response->status(),
                $response->status(),
                CloudApiMode::Legacy
            );
        }

        return $decoded;
    }

    /**
     * 证书校验走平台默认策略：插件不关闭校验，也不自带 CA。
     */
    private function http(): PendingRequest
    {
        return Http::acceptJson();
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function url(string $path, array $query = []): string
    {
        $base = $this->credentials->baseUrl;

        $url = str_starts_with($path, '/index.php')
            ? $base.$path
            : $base.'/v1'.'/'.ltrim($path, '/');

        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        return $url;
    }
}
