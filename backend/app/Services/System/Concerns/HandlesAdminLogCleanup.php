<?php

declare(strict_types=1);

namespace App\Services\System\Concerns;

use App\Models\MessageLog;
use App\Models\ScheduleRunLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

trait HandlesAdminLogCleanup
{
    public function getCleanupOverview(): array
    {
        $logPath = storage_path('logs/laravel.log');
        $fileModifiedAt = is_file($logPath) ? (int) filemtime($logPath) : 0;
        $fileSize = is_file($logPath) ? (int) filesize($logPath) : 0;
        $cacheVersion = (int) Cache::get(self::CLEANUP_OVERVIEW_CACHE_VERSION_KEY, 1);
        $cacheKey = "admin_logs:cleanup_overview:{$cacheVersion}:{$fileModifiedAt}:{$fileSize}";

        return Cache::remember(
            $cacheKey,
            now()->addSeconds(self::CLEANUP_OVERVIEW_CACHE_TTL_SECONDS),
            function () use ($logPath) {
                $logEntries = $this->readLaravelLogEntries(10000);
                $taskLogCount = collect($logEntries)->whereNotNull('task_key')->count();
                $systemLogCount = collect($logEntries)->whereNull('task_key')->count();

                return [
                    'database' => [
                        'sms' => MessageLog::query()->where('channel', 'sms')->count(),
                        'email' => MessageLog::query()->where('channel', 'email')->count(),
                        'api' => $this->baseApiLogQuery()->count(),
                        'admin_login' => $this->baseAdminLoginLogQuery()->count(),
                        'business_audit' => $this->businessAuditLogCount(),
                        'schedule_run' => ScheduleRunLog::query()->count(),
                        'ticket_upstream' => Schema::hasTable('ticket_upstream_delivery_logs')
                            ? DB::table('ticket_upstream_delivery_logs')->count()
                            : 0,
                    ],
                    'file' => [
                        'path' => 'storage/logs/laravel.log',
                        'exists' => is_file($logPath),
                        'size_bytes' => is_file($logPath) ? (int) filesize($logPath) : 0,
                        'updated_at' => is_file($logPath) ? date('Y-m-d H:i:s', (int) filemtime($logPath)) : null,
                        'task_log_count' => $taskLogCount,
                        'runtime_log_count' => $systemLogCount,
                        'system_log_count' => $systemLogCount,
                    ],
                    'supported_cleanup_types' => [
                        ['value' => 'sms', 'label' => '短信日志'],
                        ['value' => 'email', 'label' => '邮件日志'],
                        ['value' => 'api', 'label' => 'API日志'],
                        ['value' => 'admin_login', 'label' => '管理员登录日志'],
                        ['value' => 'business_audit', 'label' => '系统日志（业务审计）'],
                        ['value' => 'schedule_run', 'label' => '调度执行日志'],
                        ['value' => 'ticket_upstream', 'label' => '工单推送日志'],
                        ['value' => 'task', 'label' => '自动任务日志'],
                        ['value' => 'runtime', 'label' => '运行日志'],
                        ['value' => 'all_db', 'label' => '全部数据库日志'],
                        ['value' => 'all_file', 'label' => '全部文件日志'],
                        ['value' => 'all', 'label' => '全部日志'],
                    ],
                ];
            }
        );
    }

    public function cleanup(array $payload): array
    {
        $type = trim((string) ($payload['type'] ?? ''));
        $keepDays = (int) ($payload['keep_days'] ?? 0);
        $cutoff = now()->subDays($keepDays)->startOfDay();
        $affected = [];

        if ($type === 'all' || $type === 'all_db') {
            DB::transaction(function () use ($cutoff, &$affected) {
                $affected['sms'] = MessageLog::query()->where('channel', 'sms')->where('created_at', '<', $cutoff)->delete();
                $affected['email'] = MessageLog::query()->where('channel', 'email')->where('created_at', '<', $cutoff)->delete();
                $affected['api'] = $this->baseApiLogQuery()
                    ->where('created_at', '<', $cutoff)
                    ->delete();
                $affected['admin_login'] = $this->baseAdminLoginLogQuery()
                    ->where('created_at', '<', $cutoff)
                    ->delete();
                $affected['business_audit'] = $this->deleteBusinessAuditLogsBefore($cutoff);
                $affected['schedule_run'] = ScheduleRunLog::query()->where('created_at', '<', $cutoff)->delete();
                $affected['ticket_upstream'] = $this->deleteTicketUpstreamLogsBefore($cutoff);
            });
        } else {
            DB::transaction(function () use ($type, $cutoff, &$affected) {
                if ($type === 'sms') {
                    $affected['sms'] = MessageLog::query()->where('channel', 'sms')->where('created_at', '<', $cutoff)->delete();
                }

                if ($type === 'email') {
                    $affected['email'] = MessageLog::query()->where('channel', 'email')->where('created_at', '<', $cutoff)->delete();
                }

                if ($type === 'api') {
                    $affected['api'] = $this->baseApiLogQuery()
                        ->where('created_at', '<', $cutoff)
                        ->delete();
                }

                if ($type === 'admin_login') {
                    $affected['admin_login'] = $this->baseAdminLoginLogQuery()
                        ->where('created_at', '<', $cutoff)
                        ->delete();
                }

                if ($type === 'business_audit') {
                    $affected['business_audit'] = $this->deleteBusinessAuditLogsBefore($cutoff);
                }

                if ($type === 'schedule_run') {
                    $affected['schedule_run'] = ScheduleRunLog::query()->where('created_at', '<', $cutoff)->delete();
                }

                if ($type === 'ticket_upstream') {
                    $affected['ticket_upstream'] = $this->deleteTicketUpstreamLogsBefore($cutoff);
                }
            });
        }

        if ($type === 'all' || $type === 'all_file' || $type === 'task' || $type === 'runtime' || $type === 'system') {
            $fileCleanup = $this->cleanupFileLogs($type, $cutoff);
            $affected = array_merge($affected, $fileCleanup);
        }

        $this->bumpCleanupOverviewCacheVersion();

        return [
            'type' => $type,
            'keep_days' => $keepDays,
            'cutoff_at' => $cutoff->format('Y-m-d H:i:s'),
            'affected' => $affected,
        ];
    }

