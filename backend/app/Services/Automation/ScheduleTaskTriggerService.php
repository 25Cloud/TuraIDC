<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Jobs\RunHeartbeatTaskJob;
use App\Models\ScheduleTaskRun;
use App\Services\Automation\Heartbeat\Contracts\ScheduledTask;
use App\Services\Automation\Heartbeat\HeartbeatTaskRegistry;
use App\Services\Automation\Heartbeat\ScheduleTaskRunRepository;
use App\Services\System\OperationLogService;
use App\Support\SensitiveDataSanitizer;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Predis\Client;
use RuntimeException;

class ScheduleTaskTriggerService
{
    private ?bool $databaseQueueReady = null;

    public function __construct(
        private HeartbeatTaskRegistry $registry,
        private ScheduleTaskRunRepository $taskRuns,
        private ?OperationLogService $operationLogs = null,
    ) {}

    public function supports(string $taskKey): bool
    {
        return $this->registry->supportsManualTrigger(trim($taskKey));
    }

    /**
     * 手动触发的去重检查和运行记录创建必须在同一把分布式锁内完成。
     * 锁不可用时拒绝执行，避免退化成无保护的并发触发。
     *
     * @return array<string, mixed>
     */
    public function dispatch(string $taskKey, ?int $adminUserId = null): array
    {
        $taskKey = trim($taskKey);

        if (! $this->supports($taskKey)) {
            throw new InvalidArgumentException('不支持的任务：'.$taskKey);
        }

        $task = $this->registry->get($taskKey);
        $lock = $this->acquireTaskTriggerLock($taskKey, $task->lockTtlSeconds());

        try {
            return $this->dispatchLocked($taskKey, $task, $adminUserId);
        } finally {
            $lock->release();
        }
    }

