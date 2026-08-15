<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat;

use App\Jobs\RunHeartbeatTaskJob;
use App\Models\ScheduleTaskRun;
use App\Models\ScheduleTick;
use App\Services\Automation\Heartbeat\Contracts\ScheduledTask;
use App\Services\Automation\Heartbeat\Data\TickContext;
use App\Services\Automation\Heartbeat\Data\TickSummary;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class HeartbeatScheduler
{
    public function __construct(
        private ScheduleTickRepository $ticks,
        private ScheduleTaskRunRepository $taskRuns,
        private HeartbeatTaskRegistry $registry,
        private TriggerRuleMatcher $matcher,
    ) {}

    public function tick(CarbonImmutable $now): TickSummary
    {
        $slot = TickSlot::floorToFifteenMinutes($now);
        // 先获取槽位锁再创建/读取 tick：避免多实例并发心跳对唯一索引的写入冲突；
        // 锁失败时不触碰 tick 表，直接返回无 id 的空上下文。
        $lock = Cache::lock('scheduler:heartbeat:'.$slot->format('YmdHi'), 900);

        if (! $lock->get()) {
            Log::warning('[调度] 心跳槽位锁被占用，本槽位跳过派发', [
                'slot' => $slot->format('YmdHi'),
                'lock' => 'scheduler:heartbeat:'.$slot->format('YmdHi'),
            ]);

            return new TickSummary(TickSlot::context(null, $slot), [], [], [], []);
        }

        $queued = [];
        $skipped = [];
        $duplicates = [];
        $reclaimed = 0;
        $dispatchFailed = 0;

        try {
            $tickModel = $this->ticks->firstOrCreateSlot($now);
            $tick = $this->ticks->toContext($tickModel);

            foreach ($this->registry->enabledTasks() as $task) {
                $this->dispatchTaskForTick($task, $tickModel, $tick, $queued, $skipped, $duplicates, $reclaimed, $dispatchFailed);
            }
        } finally {
            $lock->release();
        }

        $this->reportHealth($slot, $queued, $skipped, $duplicates, $reclaimed, $dispatchFailed);

        return new TickSummary($tick, $queued, $skipped, $duplicates, []);
    }

    /**
     * @param  list<string>  $queued
     * @param  list<string>  $skipped
     * @param  list<string>  $duplicates
     */
    private function dispatchTaskForTick(
        ScheduledTask $task,
        ScheduleTick $tickModel,
        TickContext $tick,
        array &$queued,
        array &$skipped,
        array &$duplicates,
        int &$reclaimed,
        int &$dispatchFailed,
    ): void {
        try {
            $matched = $this->matcher->firstMatchedRule($task->triggers(), $tick);
        } catch (\Throwable $exception) {
            $skipped[] = $task->key();
            Log::error('[心跳定时任务] 任务触发规则评估失败，已跳过本槽位', [
                'task' => $task->key(),
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return;
        }

        if ($matched === null) {
            $skipped[] = $task->key();

            return;
        }

        $taskLock = $this->acquireTaskTriggerLock($task);
        if ($taskLock === null) {
            $duplicates[] = $task->key();

            return;
        }

        try {
            // 只有超过任务执行、队列可见性和锁安全余量的记录才回收，避免误杀仍在运行的任务。
            $reclaimed += $this->taskRuns->reclaimStaleRunsForTask($task->key(), $this->taskLeaseSeconds($task));

            if ($this->taskRuns->activeRunForTask($task->key()) !== null) {
                $duplicates[] = $task->key();

                return;
            }

            $run = $this->taskRuns->markQueued($tickModel, $task, $matched);
            if ($run === null) {
                // 同槽位已终态处理（成功/失败）不算重复，计入跳过；仅仍活跃或派发失败才视为重复，
                // 避免任务失败后的同槽剩余心跳刷出无意义的 duplicates 告警。
                $existingStatus = $this->taskRuns->existingRunForTick($tickModel, $task->key())?->status;
                if (in_array($existingStatus, [ScheduleTaskRun::STATUS_SUCCESS, ScheduleTaskRun::STATUS_FAILED], true)) {
                    $skipped[] = $task->key();

                    return;
                }

                $duplicates[] = $task->key();

                return;
            }

            try {
                RunHeartbeatTaskJob::dispatch(
                    $task->key(),
                    (int) $tickModel->id,
                    (int) $run->id,
                    null,
                    'heartbeat',
                    $task->timeout(),
                )->onQueue($task->queue());
                $queued[] = $task->key();
            } catch (\Throwable $exception) {
                $this->taskRuns->markDispatchFailed((int) $run->id, '队列派发失败：'.$exception->getMessage());
                $dispatchFailed++;

                Log::error('[心跳定时任务] 队列派发失败，将在同槽后续心跳重派', [
                    'task' => $task->key(),
                    'tick_id' => $tickModel->id,
                    'task_run_id' => $run->id,
                    'message' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);
            }
        } catch (\Throwable $exception) {
            $skipped[] = $task->key();
            Log::error('[心跳定时任务] 任务注册或派发处理失败，已隔离本任务', [
                'task' => $task->key(),
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        } finally {
            $taskLock->release();
        }
    }

    private function acquireTaskTriggerLock(ScheduledTask $task): ?Lock
    {
        try {
            $lock = Cache::lock(
                'scheduler:task-trigger:'.$task->key(),
                max(30, min(3600, $task->lockTtlSeconds())),
            );
            if (! $lock->get()) {
                return null;
            }

            return $lock;
        } catch (\Throwable $exception) {
            Log::error('[心跳定时任务] 获取任务并发锁失败，已跳过本槽位', [
                'task' => $task->key(),
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    private function taskLeaseSeconds(ScheduledTask $task): int
    {
        $driver = (string) config('queue.default', 'sync');
        $retryAfter = (int) config("queue.connections.{$driver}.retry_after", 0);

        return max(60, $task->timeout() + 60, $task->lockTtlSeconds() + 60, $retryAfter + 60);
    }

    /**
     * 滞留运行、派发失败或队列积压超过阈值时输出结构化告警；正常槽位保持静默。
     *
     * @param  list<string>  $queued
     * @param  list<string>  $skipped
     * @param  list<string>  $duplicates
     */
    private function reportHealth(
        CarbonImmutable $slot,
        array $queued,
        array $skipped,
        array $duplicates,
        int $reclaimed,
        int $dispatchFailed,
    ): void {
        $pendingJobs = $this->pendingJobsCount();
        $pendingThreshold = max(1, (int) config('health.queue_max_pending_jobs', 10000));

        if ($reclaimed === 0 && $dispatchFailed === 0 && $pendingJobs <= $pendingThreshold) {
            return;
        }

        Log::warning('[调度] 心跳槽位健康告警', [
            'slot' => $slot->format('YmdHi'),
            'queued' => count($queued),
            'skipped' => count($skipped),
            'duplicates' => count($duplicates),
            'reclaimed' => $reclaimed,
            'dispatch_failed' => $dispatchFailed,
            'jobs_table_pending' => $pendingJobs,
            'pending_threshold' => $pendingThreshold,
        ]);
    }

    private function pendingJobsCount(): int
    {
        try {
            // 队列可配置独立数据库连接（queue.connections.database.connection），
            // 必须与其余模块一致解析该连接，否则独立队列库上统计恒为 0、积压告警被静默抑制。
            $connection = trim((string) config('queue.connections.database.connection', '')) ?: (string) config('database.default');
            $table = (string) config('queue.connections.database.table', 'jobs');

            if ($table === '' || ! Schema::connection($connection)->hasTable($table)) {
                return 0;
            }

            $queues = [];
            foreach ([
                (string) config('queue.turaidc_business_queues', ''),
                (string) config('queue.turaidc_schedule_queue', ''),
            ] as $configured) {
                foreach (explode(',', $configured) as $queue) {
                    $queue = trim($queue);
                    if ($queue !== '' && ! in_array($queue, $queues, true)) {
                        $queues[] = $queue;
                    }
                }
            }

            if ($queues === []) {
                return 0;
            }

            // 统计全部可消费队列的待执行任务，业务队列积压超过阈值时同样告警。
            return DB::connection($connection)->table($table)
                ->whereIn('queue', $queues)
                ->whereNull('reserved_at')
                ->where('available_at', '<=', now()->getTimestamp())
                ->count();
        } catch (\Throwable) {
            return 0;
        }
    }
}
