<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;
use TuraIDC\Plugins\Addons\AutoRefund\Data\AutoRefundApiResult;
use TuraIDC\Plugins\Addons\AutoRefund\Logging\AutoRefundLogger;

/**
 * 上游智简魔方财务 AutoRefund `api.php` 的下游调用客户端。
 *
 * 契约（逐条对应 docs/internal/execution-plans/active/auto-refund-plugin-2026-11-01.md）：
 * - `POST {api_base_url}`，请求体必须是 `application/x-www-form-urlencoded`（Http::asForm()），
 *   不接受 JSON body——发 JSON 会被上游直接判参数错误；
 * - 必填 `api_key`（完整密钥）、`timestamp`（Unix 秒，与服务端误差 ≤ 300 秒）、
 *   `action`（`query` / `submit` / `cancel`，新接入必须显式传）；
 * - `submit` / `cancel` 必填 `upstream_product_id`，值取自 `query` 返回的 `data[].hostid`；
 * - **业务结果只看响应 JSON 的 `code`**：200 成功、400/401/403/404/405/500 是业务失败。
 *   多数失败仍是 HTTP 200，所以这里绝不用 HTTP 状态码判业务结果；
 *   `msg` 只做展示，不参与任何判断，也不当作稳定枚举；
 * - `trace_id` 只有部分响应会带，响应没给就留空，不编造；
 * - 超时 / 连不上 / 响应不是 JSON → 「结果未知」，与「上游返回失败」严格分开：
 *   前者不能释放幂等闸门（否则用户重提会造出第二笔上游申请）。
 */
final class AutoRefundApiClient
{
    public const ACTION_QUERY = 'query';

    public const ACTION_SUBMIT = 'submit';

    public const ACTION_CANCEL = 'cancel';

    /** 上游约定的成功业务码 */
    private const CODE_OK = 200;

    public function __construct(
        private readonly AutoRefundSettings $settings,
        private readonly AutoRefundLogger $logger,
    ) {}

    /**
     * 查询上游可见机器列表（`data[].hostid` 是提交/取消时要用的产品标识）。
     */
    public function query(): AutoRefundApiResult
    {
        return $this->post(self::ACTION_QUERY, []);
    }

    /**
     * 提交退款申请。成功只代表「申请已创建」，不代表资金到账。
     */
    public function submit(string $upstreamProductId): AutoRefundApiResult
    {
        $productId = trim($upstreamProductId);
        if ($productId === '') {
            return new AutoRefundApiResult(
                outcome: AutoRefundApiResult::OUTCOME_REJECTED,
                action: self::ACTION_SUBMIT,
                code: null,
                upstreamMessage: '',
                traceId: null,
                request: [],
                response: [],
                httpStatus: null,
                message: '缺少上游产品标识，无法提交退款申请',
            );
        }

        return $this->post(self::ACTION_SUBMIT, ['upstream_product_id' => $productId]);
    }

