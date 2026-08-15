<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Support\SensitiveDataSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminLogDetailResource extends JsonResource
{
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
        $row = is_array($this->resource) ? $this->resource : [];
        $fields = is_array($row['fields'] ?? null) ? $row['fields'] : [];
        $channel = (string) ($row['channel'] ?? '');
        $rawNotification = $this->isRawNotificationChannel($channel);

        $payload = [
            'id' => (string) ($row['id'] ?? $fields['id'] ?? ''),
            'channel' => $channel,
            'source' => (string) ($row['source'] ?? $fields['source'] ?? ''),
            'fields' => $this->sanitizeFields($fields, $rawNotification),
            'message' => $rawNotification ? (string) ($row['message'] ?? '') : SensitiveDataSanitizer::sanitizeText((string) ($row['message'] ?? '')),
            'context' => $rawNotification ? ($row['context'] ?? []) : SensitiveDataSanitizer::sanitize($row['context'] ?? []),
            'created_at' => $row['created_at'] ?? $fields['created_at'] ?? null,
        ];

        return $rawNotification ? $payload : $this->dropSensitiveKeys($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizeFields(array $fields, bool $raw = false): array
    {
        if ($raw) {
            return $fields;
        }

        $sanitized = [];

        foreach ($fields as $key => $value) {
            if (! is_string($key) || $this->isSensitiveKey($key)) {
                continue;
            }

            if (in_array($key, self::PRIVACY_PROJECTED_FIELDS, true)) {
                $sanitized[$key] = $value;

                continue;
            }

            if (in_array($key, self::BUSINESS_IDENTIFIER_FIELDS, true)) {
                $sanitized[$key] = is_string($value) ? SensitiveDataSanitizer::sanitizeText($value) : $value;

                continue;
            }

            $sanitized[$key] = SensitiveDataSanitizer::sanitize($value, $key);
        }

        return $sanitized;
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
