<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Support\SensitiveDataSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminLogSummaryResource extends JsonResource
{
    private const FIELD_KEYS = [
        'id',
        'source',
        'level',
        'status',
        'result_status',
        'method',
        'path',
        'module',
        'action',
        'user_type',
        'user_id',
        'actor_type',
        'actor_id',
        'actor_name',
        'admin_username',
        'admin_nickname',
        'role_name',
        'subject_type',
        'subject_id',
        'phone',
        'to_email',
        'template_code',
        'subject',
        'provider',
        'request_id',
        'gateway',
        'gateway_key',
        'driver_key',
        'plugin_key',
        'provider_key',
        'plugin_id',
        'trace_id',
        'out_trade_no',
        'trade_no',
        'invoice_id',
        'task_key',
        'task_title',
        'task_name',
        'duration_ms',
        'error_code',
        'created_at',
        'updated_at',
        'sent_at',
        'time',
        'started_at',
        'finished_at',
        'ticket_id',
        'ticket_reply_id',
        'direction',
        'operation',
        'event',
        'reason_code',
        'supplier_id',
        'http_status',
        'occurred_at',
    ];

    private const PRIVACY_PROJECTED_FIELDS = [
        'phone',
        'to_email',
    ];

    private const BUSINESS_IDENTIFIER_FIELDS = [
        'gateway_key',
        'driver_key',
        'plugin_key',
    ];

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $channel = (string) ($this->resource['channel'] ?? '');
        $row = is_array($this->resource['row'] ?? null) ? $this->resource['row'] : [];
        $rawNotification = $this->isRawNotificationChannel($channel);

        $payload = [
            'id' => (string) ($row['id'] ?? ''),
            'channel' => $channel,
        ];

        foreach (self::FIELD_KEYS as $key) {
            if (array_key_exists($key, $row) && ! $this->isSensitiveKey($key)) {
                $payload[$key] = $this->sanitizeField($key, $row[$key], $rawNotification);
            }
        }

        $payload['message_excerpt'] = $this->excerpt($this->messageText($row), sanitize: ! $rawNotification);
        $payload['context_excerpt'] = $this->excerpt($this->contextText($row, $rawNotification), 240, ! $rawNotification);
        $payload['error_excerpt'] = $this->excerpt((string) ($row['error_msg'] ?? $row['error_message'] ?? ''), 200, ! $rawNotification);

        return $this->dropSensitiveKeys($payload);
    }

    private function messageText(array $row): string
    {
        foreach (['message', 'content', 'description', 'action', 'error_msg', 'error_message', 'raw'] as $key) {
            $value = trim((string) ($row[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function contextText(array $row, bool $raw = false): string
    {
        $context = [];
        foreach (['detail', 'context', 'params', 'request_data', 'response_data', 'request_meta', 'response_meta', 'summary'] as $key) {
            if (array_key_exists($key, $row)) {
                $context[$key] = $row[$key];
            }
        }

        if ($context === []) {
            return '';
        }

        return (string) json_encode($raw ? $context : $this->dropSensitiveKeys($context), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function excerpt(string $value, int $limit = 160, bool $sanitize = true): string
    {
        $text = $sanitize ? SensitiveDataSanitizer::sanitizeText($value) : $value;
        $value = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit).'...';
    }

    private function sanitizeField(string $key, mixed $value, bool $raw = false): mixed
    {
        if ($raw) {
            return $value;
        }

        if (in_array($key, self::PRIVACY_PROJECTED_FIELDS, true)) {
            return $value;
        }

        if (in_array($key, self::BUSINESS_IDENTIFIER_FIELDS, true)) {
            return is_string($value) ? SensitiveDataSanitizer::sanitizeText($value) : $value;
        }

        return SensitiveDataSanitizer::sanitize($value, $key);
    }

    // 通知通道（短信/邮件）日志对管理端返回完整原文，不做脱敏（项目红线：管理员需真实审计信息）
    private function isRawNotificationChannel(string $channel): bool
    {
        return in_array($channel, ['sms', 'email'], true);
    }

    /**
     * @return array<string, mixed>
     */
    private function dropSensitiveKeys(array $payload): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                continue;
            }

            $result[$key] = is_array($value) ? $this->dropSensitiveKeys($value) : $value;
        }

        return $result;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response'] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