    /**
     * 取消该产品实例最新一条仍待审核的申请。上游该接口**非幂等**：
     * 第二次调用会返回 400，所以调用方必须按结果推进状态机，不能重试当作成功。
     */
    public function cancel(string $upstreamProductId): AutoRefundApiResult
    {
        $productId = trim($upstreamProductId);
        if ($productId === '') {
            return new AutoRefundApiResult(
                outcome: AutoRefundApiResult::OUTCOME_REJECTED,
                action: self::ACTION_CANCEL,
                code: null,
                upstreamMessage: '',
                traceId: null,
                request: [],
                response: [],
                httpStatus: null,
                message: '缺少上游产品标识，无法取消退款申请',
            );
        }

        return $this->post(self::ACTION_CANCEL, ['upstream_product_id' => $productId]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function post(string $action, array $params): AutoRefundApiResult
    {
        if (! $this->settings->bridgeReady()) {
            return AutoRefundApiResult::notReady($action, '自助退款尚未配置上游地址或 API KEY');
        }

        // 本机组装关联 ID：上游不一定返回 trace_id，日志靠它对齐同一次调用。
        // 它只进本机日志与 response_meta 的 local_trace_id，绝不冒充上游 trace_id。
        $localTraceId = (string) Str::uuid();

        $payload = array_merge($params, [
            'action' => $action,
            'api_key' => $this->settings->apiKey(),
            'timestamp' => time(),
        ]);

        $redactedRequest = $this->logger->redactRequest($payload);
        $timeout = $this->settings->requestTimeoutSeconds();

        try {
            $response = Http::asForm()
                ->timeout($timeout)
                ->connectTimeout(min($timeout, 10))
                ->acceptJson()
                ->post($this->settings->apiBaseUrl(), $payload);
        } catch (ConnectionException $exception) {
            return $this->unknown(
                action: $action,
                message: '上游连接超时或中断，本次结果未知',
                localTraceId: $localTraceId,
                redactedRequest: $redactedRequest,
                error: $exception->getMessage(),
            );
        } catch (Throwable $exception) {
            return $this->unknown(
                action: $action,
                message: '调用上游失败，本次结果未知',
                localTraceId: $localTraceId,
                redactedRequest: $redactedRequest,
                error: $exception->getMessage(),
            );
        }

        $httpStatus = $response->status();
        $serverTime = $this->upstreamServerTime($response);
        $decoded = json_decode($response->body(), true);

        // 响应不是 JSON 对象：无法判定业务结果 → 结果未知。
        // 5xx 也可能是「上游已经建单，但回包归零」，宁可让闸门占住等人工作废。
        if (! is_array($decoded)) {
            return $this->unknown(
                action: $action,
                message: '上游响应无法解析，本次结果未知',
                localTraceId: $localTraceId,
                redactedRequest: $redactedRequest,
                httpStatus: $httpStatus,
                serverTime: $serverTime,
                error: 'non_json_response',
            );
        }

        $code = $this->normalizeCode($decoded['code'] ?? null);
        $upstreamMessage = $this->normalizeMessage($decoded);
        $traceId = $this->normalizeTraceId($decoded);
        $redactedResponse = $this->logger->redactResponse($decoded);

        // 有 JSON 但没有可判定的业务码：同样不能当作业务失败放行。
        if ($code === null) {
            return new AutoRefundApiResult(
                outcome: AutoRefundApiResult::OUTCOME_UNKNOWN,
                action: $action,
                code: null,
                upstreamMessage: $upstreamMessage,
                traceId: $traceId,
                request: $redactedRequest,
                response: $redactedResponse,
                httpStatus: $httpStatus,
                message: '上游响应缺少业务码，本次结果未知',
                localTraceId: $localTraceId,
                upstreamServerTime: $serverTime,
                data: is_array($decoded['data'] ?? null) ? $decoded['data'] : [],
            );
        }

        $result = new AutoRefundApiResult(
            outcome: $code === self::CODE_OK ? AutoRefundApiResult::OUTCOME_OK : AutoRefundApiResult::OUTCOME_REJECTED,
            action: $action,
            code: $code,
            upstreamMessage: $upstreamMessage,
            traceId: $traceId,
            request: $redactedRequest,
            response: $redactedResponse,
            httpStatus: $httpStatus,
            message: $code === self::CODE_OK
                ? '上游已受理'
                : ($upstreamMessage !== '' ? $upstreamMessage : "上游返回业务失败（code {$code}）"),
            localTraceId: $localTraceId,
            upstreamServerTime: $serverTime,
            data: is_array($decoded['data'] ?? null) ? $decoded['data'] : [],
        );

        $this->logger->record($action, $result->outcome, [
            'local_trace_id' => $localTraceId,
            'trace_id' => $traceId,
            'http_status' => $httpStatus,
            'code' => $code,
            'msg' => $upstreamMessage,
            'request' => $redactedRequest,
            'response' => $redactedResponse,
        ]);

        return $result;
    }

    /**
     * @param  array<string, mixed>  $redactedRequest
     */
    private function unknown(
        string $action,
        string $message,
        string $localTraceId,
        array $redactedRequest,
        ?int $httpStatus = null,
        ?string $serverTime = null,
        string $error = '',
    ): AutoRefundApiResult {
        $this->logger->record($action, AutoRefundApiResult::OUTCOME_UNKNOWN, [
            'local_trace_id' => $localTraceId,
            'http_status' => $httpStatus,
            'error' => $error,
            'request' => $redactedRequest,
        ]);

        return new AutoRefundApiResult(
            outcome: AutoRefundApiResult::OUTCOME_UNKNOWN,
            action: $action,
            code: null,
            upstreamMessage: '',
            traceId: null,
            request: $redactedRequest,
            response: [],
            httpStatus: $httpStatus,
            message: $message,
            localTraceId: $localTraceId,
            upstreamServerTime: $serverTime,
        );
    }

    private function normalizeCode(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_bool($value)) {
            return $value ? self::CODE_OK : null;
        }

        if (is_string($value) && is_numeric(trim($value))) {
            return (int) round((float) trim($value));
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function normalizeMessage(array $decoded): string
    {
        $message = $decoded['msg'] ?? $decoded['message'] ?? '';

        if (is_string($message)) {
            return mb_substr(trim($message), 0, 255);
        }

        if (is_scalar($message)) {
            return mb_substr((string) $message, 0, 255);
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function normalizeTraceId(array $decoded): ?string
    {
        $traceId = trim((string) ($decoded['trace_id'] ?? ''));

        return $traceId === '' ? null : mb_substr($traceId, 0, 64);
    }

    /**
     * 上游回包的 `Date` 头即服务端时间，用于后台「时间差」自检；
     * 拿不到就留空，不拿本地时间凑数。
     */
    private function upstreamServerTime(Response $response): ?string
    {
        $header = trim((string) $response->header('Date'));

        return $header === '' ? null : mb_substr($header, 0, 64);
    }
}
