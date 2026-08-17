<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Lib;

use App\Exceptions\BusinessException;
use App\Models\Supplier;
use App\Services\Integrations\Support\ProviderErrorMapper;
use App\Services\Upstream\ProviderKey;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Support\Facades\Log;

final class ZjmfStatusService
{
    private const RUNTIME_UNAVAILABLE_UPSTREAM_STATUSES = ['suspended', 'cancelled', 'deleted'];

    private const RUNTIME_OPERATION_NOT_ALLOWED_KEYWORDS = [
        '不能执行该操作',
        '无法执行该操作',
        '该操作无法执行',
        '当前状态不允许执行该操作',
    ];

    private const RUNTIME_HOST_MISSING_KEYWORDS = [
        '主机不存在',
        'host not found',
    ];

    public function __construct(
        private readonly ZjmfFinanceTransport $transport,
    ) {}

    public function syncServiceStatuses(Supplier $supplier, array $items, int $chunkSize = 10): array
    {
        $jwt = $this->transport->login($supplier);
        $results = [];

        foreach (array_chunk($items, max(1, $chunkSize)) as $chunk) {
            $requests = $this->buildBatchRequests($chunk);
            if ($requests === []) {
                continue;
            }

            $responses = $this->transport->parallelGet($supplier, $requests, $jwt);
            if ($this->shouldRetryWithFreshJwt($responses)) {
                $jwt = $this->transport->refreshJwt($supplier);
                $responses = $this->transport->parallelGet($supplier, $requests, $jwt);
            }

            foreach ($chunk as $item) {
                $serviceId = (int) ($item['service_id'] ?? 0);
                $hostId = (int) ($item['host_id'] ?? 0);
                if ($serviceId <= 0) {
                    continue;
                }

                try {
                    $host = $this->extractHostPayload($responses['detail_'.$serviceId] ?? []);
                    $runtimeResponse = $this->fetchRuntimeStatus($supplier, $hostId, $jwt);
                    $runtime = $this->extractRuntimePayload($runtimeResponse, $host);

                    $results[$serviceId] = [
                        'host' => $this->normalizeHost($host),
                        'runtime' => $this->normalizeRuntime($runtime),
                    ];
                } catch (\Throwable $exception) {
                    $results[$serviceId] = [
                        'error' => $exception->getMessage(),
                    ];
                }
            }
        }

        // 不把会话 JWT 放进返回值：上游会话 token 是敏感凭据，调用方（状态同步等）
        // 若整体记日志/缓存会把会话泄露落盘；JWT 只在方法内部使用与维护。
        return [
            'services' => $results,
        ];
    }

    private function buildBatchRequests(array $items): array
    {
        $requests = [];

        foreach ($items as $item) {
            $serviceId = (int) ($item['service_id'] ?? 0);
            $hostId = (int) ($item['host_id'] ?? 0);
            if ($serviceId <= 0 || $hostId <= 0) {
                continue;
            }

            $requests['detail_'.$serviceId] = [
                'uri' => '/host/header',
                'query' => [
                    'host_id' => $hostId,
                    'source' => 'API',
                ],
            ];
        }

        return $requests;
    }

    private function fetchRuntimeStatus(Supplier $supplier, int $hostId, string &$jwt): array
    {
        $response = $this->requestRuntimeStatus($supplier, $hostId, $jwt);
        if ($this->isUnauthorizedPayload($response)) {
            $jwt = $this->transport->refreshJwt($supplier);
            $response = $this->requestRuntimeStatus($supplier, $hostId, $jwt);
        }

        return [
            'status_code' => 0,
            'response' => $response,
        ];
    }

    private function requestRuntimeStatus(Supplier $supplier, int $hostId, string $jwt): array
    {
        return $this->transport->post(
            $supplier,
            '/provision/default',
            [
                'id' => $hostId,
                'func' => 'status',
            ],
            $jwt,
            ['content-type: application/x-www-form-urlencoded']
        );
    }

    private function isUnauthorizedPayload(array $response): bool
    {
        return (int) ($response['status'] ?? $response['code'] ?? $response['status_code'] ?? 0) === 401;
    }

    private function shouldRetryWithFreshJwt(array $responses): bool
    {
        foreach ($responses as $response) {
            if (! is_array($response)) {
                continue;
            }

            if ((int) ($response['status_code'] ?? 0) === 401) {
                return true;
            }

            $payload = is_array($response['response'] ?? null) ? $response['response'] : [];
            $status = (int) ($payload['status'] ?? $payload['code'] ?? $payload['status_code'] ?? 0);

            if ($status === 401) {
                return true;
            }
        }

        return false;
    }

    private function extractHostPayload(array $response): array
    {
        $payload = $this->extractParallelPayload($response, '读取主机详情');
        $host = $this->transport->normalizeHostDetailPayload($payload);

        if ($host === []) {
            throw new BusinessException('读取主机详情失败：上游未返回实例数据', 42200);
        }

        return $host;
    }

    private function extractRuntimePayload(array $response, array $host): array
    {
        try {
            return $this->extractParallelPayload($response, '读取电源状态');
        } catch (\Throwable $exception) {
            $runtimeUnavailableContext = $this->resolveRuntimeUnavailableContext($response, $host);

            if ($runtimeUnavailableContext !== null) {
                Log::debug('[ZJMF 财务状态同步] 电源状态不可用，降级为仅同步实例详情', [
                    'reason' => $runtimeUnavailableContext['reason'],
                    'upstream_status' => $runtimeUnavailableContext['upstream_status'],
                ]);

                return [];
            }

            throw $exception;
        }
    }

