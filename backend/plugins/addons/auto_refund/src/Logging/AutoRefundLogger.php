<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund\Logging;

use Throwable;

/**
 * 自助退款的调用留痕（专用日志，不落库）。
 *
 * 为什么不是新建日志表：插件规范第 6 节禁止插件自建业务运行日志表，
 * 平台已有 `integration_plugin_runtime_logs` 承载插件 action 级留痕；
 * 本插件要记的是「上游 HTTP 调用的请求/响应摘要」，属于排错文本，
 * 因此按 zjmf_bridge 的既有做法写到独立日志文件 `storage/logs/auto_refund.log`。
 *
 * 硬约束：`api_key` 一律以 `***` 出现，任何调用方都无法把密钥写进日志——
 * 脱敏在这里统一做，而不是指望每个调用点自己记得。
 */
final class AutoRefundLogger
{
    /** 上游返回的 msg 可能很长，日志里只留前 500 字符 */
    private const MESSAGE_LIMIT = 500;

    /** 明确脱敏的字段（大小写不敏感，含包含匹配） */
    private const SECRET_KEYS = ['api_key', 'apikey', 'key', 'secret', 'token', 'password', 'authorization'];

    /**
     * 请求摘要：保留排错必需的字段，密钥替换为掩码。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redactRequest(array $payload): array
    {
        $redacted = [];

        foreach ($payload as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if ($this->isSecretKey($normalizedKey)) {
                $redacted[$key] = '***';

                continue;
            }

            $redacted[$key] = is_scalar($value) || $value === null
                ? $value
                : $this->truncate((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }

        return $redacted;
    }

    /**
     * 响应摘要：只留业务判定用的字段，原文截断。
     *
     * @param  array<string, mixed>  $decoded
     * @return array<string, mixed>
     */
    public function redactResponse(array $decoded): array
    {
        $summary = [
            'code' => $decoded['code'] ?? null,
            'msg' => $this->truncate((string) ($decoded['msg'] ?? $decoded['message'] ?? '')),
            'trace_id' => isset($decoded['trace_id']) ? (string) $decoded['trace_id'] : null,
        ];

        // data 只统计规模：上游主机列表可能很长，也可能带客户侧字段，不整段落盘。
        $data = $decoded['data'] ?? null;
        if (is_array($data)) {
            $summary['data_type'] = array_is_list($data) ? 'list' : 'map';
            $summary['data_count'] = array_is_list($data) ? count($data) : count(array_keys($data));
        } elseif ($data === null) {
            $summary['data_type'] = 'null';
            $summary['data_count'] = 0;
        } else {
            $summary['data_type'] = get_debug_type($data);
            $summary['data_count'] = 0;
        }

        return $summary;
    }

    /**
     * 写一行 JSON 审计日志。日志失败绝不改变业务响应。
     *
     * @param  array<string, mixed>  $context
     */
    public function record(string $action, string $stage, array $context = []): void
    {
        try {
            $payload = $this->withoutSecrets([
                'logged_at' => now()->format('Y-m-d H:i:s'),
                'action' => $action,
                'stage' => $stage,
            ] + $context);

            $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (! is_string($line)) {
                return;
            }

            $directory = storage_path('logs');
            if (! is_dir($directory)) {
                return;
            }

            file_put_contents($directory.DIRECTORY_SEPARATOR.'auto_refund.log', $line.PHP_EOL, FILE_APPEND | LOCK_EX);
        } catch (Throwable) {
            // 留痕不能反过来影响退款业务。
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function withoutSecrets(array $context): array
    {
        foreach ($context as $key => $value) {
            if ($this->isSecretKey(strtolower((string) $key))) {
                $context[$key] = '***';

                continue;
            }

            if (is_array($value)) {
                $context[$key] = $this->withoutSecrets($value);
            }
        }

        return $context;
    }

    private function isSecretKey(string $key): bool
    {
        foreach (self::SECRET_KEYS as $secretKey) {
            if ($key === $secretKey || str_contains($key, $secretKey)) {
                return true;
            }
        }

        return false;
    }

    private function truncate(string $value): string
    {
        return mb_substr($value, 0, self::MESSAGE_LIMIT);
    }
}
