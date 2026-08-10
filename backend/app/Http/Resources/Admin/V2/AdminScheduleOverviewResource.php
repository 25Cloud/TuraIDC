<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Support\SensitiveDataSanitizer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminScheduleOverviewResource extends JsonResource
{
    private const TASK_LIMIT = 50;

    private const LOG_LIMIT = 20;

    private const SETTINGS_LIMIT = 20;

    private const ARRAY_ITEM_LIMIT = 12;

    private const MAX_DEPTH = 3;

    private const TEXT_LIMIT = 500;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $overview = is_array($this->resource) ? $this->resource : [];

        return [
            'environment' => $this->environment((array) ($overview['environment'] ?? [])),
            'tasks' => $this->limitedList($overview['tasks'] ?? [], self::TASK_LIMIT, fn (array $task): array => $this->task($task)),
            'recent_logs' => $this->limitedList($overview['recent_logs'] ?? [], self::LOG_LIMIT, fn (array $log): array => $this->log($log)),
            'settings_snapshot' => $this->limitedList($overview['settings_snapshot'] ?? [], self::SETTINGS_LIMIT, fn (array $item): array => $this->settingsSnapshotItem($item)),
        ];
    }

    /**
     * @param  array<string, mixed>  $environment
     * @return array<string, mixed>
     */
    private function environment(array $environment): array
    {
        return [
            'app_env' => $this->compactScalar($environment['app_env'] ?? null),
            'app_timezone' => $this->compactScalar($environment['app_timezone'] ?? null),
            'queue_driver' => $this->compactScalar($environment['queue_driver'] ?? null),
            'jobs_table_ready' => (bool) ($environment['jobs_table_ready'] ?? false),
            'failed_jobs_table_ready' => (bool) ($environment['failed_jobs_table_ready'] ?? false),
            'pending_jobs' => $environment['pending_jobs'] ?? null,
            'failed_jobs' => $environment['failed_jobs'] ?? null,
            'queue_runtime_mode' => $this->compactScalar($environment['queue_runtime_mode'] ?? null),
            'schedule_mutex' => $this->compactValue($environment['schedule_mutex'] ?? [], 0),
            'automation_config' => $this->compactValue($environment['automation_config'] ?? [], 0),
        ];
    }

    /**
     * @param  array<string, mixed>  $task
     * @return array<string, mixed>
     */
    private function task(array $task): array
    {
        $sourceType = $this->sourceType($task['source_type'] ?? null);

        return [
            'key' => $this->compactScalar($task['key'] ?? ''),
            'title' => $this->compactScalar($task['title'] ?? ''),
            'category' => $this->compactScalar($task['category'] ?? ''),
            'source_type' => $sourceType,
            'source_label' => $sourceType === 'third_party' ? '第三方任务' : '系统任务',
            'description' => $this->compactScalar($task['description'] ?? ''),
            'manual_triggerable' => (bool) ($task['manual_triggerable'] ?? false),
            'expression' => $this->compactScalar($task['expression'] ?? ''),
            'summary' => $this->compactScalar($task['summary'] ?? ''),
            'timezone' => $this->compactScalar($task['timezone'] ?? ''),
            'next_run_at' => $this->compactScalar($task['next_run_at'] ?? null),
            'without_overlapping' => (bool) ($task['without_overlapping'] ?? false),
            'run_in_background' => (bool) ($task['run_in_background'] ?? false),
            'overlap_expires_minutes' => $task['overlap_expires_minutes'] ?? null,
            'last_log' => is_array($task['last_log'] ?? null) ? $this->log($task['last_log']) : null,
        ];
    }

    private function sourceType(mixed $value): string
    {
        return trim((string) $value) === 'third_party' ? 'third_party' : 'system';
    }

    /**
     * @param  array<string, mixed>  $log
     * @return array<string, mixed>
     */
    private function log(array $log): array
    {
        return [
            'time' => $this->compactScalar($log['time'] ?? null),
            'level' => $this->compactScalar($log['level'] ?? null),
            'message' => $this->compactScalar($log['message'] ?? null),
            'task_key' => $this->compactScalar($log['task_key'] ?? null),
            'status' => $this->compactScalar($log['status'] ?? null),
            'duration_ms' => $log['duration_ms'] ?? null,
            'summary' => $this->compactValue($log['summary'] ?? null, 0),
            'error_msg' => $this->compactScalar($log['error_msg'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function settingsSnapshotItem(array $item): array
    {
        return [
            'label' => $this->compactScalar($item['label'] ?? ''),
            'value' => $this->compactScalar($item['value'] ?? ''),
            'note' => $this->compactScalar($item['note'] ?? ''),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function limitedList(mixed $items, int $limit, callable $mapper): array
    {
        if (! is_array($items)) {
            return [];
        }

        $list = [];
        foreach ($items as $item) {
            if (count($list) >= $limit) {
                break;
            }

            if (is_array($item)) {
                $list[] = $mapper($item);
            }
        }

        return $list;
    }

    private function compactValue(mixed $value, int $depth): mixed
    {
        if ($depth >= self::MAX_DEPTH) {
            return is_array($value) ? '[truncated]' : $this->compactScalar($value);
        }

        if (! is_array($value)) {
            return $this->compactScalar($value);
        }

        $isList = array_is_list($value);
        $result = [];
        $count = 0;

        foreach ($value as $key => $item) {
            if ($count >= self::ARRAY_ITEM_LIMIT) {
                break;
            }

            if (is_string($key) && $this->isSensitiveKey($key)) {
                continue;
            }

            $result[$key] = $this->compactValue($item, $depth + 1);
            $count++;
        }

        return $isList ? array_values($result) : $result;
    }

    private function compactScalar(mixed $value): mixed
    {
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        $text = SensitiveDataSanitizer::sanitizeText((string) $value);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if (mb_strlen($text) <= self::TEXT_LIMIT) {
            return $text;
        }

        return mb_substr($text, 0, self::TEXT_LIMIT).'...';
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