    private function extractParallelPayload(array $response, string $action): array
    {
        if ($response === []) {
            throw new BusinessException($action.'失败：未获取到有效响应', 42200);
        }

        $error = trim((string) ($response['error'] ?? ''));
        if ($error !== '') {
            throw new BusinessException($action.'失败：'.$error, 42200);
        }

        $payload = is_array($response['response'] ?? null) ? $response['response'] : [];
        if ($payload === []) {
            throw new BusinessException($action.'失败：响应为空', 42200);
        }

        $this->assertSuccess($payload, $action);

        return is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
    }

    private function assertSuccess(array $response, string $action): void
    {
        $status = (int) ($response['status'] ?? $response['code'] ?? $response['status_code'] ?? 0);

        if (in_array($status, [200, 1001], true)) {
            return;
        }

        $message = trim((string) ($response['msg'] ?? $response['message'] ?? ''));
        Log::warning('[ZJMF 财务状态同步] 返回失败', [
            'action' => $action,
            'status' => $status,
            'message' => SensitiveDataSanitizer::sanitizeText($message),
        ]);

        throw new BusinessException(app(ProviderErrorMapper::class)->toUserMessage(ProviderKey::ZJMF_FINANCE_API, $action, $message), 42200);
    }

    private function resolveRuntimeUnavailableContext(array $response, array $host): ?array
    {
        $upstreamStatus = strtolower(trim((string) ($host['domainstatus'] ?? '')));
        $message = $this->extractRuntimeFailureMessage($response);
        if ($message === '') {
            return null;
        }

        $payload = is_array($response['response'] ?? null) ? $response['response'] : [];
        $httpStatus = (int) ($response['status_code'] ?? 0);
        $businessStatus = (int) ($payload['status'] ?? $payload['code'] ?? $payload['status_code'] ?? 0);

        if ($this->messageContainsAny($message, self::RUNTIME_OPERATION_NOT_ALLOWED_KEYWORDS)) {
            return [
                'upstream_status' => $upstreamStatus,
                'reason' => 'operation_not_allowed',
                'message' => $message,
                'http_status' => $httpStatus,
                'business_status' => $businessStatus,
            ];
        }

        if (! $this->isExpectedRuntimeFailureStatus($httpStatus, $businessStatus)) {
            return null;
        }

        if (
            in_array($upstreamStatus, self::RUNTIME_UNAVAILABLE_UPSTREAM_STATUSES, true)
            && in_array($upstreamStatus, ['cancelled', 'deleted'], true)
            && $this->messageContainsAny($message, self::RUNTIME_HOST_MISSING_KEYWORDS)
        ) {
            return [
                'upstream_status' => $upstreamStatus,
                'reason' => 'host_missing_after_termination',
                'message' => $message,
                'http_status' => $httpStatus,
                'business_status' => $businessStatus,
            ];
        }

        return null;
    }

    private function extractRuntimeFailureMessage(array $response): string
    {
        $errorMessage = trim((string) ($response['error'] ?? ''));
        if ($errorMessage !== '') {
            return $errorMessage;
        }

        $payload = is_array($response['response'] ?? null) ? $response['response'] : [];

        return trim((string) ($payload['msg'] ?? $payload['message'] ?? ''));
    }

    private function isExpectedRuntimeFailureStatus(int $httpStatus, int $businessStatus): bool
    {
        $expectedStatuses = [400, 403, 404, 422];

        if ($httpStatus > 0 && in_array($httpStatus, $expectedStatuses, true)) {
            return true;
        }

        if ($businessStatus > 0 && in_array($businessStatus, $expectedStatuses, true)) {
            return true;
        }

        return $httpStatus === 0 && $businessStatus === 0;
    }

    private function messageContainsAny(string $message, array $keywords): bool
    {
        foreach ($keywords as $keyword) {
            if (mb_stripos($message, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    private function normalizeHost(array $host): array
    {
        return [
            ...$host,
            'domainstatus' => (string) ($host['domainstatus'] ?? ''),
            'product_id' => (int) (($host['product_id'] ?? 0) ?: 0),
            'product_name' => trim((string) ($host['product_name'] ?? '')),
            'domain' => trim((string) ($host['domain'] ?? '')),
            'dedicatedip' => trim((string) ($host['dedicatedip'] ?? '')),
            'assignedips' => is_array($host['assignedips'] ?? null) ? $host['assignedips'] : [],
            'config_option' => is_array($host['config_option'] ?? null) ? $host['config_option'] : [],
            'os' => trim((string) ($host['os'] ?? '')),
            'username' => trim((string) ($host['username'] ?? '')),
            'password' => (string) ($host['password'] ?? ''),
            'port' => (int) (($host['port'] ?? 0) ?: 0),
            'internalip' => trim((string) ($host['internalip'] ?? $host['privateip'] ?? '')),
            'nextduedate' => $host['nextduedate'] ?? null,
        ];
    }

    private function normalizeRuntime(array $runtime): array
    {
        if ($runtime === []) {
            return [];
        }

        return [
            ...$runtime,
            'status' => (string) ($runtime['status'] ?? ''),
            'des' => (string) ($runtime['des'] ?? ''),
        ];
    }
}
