<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\TuraOpenApi\Lib;

use App\Exceptions\BusinessException;
use App\Models\Supplier;
use Illuminate\Http\Client\Response;
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
     * 访问上游站点的公开目录接口（/api/v2/site/*）。
     *
     * 该系列接口面向官网访客，不需要、也不应该带 API 密钥——带上反而可能被网关
     * 当成无效凭据处理。开放接口 /api/v2/open/products 只投影 id/name/product_type/stock，
     * 而站点目录带完整的一/二/三级货架分组，故用它来补全商品分组（见 TuraOpenApi）。
     *
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function getPublic(Supplier $supplier, string $uri, array $query = []): array
    {
        $baseUrl = $this->resolveBaseUrl($supplier);

        try {
            $response = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->connectTimeout(self::DEFAULT_CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::DEFAULT_TIMEOUT_SECONDS)
                ->get($uri, $query);
        } catch (\Throwable $exception) {
            $this->logFailure($supplier, 'GET', $uri, $exception->getMessage());

            throw new BusinessException('上游站点目录接口连接失败', 42200);
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            $this->logFailure($supplier, 'GET', $uri, 'http '.$response->status().' 非 JSON 响应');

            throw new BusinessException('上游站点目录接口返回异常', 42200);
        }

        if ((int) ($decoded['code'] ?? -1) !== 0) {
            $message = trim((string) ($decoded['message'] ?? '')) ?: '上游站点目录接口请求失败';

            throw new BusinessException($message, 42200);
        }

        $data = $decoded['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    /**
     * 上游站点公开目录的 POST 接口（无需 API 密钥）。
     *
     * 用于实时报价：POST /api/v2/site/products/{id}/quote。与 getPublic 一样不携带
     * 密钥——站点目录对官网访客开放，带密钥反而可能被网关当成无效凭据拒绝。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function postPublic(Supplier $supplier, string $uri, array $payload = []): array
    {
        $baseUrl = $this->resolveBaseUrl($supplier);

        try {
            $response = Http::baseUrl($baseUrl)
                ->acceptJson()
                ->connectTimeout(self::DEFAULT_CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::DEFAULT_TIMEOUT_SECONDS)
                ->post($uri, $payload);
        } catch (\Throwable $exception) {
            $this->logFailure($supplier, 'POST', $uri, $exception->getMessage());

            throw new BusinessException('上游站点接口连接失败', 42200);
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            $this->logFailure($supplier, 'POST', $uri, 'http '.$response->status().' 非 JSON 响应');

            throw new BusinessException('上游站点接口返回异常', 42200);
        }

        if ((int) ($decoded['code'] ?? -1) !== 0) {
            $message = trim((string) ($decoded['message'] ?? '')) ?: '上游站点接口请求失败';

            throw new BusinessException($message, 42200);
        }

        $data = $decoded['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * 并发执行多个 GET 请求。
     *
     * 批量导入需要对每个商品逐个询价，串行时 RTT 线性累加
     * （N 个商品 × 4 个计费周期，选 5 个即 20 次串行 ≈ 10s），
     * 走 Http::pool 并发可把墙钟时间压回单次 RTT 量级。
     *
     * 鉴权与超时口径与 request() 完全一致；单个请求失败不影响其他请求，
     * 失败项不返回结果，由调用方决定是否走串行兜底。
     *
     * @param  array<int|string, string>  $requests  alias => uri（query 通过 $queryByAlias 传入）
     * @param  array<int|string, array<string, mixed>>  $queryByAlias
     * @return array<int|string, array<string, mixed>>  alias => data（仅成功项）
     */
    public function getMany(Supplier $supplier, array $requests, array $queryByAlias = []): array
    {
        if ($requests === []) {
            return [];
        }

        $baseUrl = $this->resolveBaseUrl($supplier);
        $apiKey = trim((string) $supplier->getAttribute('api_key'));

        if ($apiKey === '') {
            throw new BusinessException('上游开放接口 API 密钥未配置，请先补全供应商凭据', 42200);
        }

        try {
            $responses = Http::pool(static function ($pool) use ($baseUrl, $apiKey, $requests, $queryByAlias): void {
                foreach ($requests as $alias => $uri) {
                    $pool->as((string) $alias)
                        ->baseUrl($baseUrl)
                        ->acceptJson()
                        ->withToken($apiKey)
                        ->connectTimeout(self::DEFAULT_CONNECT_TIMEOUT_SECONDS)
                        ->timeout(self::DEFAULT_TIMEOUT_SECONDS)
                        ->get($uri, (array) ($queryByAlias[$alias] ?? []));
                }
            });
        } catch (\Throwable $exception) {
            $this->logFailure($supplier, 'GET', 'pool(' . count($requests) . ')', $exception->getMessage());

            return [];
        }

        $results = [];

        // Http::pool 返回的数组元素本身就是 Response 实例
        foreach ($responses as $alias => $response) {
            if (! $response instanceof Response) {
                continue;
            }

            $decoded = $response->json();

            if (! is_array($decoded)) {
                continue;
            }

            if ($response->status() === 401 || (int) ($decoded['code'] ?? -1) === 40100) {
                $this->logFailure($supplier, 'GET', (string) $alias, '认证失败');

                continue;
            }

            if ((int) ($decoded['code'] ?? -1) !== 0) {
                $this->logFailure($supplier, 'GET', (string) $alias, (string) ($decoded['message'] ?? '上游返回失败'));

                continue;
            }

            $data = $decoded['data'] ?? [];

            if (is_array($data)) {
                $results[$alias] = $data;
            }
        }

        return $results;
    }

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
