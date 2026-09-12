<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\TuraOpenApi\Lib;

use App\Exceptions\BusinessException;
use App\Models\Supplier;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * TuraIDC 开放接口 HTTP 客户端。
 *
 * 对接上游实例的 /api/v2/open 网关：Bearer API 密钥认证，统一响应信封
 * {code, message, data, timestamp}（code=0 为成功）。HTTP 401/429 与业务
 * 错误码分别映射为可定位的中文业务异常；api_key 为空前置拒服务（密钥缺省即拒绝）。
 */
final class TuraOpenApiClient
{
    private const DEFAULT_TIMEOUT_SECONDS = 30;

    private const DEFAULT_CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * 发起 GET 请求，成功时返回响应信封 data 部分。
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(Supplier $supplier, string $uri, array $query = []): array
    {
        return $this->request($supplier, 'GET', $uri, $query);
    }

    /**
     * 发起 POST 请求（JSON 体），成功时返回响应信封 data 部分。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(Supplier $supplier, string $uri, array $payload = []): array
    {
        return $this->request($supplier, 'POST', $uri, [], $payload);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function request(Supplier $supplier, string $method, string $uri, array $query = [], array $payload = []): array
    {
        $baseUrl = $this->resolveBaseUrl($supplier);
        $apiKey = trim((string) $supplier->getAttribute('api_key'));

        // 密钥缺省即拒绝：API Key 为空时上游必然 401，直接给出可定位的中文提示
        if ($apiKey === '') {
            throw new BusinessException('上游开放接口 API 密钥未配置，请先补全供应商凭据', 42200);
        }

        $method = strtoupper(trim($method));
        $request = Http::baseUrl($baseUrl)
            ->acceptJson()
            ->withToken($apiKey)
            ->connectTimeout(self::DEFAULT_CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::DEFAULT_TIMEOUT_SECONDS);

        try {
            $response = $method === 'GET'
                ? $request->get($uri, $query)
                : $request->post($uri, $payload);
        } catch (\Throwable $exception) {
            $this->logFailure($supplier, $method, $uri, $exception->getMessage());

            throw new BusinessException('上游开放接口连接失败，请稍后重试或联系管理员', 42200);
        }

        // 429 优先判：业务信封之外的全局限流响应（中文 message 由网关渲染）
        if ($response->status() === 429) {
            throw new BusinessException('上游开放接口限流，请稍后重试', 42200);
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            $this->logFailure($supplier, $method, $uri, 'http '.$response->status().' 非 JSON 响应');

            throw new BusinessException('上游开放接口返回异常，请稍后重试', 42200);
        }

        $code = (int) ($decoded['code'] ?? -1);

        if ($response->status() === 401 || $code === 40100) {
            throw new BusinessException('上游开放接口认证失败，请检查供应商绑定的 API 密钥', 42200);
        }

        if ($code !== 0) {
            $message = trim((string) ($decoded['message'] ?? '')) ?: '上游开放接口请求失败';

            throw new BusinessException($message, 42200);
        }

        $data = $decoded['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    private function resolveBaseUrl(Supplier $supplier): string
    {
        $baseUrl = rtrim(trim((string) $supplier->getAttribute('api_url')), '/');

        if ($baseUrl === '') {
            throw new BusinessException('上游开放接口地址未配置', 42200);
        }

        return $baseUrl;
    }

    private function logFailure(Supplier $supplier, string $method, string $uri, string $reason): void
    {
        // 日志不含 API 密钥与完整响应体，仅保留定位所需的最小上下文
        Log::warning('[TuraIDC 开放接口] 请求失败', [
            'supplier_id' => (int) $supplier->id,
            'method' => $method,
            'uri' => $uri,
            'reason' => $reason,
        ]);
    }
}
