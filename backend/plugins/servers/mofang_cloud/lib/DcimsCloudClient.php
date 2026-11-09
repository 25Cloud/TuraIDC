<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\MofangCloud\Lib;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * 魔方云新版本接口（dcims `/api/v1`）客户端。
 *
 * 逐条对齐魔方财务 3.8.0 的 `DcimsClient`：
 * - 地址 `{url}/api/v1{path}`，JSON 报文；
 * - 头 `Dcims-X-Appid` / `Dcims-X-Nonce` / `Dcims-X-Timestamp` / `Dcims-X-Sign`；
 * - 签名两次 HMAC-SHA256：先把报文（键名递归升序后的 JSON 原文）用 AppSecret 摘要，
 *   再把 `secret/data/nonce/timestamp` 升序拼查询串用 AppSecret 摘要；
 * - 参与签名的 JSON 与真正发出去的报文必须是同一份字节，否则服务端必然验签失败；
 * - 业务成功判定：`status === 200` 或 `status === 'success'` 或 `code === 200`。
 */
final class DcimsCloudClient implements CloudApiClient
{
    private const DEFAULT_TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly CloudCredentials $credentials,
    ) {}

    public function mode(): CloudApiMode
    {
        return CloudApiMode::Dcims;
    }

    public function credentials(): CloudCredentials
    {
        return $this->credentials;
    }

    public function request(string $method, string $path, array $payload = [], array $query = []): array
    {
        $method = strtoupper($method);
        $nonce = bin2hex(random_bytes(8));
        $timestamp = time();

        if ($method === 'GET') {
            $params = $this->stringify(array_replace($payload, $query));
            $body = $this->canonicalJson($params);
            $headers = $this->headers($nonce, $timestamp, $this->sign($body, $nonce, $timestamp));
            $url = $this->url($path, $params);

            return $this->decode($this->send('GET', $url, $headers, null));
        }

        $body = $this->canonicalJson($payload);
        $headers = $this->headers($nonce, $timestamp, $this->sign($body, $nonce, $timestamp));

        return $this->decode($this->send($method, $this->url($path, $query), $headers, $body));
    }

    /**
     * 连通性/凭据校验：新版本接口用 `GET /test` 探活。
     */
    public function ping(): bool
    {
        $body = $this->request('GET', '/test');

        return self::isSuccess($body);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function isSuccess(array $body): bool
    {
        if (array_key_exists('status', $body)) {
            if ($body['status'] === 200 || $body['status'] === 'success') {
                return true;
            }
        }

        return isset($body['code']) && (int) $body['code'] === 200;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function messageOf(array $body, string $fallback = '魔方云未返回错误信息'): string
    {
        foreach (['msg', 'message', 'error'] as $key) {
            $value = $body[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $fallback;
    }

    /**
     * 参与签名的 JSON 原文：空报文为 `{}`，否则键名递归升序后不转义斜杠与中文。
     *
     * 返回的字符串同时用于签名和请求体，保证两端字节完全一致。
     *
     * @param  array<string, mixed>  $payload
     */
    private function canonicalJson(array $payload): string
    {
        if ($payload === []) {
            return '{}';
        }

        $sorted = $this->sortRecursive($payload);
        $encoded = json_encode($sorted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return is_string($encoded) ? $encoded : '{}';
    }

    private function sign(string $canonicalJson, string $nonce, int $timestamp): string
    {
        $dataHash = hash_hmac('sha256', $canonicalJson, $this->credentials->secret);

        $signData = [
            'secret' => $this->credentials->secret,
            'data' => $dataHash,
            'nonce' => $nonce,
            'timestamp' => $timestamp,
        ];
        ksort($signData);

        return hash_hmac('sha256', http_build_query($signData), $this->credentials->secret);
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $nonce, int $timestamp, string $sign): array
    {
        return [
            'Content-Type' => 'application/json',
            'Dcims-X-Appid' => $this->credentials->account,
            'Dcims-X-Nonce' => $nonce,
            'Dcims-X-Timestamp' => (string) $timestamp,
            'Dcims-X-Sign' => $sign,
        ];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function send(string $method, string $url, array $headers, ?string $body): Response
    {
        try {
            if ($method === 'GET') {
                return $this->http()->withHeaders($headers)->timeout(self::DEFAULT_TIMEOUT_SECONDS)->get($url);
            }

            $pending = $this->http()->withHeaders($headers)->timeout(self::DEFAULT_TIMEOUT_SECONDS);

            return $body === null
                ? $pending->send($method, $url)
                : $pending->withBody($body, 'application/json')->send($method, $url);
        } catch (ConnectionException $exception) {
            throw new CloudApiException('无法连接魔方云管理系统：'.$exception->getMessage(), null, CloudApiMode::Dcims);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->body(), true);

        if (! is_array($decoded)) {
            throw new CloudApiException(
                '魔方云返回的不是有效 JSON（HTTP '.$response->status().'），请确认地址是魔方云站点根地址而不是面板页面地址',
                $response->status(),
                CloudApiMode::Dcims
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
        $url = $this->credentials->baseUrl.'/api/v1/'.ltrim($path, '/');

        if ($query !== []) {
            $url .= '?'.http_build_query($query);
        }

        return $url;
    }

    /**
     * 新版本接口的 GET 参数会被当成字符串参与签名。
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function stringify(array $params): array
    {
        $result = [];
        foreach ($params as $key => $value) {
            $result[$key] = is_scalar($value) || $value === null ? (string) $value : $value;
        }

        return $result;
    }

    /**
     * 键名递归升序（与魔方财务同一套规则）：关联数组排序，列表保持原序，但元素继续下钻。
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function sortRecursive(array $data): array
    {
        $isList = array_keys($data) === range(0, count($data) - 1);
        if (! $isList) {
            ksort($data);
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortRecursive($value);
            }
        }

        return $data;
    }
}
