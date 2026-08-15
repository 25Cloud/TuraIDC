<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Models\ScheduleTaskRun;
use App\Support\SensitiveDataSanitizer;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminScheduleTaskRunResource extends JsonResource
{
    private const MAX_DEPTH = 3;

    private const MAX_ITEMS = 20;

    private const MAX_TEXT = 2000;

    /**
     * 运行台账只允许返回可解释的状态字段，不暴露 Job payload、异常对象或第三方原始响应。
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $run = $this->resource;
        if (! $run instanceof ScheduleTaskRun) {
            return [];
        }

        return [
            'id' => (int) $run->id,
            'task_key' => (string) ($this->text($run->task_key) ?? ''),
            'task_name' => (string) ($this->text($run->task_name) ?? ''),
            'rule_description' => $this->text($run->rule_description),
            'source' => (string) ($this->text($run->source) ?? ''),
            'status' => (string) ($this->text($run->status) ?? ''),
            'status_label' => ScheduleTaskRun::statusLabel((string) ($run->status ?? '')),
            'attempt' => max(1, (int) ($run->attempt ?? 1)),
            'parent_run_id' => $run->parent_run_id !== null ? (int) $run->parent_run_id : null,
            'queue' => $this->text($run->queue),
            'duration_ms' => $run->duration_ms !== null ? (int) $run->duration_ms : null,
            'queued_at' => $this->date($run->queued_at),
            'started_at' => $this->date($run->started_at),
            'finished_at' => $this->date($run->finished_at),
            'manual_retry_at' => $this->date($run->manual_retry_at),
            'manual_retry_by' => $run->manual_retry_by !== null ? (int) $run->manual_retry_by : null,
            'summary' => $this->safeValue($run->summary, 0),
            'error_msg' => $this->text($run->error_msg),
        ];
    }

    private function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = SensitiveDataSanitizer::sanitizeText((string) $value);
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);

        return mb_strlen($value) > self::MAX_TEXT
            ? mb_substr($value, 0, self::MAX_TEXT).'...'
            : $value;
    }

    private function date(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d H:i:s') : null;
    }

    private function safeValue(mixed $value, int $depth): mixed
    {
        if ($depth >= self::MAX_DEPTH) {
            if (is_array($value)) {
                return '[truncated]';
            }

            if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
                return $value;
            }

            return $this->text($value);
        }

        if (! is_array($value)) {
            if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
                return $value;
            }

            return $this->text($value);
        }

        $result = [];
        $count = 0;
        foreach ($value as $key => $item) {
            if ($count >= self::MAX_ITEMS) {
                break;
            }

            if (is_string($key) && $this->isSensitiveKey($key)) {
                continue;
            }

            $result[$key] = $this->safeValue($item, $depth + 1);
            $count++;
        }

        return array_is_list($value) ? array_values($result) : $result;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(trim($key));

        foreach ([
            'password',
            'secret',
            'api_key',
            'raw_response',
            'third_party_response',
            'token',
            'authorization',
            'cookie',
            'signature',
            'private_key',
            'public_key',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }
}