    /**
     * 仅对已经确认的终态 failed 运行发起一次人工重跑。
     * 新运行以 manual_retry 来源写入，并通过 parent_run_id 保留原失败记录。
     *
     * @return array<string, mixed>
     */
    public function retryFailedRun(
        int $runId,
        ?int $adminUserId,
        string $reason,
        ?string $ipAddress = null,
    ): array {
        if ($runId <= 0) {
            throw new InvalidArgumentException('运行记录不存在');
        }

        if (! $this->taskRuns->tableReady()) {
            throw new RuntimeException('定时任务运行记录表不可用，已拒绝执行');
        }

        if (! $this->taskRuns->retryLineageReady()) {
            throw new RuntimeException('运行台账尚未完成重跑字段迁移，暂不能人工重跑');
        }

        $parent = $this->taskRuns->findRun($runId);
        if ($parent === null) {
            throw new InvalidArgumentException('运行记录不存在');
        }

        $taskKey = trim((string) $parent->task_key);
        if (! $this->registry->supportsManualTrigger($taskKey)) {
            throw new InvalidArgumentException('任务未注册或当前不允许人工触发');
        }

        $task = $this->registry->get($taskKey);
        $lock = $this->acquireTaskTriggerLock($taskKey, $task->lockTtlSeconds());
        $safeReason = mb_substr(
            trim(SensitiveDataSanitizer::sanitizeText($reason)),
            0,
            500,
        );

        try {
            // 锁内重新读取，防止两个管理员同时通过状态检查。
            $parent = $this->taskRuns->findRun($runId);
            if ($parent === null) {
                throw new InvalidArgumentException('运行记录不存在');
            }

            if ($parent->status === ScheduleTaskRun::STATUS_DISPATCH_FAILED) {
                // 心跳派发失败由同槽复用/租约回收收敛；只有人工重跑子运行需要管理员重派，
                // 否则父记录已标记 manual_retry_at、无任何机制能恢复，重跑链路形成死胡同。
                if ((string) $parent->source !== 'manual_retry') {
                    throw new InvalidArgumentException('队列派发失败记录不能人工重跑，请先恢复队列基础设施');
                }

                $child = $this->taskRuns->requeueDispatchFailedRun((int) $parent->id);
                if ($child === null) {
                    throw new InvalidArgumentException('该运行记录状态已变化，无法重新派发');
                }

                $childId = (int) $child->id;
                $ancestorRunId = (int) ($child->parent_run_id ?? $runId);

                try {
                    $executionMode = $this->dispatchRun(
                        taskKey: $taskKey,
                        task: $task,
                        runId: $childId,
                        adminUserId: $adminUserId,
                        source: 'manual_retry_redispatch',
                    );
                } catch (\Throwable $exception) {
                    $this->taskRuns->markDispatchFailed(
                        $childId,
                        '队列派发失败：'.$exception->getMessage(),
                    );

                    throw $exception;
                }

                $this->writeRetryAudit(
                    adminUserId: $adminUserId,
                    parentRunId: $ancestorRunId,
                    childRunId: $childId,
                    taskKey: $taskKey,
                    reason: $safeReason.'（重新派发队列派发失败的运行）',
                    ipAddress: $ipAddress,
                );

                return [
                    'task' => $taskKey,
                    'title' => $task->title(),
                    'execution_mode' => $executionMode,
                    'task_run_id' => $childId,
                    'parent_run_id' => $ancestorRunId,
                    'source' => 'manual_retry_redispatch',
                    'status' => 'queued',
                ];
            }

            if ($parent->status !== ScheduleTaskRun::STATUS_FAILED) {
                throw new InvalidArgumentException('仅终态失败运行允许人工重跑');
            }

            if ($parent->manual_retry_at !== null) {
                throw new InvalidArgumentException('该失败运行已经发起过人工重跑');
            }

            // 与手动触发路径（dispatchLocked）对齐：先回收租约超时的陈旧运行，
            // 避免一条已过租约的 queued/running/retrying 记录把失败任务的人工重跑永久阻塞到下一槽位。
            $this->taskRuns->reclaimStaleRunsForTask($taskKey, $this->taskLeaseSeconds($task));

            if ($this->taskRuns->activeRunForTask($taskKey) !== null) {
                throw new InvalidArgumentException('该任务已有排队或运行中的记录，请稍后重试');
            }

            $child = $this->taskRuns->markManualRetryQueued(
                $parent,
                $task,
                $adminUserId,
                $safeReason,
            );
            if ($child === null || ! $child->exists) {
                throw new InvalidArgumentException('该失败运行已经发起过人工重跑');
            }

            $childId = (int) $child->id;
            try {
                $executionMode = $this->dispatchRun(
                    taskKey: $taskKey,
                    task: $task,
                    runId: $childId,
                    adminUserId: $adminUserId,
                    source: 'manual_retry',
                );
            } catch (\Throwable $exception) {
                $this->taskRuns->markDispatchFailed(
                    $childId,
                    '队列派发失败：'.$exception->getMessage(),
                );

                throw $exception;
            }

            $this->writeRetryAudit(
                adminUserId: $adminUserId,
                parentRunId: $runId,
                childRunId: $childId,
                taskKey: $taskKey,
                reason: $safeReason,
                ipAddress: $ipAddress,
            );

            return [
                'task' => $taskKey,
                'title' => $task->title(),
                'execution_mode' => $executionMode,
                'task_run_id' => $childId,
                'parent_run_id' => $runId,
                'source' => 'manual_retry',
                'status' => 'queued',
            ];
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatchLocked(string $taskKey, ScheduledTask $task, ?int $adminUserId): array
    {
        if (! $this->taskRuns->tableReady()) {
            throw new RuntimeException('定时任务运行记录表不可用，已拒绝执行');
        }

        $this->taskRuns->reclaimStaleRunsForTask($taskKey, $this->taskLeaseSeconds($task));
        $activeRun = $this->taskRuns->activeRunForTask($taskKey);
        if ($activeRun !== null) {
            return [
                'task' => $taskKey,
                'title' => $task->title(),
                'execution_mode' => 'already_queued',
                'task_run_id' => (int) $activeRun->id,
            ];
        }

        $run = $this->taskRuns->markManualQueued($task, $adminUserId);
        if ($run === null || ! $run->exists) {
            throw new RuntimeException('定时任务运行记录创建失败，已拒绝执行');
        }

        $runId = (int) $run->id;
        try {
            $executionMode = $this->dispatchRun(
                taskKey: $taskKey,
                task: $task,
                runId: $runId,
                adminUserId: $adminUserId,
                source: 'manual_trigger',
            );
        } catch (\Throwable $exception) {
            $this->taskRuns->markTerminalFailed($runId, '队列派发失败：'.$exception->getMessage(), 0);

            throw $exception;
        }

        return [
            'task' => $taskKey,
            'title' => $task->title(),
            'execution_mode' => $executionMode,
            'task_run_id' => $runId,
        ];
    }

    /**
     * 统一的 Job 派发路径。HTTP 请求只会进入 queue 或 after_response，
     * 只有显式的控制台调用才允许同步执行。
     */
    private function dispatchRun(
        string $taskKey,
        ScheduledTask $task,
        int $runId,
        ?int $adminUserId,
        string $source,
    ): string {
        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            RunHeartbeatTaskJob::dispatchSync(
                $taskKey,
                null,
                $runId,
                $adminUserId,
                $source,
                $task->timeout(),
            );

            return 'sync';
        }

        if ($this->shouldUseQueue()) {
            RunHeartbeatTaskJob::dispatch(
                $taskKey,
                null,
                $runId,
                $adminUserId,
                $source,
                $task->timeout(),
            )->onQueue($task->queue());

            return 'queue';
        }

        $this->logFallbackDispatch($taskKey, $adminUserId);
        RunHeartbeatTaskJob::dispatchAfterResponse(
            $taskKey,
            null,
            $runId,
            $adminUserId,
            $source,
            $task->timeout(),
        )->onQueue($task->queue());

        return 'after_response';
    }

    private function writeRetryAudit(
        ?int $adminUserId,
        int $parentRunId,
        int $childRunId,
        string $taskKey,
        string $reason,
        ?string $ipAddress,
    ): void {
        try {
            ($this->operationLogs ?? app(OperationLogService::class))->write(
                userId: $adminUserId,
                userType: 'admin',
                action: 'schedule.task.retry',
                module: 'schedule',
                targetId: $parentRunId,
                detail: [
                    'task_key' => $taskKey,
                    'parent_run_id' => $parentRunId,
                    'child_run_id' => $childRunId,
                    'source' => 'manual_retry',
                    'reason' => $reason,
                ],
                ipAddress: $ipAddress !== null && trim($ipAddress) !== '' ? trim($ipAddress) : null,
            );
        } catch (\Throwable $exception) {
            // 审计双写故障不应让已入队的业务任务被再次派发；保留告警供运维补偿。
            Log::warning('[定时任务] 人工重跑审计写入失败', [
                'task' => $taskKey,
                'parent_run_id' => $parentRunId,
                'child_run_id' => $childRunId,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }
    }

    private function acquireTaskTriggerLock(string $taskKey, int $lockTtlSeconds): Lock
    {
        try {
            $lock = Cache::lock(
                'scheduler:task-trigger:'.$taskKey,
                max(30, min(3600, $lockTtlSeconds)),
            );

            if (! $lock->get()) {
                throw new RuntimeException('定时任务正在由其他请求触发，请稍后重试');
            }

            return $lock;
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('[手动定时任务] 获取分布式锁失败', [
                'task' => $taskKey,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            throw new RuntimeException('定时任务并发锁不可用，已拒绝执行', 0, $exception);
        }
    }

    private function taskLeaseSeconds(ScheduledTask $task): int
    {
        $driver = (string) config('queue.default', 'sync');
        $retryAfter = (int) config("queue.connections.{$driver}.retry_after", 0);

        return max(60, $task->timeout() + 60, $task->lockTtlSeconds() + 60, $retryAfter + 60);
    }

    private function shouldUseQueue(): bool
    {
        $driver = (string) config('queue.default', 'sync');

        if ($driver === '' || $driver === 'sync') {
            return false;
        }

        if ($driver === 'database') {
            return $this->databaseQueueIsReady();
        }

        if ($driver === 'redis') {
            return extension_loaded('redis') || class_exists(Client::class);
        }

        return true;
    }

    private function databaseQueueIsReady(): bool
    {
        if ($this->databaseQueueReady !== null) {
            return $this->databaseQueueReady;
        }

        $config = (array) config('queue.connections.database', []);
        $connection = trim((string) ($config['connection'] ?? '')) ?: (string) config('database.default');
        $table = (string) ($config['table'] ?? 'jobs');

        try {
            $this->databaseQueueReady = $table !== '' && Schema::connection($connection)->hasTable($table);
        } catch (\Throwable $exception) {
            Log::warning('[手动定时任务] 检查队列表失败，回退为 afterResponse', [
                'connection' => $connection,
                'table' => $table,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            $this->databaseQueueReady = false;
        }

        return $this->databaseQueueReady;
    }

    private function logFallbackDispatch(string $taskKey, ?int $adminUserId): void
    {
        Log::info('[手动定时任务] 管理端立即执行采用 afterResponse/同步执行', [
            'task' => $taskKey,
            'admin_user_id' => $adminUserId,
            'queue_driver' => (string) config('queue.default', 'sync'),
            'running_in_console' => app()->runningInConsole(),
        ]);
    }
}
