<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund\Data;

/**
 * 一次上游调用的结果（只读值对象）。
 *
 * 为什么要把「上游返回失败」和「结果未知」分成两个 outcome：
 * 上游业务失败（`code != 200`）代表申请**确实没建成**，闸门可以释放；
 * 而网络超时 / 连接中断 / 5xx 非 JSON 响应代表**我们不知道上游建没建**，
 * 这时释放闸门会让用户重提造出第二笔上游申请。两者必须走不同分支。
 */
final class AutoRefundApiResult
{
    /** 上游 `code = 200`：业务成功 */
    public const OUTCOME_OK = 'ok';

    /** 上游明确返回非 200 的业务码：请求到达了上游，业务条件不满足 */
    public const OUTCOME_REJECTED = 'rejected';

    /** 结果未知：超时、连接失败、响应无法解析 */
    public const OUTCOME_UNKNOWN = 'unknown';

    /** 本地未就绪（地址或密钥缺失）：请求根本没发出去 */
    public const OUTCOME_NOT_READY = 'not_ready';

    /**
     * @param  array<string, mixed>  $request  脱敏后的请求摘要
     * @param  array<string, mixed>  $response  脱敏后的响应摘要
     * @param  array<string, mixed>  $data  解析出的 data 段（不做落库）
     */
    public function __construct(
        public readonly string $outcome,
        public readonly string $action,
        public readonly ?int $code,
        public readonly string $upstreamMessage,
        public readonly ?string $traceId,
        public readonly array $request,
        public readonly array $response,
        public readonly ?int $httpStatus,
        public readonly string $message,
        public readonly ?string $localTraceId = null,
        public readonly ?string $upstreamServerTime = null,
        public readonly array $data = [],
    ) {}

    public static function notReady(string $action, string $message): self
    {
        return new self(
            outcome: self::OUTCOME_NOT_READY,
            action: $action,
            code: null,
            upstreamMessage: '',
            traceId: null,
            request: [],
            response: [],
            httpStatus: null,
            message: $message,
        );
    }

    public function isOk(): bool
    {
        return $this->outcome === self::OUTCOME_OK;
    }

    public function isRejected(): bool
    {
        return $this->outcome === self::OUTCOME_REJECTED;
    }

    /**
     * 结果未知：调用方必须保住幂等闸门，不能当作「失败可重提」。
     */
    public function isUnknown(): bool
    {
        return $this->outcome === self::OUTCOME_UNKNOWN;
    }

    public function isNotReady(): bool
    {
        return $this->outcome === self::OUTCOME_NOT_READY;
    }

    /**
     * 写进 `auto_refund_applications.response_meta` 的脱敏摘要。
     *
     * 只留排错必需的信息：动作、业务码、上游报文、trace、HTTP 状态、本机生成的
     * 关联 ID（上游没给 trace_id 时用它对齐日志）。绝不包含 api_key 与上游全量 data。
     *
     * @return array<string, mixed>
     */
    public function toMeta(): array
    {
        return [
            'outcome' => $this->outcome,
            'action' => $this->action,
            'http_status' => $this->httpStatus,
            'code' => $this->code,
            'msg' => mb_substr($this->upstreamMessage, 0, 255),
            'trace_id' => $this->traceId,
            'local_trace_id' => $this->localTraceId,
            'upstream_server_time' => $this->upstreamServerTime,
            'request' => $this->request,
            'response' => $this->response,
        ];
    }

    /**
     * 上游返回的 `data` 里的机器列表；`data` 不是列表时返回空数组。
     *
     * @return array<int, array<string, mixed>>
     */
    public function hosts(): array
    {
        $rows = $this->data;

        if (! array_is_list($rows)) {
            // 兼容上游把主机列表包在 data.hosts / data.list 里的形态
            foreach (['hosts', 'list', 'items'] as $key) {
                if (isset($rows[$key]) && is_array($rows[$key])) {
                    $rows = $rows[$key];

                    break;
                }
            }
        }

        if (! is_array($rows)) {
            return [];
        }

        $hosts = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $hosts[] = $row;
            }
        }

        return $hosts;
    }
}
