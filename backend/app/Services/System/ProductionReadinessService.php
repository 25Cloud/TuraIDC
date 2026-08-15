<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Models\ScheduleTaskRun;
use App\Models\ScheduleTick;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class ProductionReadinessService
{
    /** @return array{ready: bool, checks: array<string, bool>} */
    public function check(): array
    {
        $schedulerLiveness = $this->schedulerIsReady();
        $taskReadiness = $this->taskReadiness();
        $queueReadiness = $this->queueIsReady();
        $checks = [
            'database' => $this->databaseIsReady(),
            'cache' => $this->cacheIsReady(),
            'storage' => $this->storageIsReady(),
            // 保留 scheduler 字段表示心跳存活，新增细分项避免“心跳新鲜但任务已失控”。
            'scheduler' => $schedulerLiveness,
            'scheduler_liveness' => $schedulerLiveness,
            'task_readiness' => $taskReadiness,
            'queue' => $queueReadiness,
        ];

        return [
            'ready' => ! in_array(false, $checks, true),
            'checks' => $checks,
        ];
    }

    private function databaseIsReady(): bool
    {
        try {
            DB::connection()->selectOne('SELECT 1 AS ready');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function cacheIsReady(): bool
    {
        $key = 'health:readiness:'.Str::uuid()->toString();

        try {
            Cache::put($key, 'ready', 10);

            return Cache::get($key) === 'ready';
        } catch (Throwable) {
            return false;
        } finally {
            try {
                Cache::forget($key);
            } catch (Throwable) {
                // 读取或写入已经失败时，清理失败不改变就绪结论。
            }
        }
    }

    private function storageIsReady(): bool
    {
        foreach ([storage_path(), base_path('bootstrap/cache')] as $directory) {
            if (! is_dir($directory) || ! is_writable($directory)) {
                return false;
            }
        }

        return true;
    }

    private function schedulerIsReady(): bool
    {
        try {
            $latestHeartbeat = ScheduleTick::query()->max('triggered_at');
            if (! is_string($latestHeartbeat) || trim($latestHeartbeat) === '') {
                return false;
            }

            $maximumAge = max(60, (int) config('health.scheduler_max_age_seconds', 180));

            return CarbonImmutable::parse($latestHeartbeat)
                ->greaterThanOrEqualTo(CarbonImmutable::now()->subSeconds($maximumAge));
        } catch (Throwable) {
            return false;
        }
    }

    private function taskReadiness(): bool
    {
        try {
            if (! Schema::hasTable('schedule_task_runs')) {
                return false;
            }

            $failureWindow = max(60, (int) config('health.scheduler_task_failure_window_seconds', 900));
            $recentFailure = ScheduleTaskRun::query()
                ->whereIn('status', [
                    ScheduleTaskRun::STATUS_FAILED,
                    ScheduleTaskRun::STATUS_DISPATCH_FAILED,
                ])
                ->where('updated_at', '>=', now()->subSeconds($failureWindow))
                ->exists();
            if ($recentFailure) {
                return false;
            }

            $leaseSeconds = max(
                60,
                (int) config('queue.turaidc_worker_max_timeout', 3600) + 60,
                (int) config('queue.connections.database.retry_after', 3900) + 60,
            );

            return ! ScheduleTaskRun::query()
                ->whereIn('status', [
                    ScheduleTaskRun::STATUS_QUEUED,
                    ScheduleTaskRun::STATUS_RUNNING,
                    ScheduleTaskRun::STATUS_RETRYING,
                ])
                ->where(function ($query) use ($leaseSeconds): void {
                    $cutoff = now()->subSeconds($leaseSeconds);
                    $query
                        ->whereRaw('COALESCE(updated_at, queued_at, created_at) <= ?', [$cutoff])
                        ->orWhere(function ($nested): void {
                            $nested
                                ->whereNull('updated_at')
                                ->whereNull('queued_at')
                                ->whereNull('created_at');
                        });
                })
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    private function queueIsReady(): bool
    {
        $driver = (string) config('queue.default', 'sync');
        if ($driver === '' || $driver === 'sync') {
            return true;
        }

        if ($driver !== 'database') {
            // Redis/其他驱动的连通性由其连接层和 Worker 负责；这里不伪造“积压为零”。
            return true;
        }

        $config = (array) config('queue.connections.database', []);
        $connection = trim((string) ($config['connection'] ?? '')) ?: (string) config('database.default');
        $table = trim((string) ($config['table'] ?? 'jobs'));

        try {
            if ($table === '' || ! Schema::connection($connection)->hasTable($table)) {
                return false;
            }

            $maxPending = max(1, (int) config('health.queue_max_pending_jobs', 10000));

            return (int) DB::connection($connection)->table($table)->count() <= $maxPending;
        } catch (Throwable) {
            return false;
        }
    }
}