    private function cleanupFileLogs(string $type, Carbon $cutoff): array
    {
        $logPath = storage_path('logs/laravel.log');
        $affected = [];

        if (! is_file($logPath)) {
            return $affected;
        }

        $content = file_get_contents($logPath);
        if ($content === false || $content === '') {
            return $affected;
        }

        $lines = preg_split('/\r\n|\n|\r/', rtrim($content, "\r\n"));
        if ($lines === false) {
            return $affected;
        }

        $filteredLines = [];
        $currentEntry = [];
        $taskRemovedCount = 0;
        $systemRemovedCount = 0;

        $flushEntry = function (array $entry) use ($type, $cutoff, &$filteredLines, &$taskRemovedCount, &$systemRemovedCount): void {
            if ($entry === []) {
                return;
            }

            $logDate = $this->extractLogDate((string) $entry[0]);
            if ($logDate === null) {
                array_push($filteredLines, ...$entry);

                return;
            }

            $isTaskLog = $this->isTaskLogLine(implode("\n", $entry));
            $shouldRemoveTask = ($type === 'task' || $type === 'all' || $type === 'all_file')
                && $isTaskLog
                && $logDate < $cutoff;
            $shouldRemoveSystem = ($type === 'runtime' || $type === 'system' || $type === 'all' || $type === 'all_file')
                && ! $isTaskLog
                && $logDate < $cutoff;

            if ($shouldRemoveTask) {
                $taskRemovedCount++;

                return;
            }

            if ($shouldRemoveSystem) {
                $systemRemovedCount++;

                return;
            }

            array_push($filteredLines, ...$entry);
        };

        foreach ($lines as $line) {
            $line = (string) $line;
            $logDate = $this->extractLogDate($line);
            if ($logDate !== null) {
                $flushEntry($currentEntry);
                $currentEntry = [$line];

                continue;
            }

            if ($currentEntry === []) {
                $filteredLines[] = $line;

                continue;
            }

            $currentEntry[] = $line;
        }

        $flushEntry($currentEntry);

        if ($taskRemovedCount > 0 || $systemRemovedCount > 0) {
            $newContent = implode("\n", $filteredLines);
            if (substr($newContent, -1) !== "\n") {
                $newContent .= "\n";
            }
            file_put_contents($logPath, $newContent, LOCK_EX);
        }

        if ($type === 'task' || $type === 'all' || $type === 'all_file') {
            $affected['task'] = $taskRemovedCount;
        }
        if ($type === 'runtime' || $type === 'system' || $type === 'all' || $type === 'all_file') {
            $affected['runtime'] = $systemRemovedCount;
        }

        return $affected;
    }

    private function businessAuditLogCount(): int
    {
        $activityCount = Schema::hasTable('activity_logs') ? DB::table('activity_logs')->count() : 0;
        $operationCount = Schema::hasTable('operation_logs')
            ? DB::table('operation_logs')
                ->whereRaw('action NOT REGEXP ?', [self::HTTP_ACTION_REGEXP])
                ->where('action', '<>', 'admin.login')
                ->count()
            : 0;

        return (int) $activityCount + (int) $operationCount;
    }

    private function deleteTicketUpstreamLogsBefore(Carbon $cutoff): int
    {
        if (! Schema::hasTable('ticket_upstream_delivery_logs')) {
            return 0;
        }

        return (int) DB::table('ticket_upstream_delivery_logs')
            ->where('occurred_at', '<', $cutoff)
            ->delete();
    }

    private function deleteBusinessAuditLogsBefore(Carbon $cutoff): int
    {
        $deleted = 0;

        if (Schema::hasTable('activity_logs')) {
            $deleted += DB::table('activity_logs')->where('created_at', '<', $cutoff)->delete();
        }

        if (Schema::hasTable('operation_logs')) {
            $deleted += DB::table('operation_logs')
                ->whereRaw('action NOT REGEXP ?', [self::HTTP_ACTION_REGEXP])
                ->where('action', '<>', 'admin.login')
                ->where('created_at', '<', $cutoff)
                ->delete();
        }

        return (int) $deleted;
    }

    private function bumpCleanupOverviewCacheVersion(): void
    {
        Cache::forever(
            self::CLEANUP_OVERVIEW_CACHE_VERSION_KEY,
            (int) Cache::get(self::CLEANUP_OVERVIEW_CACHE_VERSION_KEY, 1) + 1
        );
    }

    private function extractLogDate(string $line): ?Carbon
    {
        if (preg_match('/^\[([^\]]+)\]/', $line, $matches)) {
            try {
                return Carbon::parse(trim($matches[1]));
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    private function isTaskLogLine(string $line): bool
    {
        foreach (self::TASK_META as $meta) {
            foreach ((array) ($meta['log_keywords'] ?? []) as $keyword) {
                if ($keyword !== '' && str_contains($line, $keyword)) {
                    return true;
                }
            }
        }

        return false;
    }
}
