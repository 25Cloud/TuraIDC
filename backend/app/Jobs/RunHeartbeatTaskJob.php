<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Automation\Heartbeat\Contracts\ScheduledTask;
use App\Services\Automation\Heartbeat\Data\TaskContext;
use App\Services\Automation\Heartbeat\HeartbeatTaskRegistry;
use App\Services\Automation\Heartbeat\HeartbeatTaskRunner;
use App\Services\Automation\Heartbeat\ScheduleTaskRunRepository;
use App\Services\Automation\Heartbeat\ScheduleTickRepository;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunHeartbeatTaskJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 1200;

    public int $backoff = 60;

    public function __construct(
        public string $taskKey,
        public ?int $tickId = null,
        public ?int $taskRunId = null,
        public ?int $adminUserId = null,
        public string $source = 'heartbeat',
        ?int $taskTimeout = null,
    ) {
        $this->timeout = max(1, $taskTimeout ?? $this->timeout);
        $this->onQueue((string) config('queue.turaidc_schedule_queue', 'automation'));
        $this->afterCommit();
    }

    public function middleware(): array
    {
        $task = $this->resolveTask();

        if ($task === null) {
            return [];
        }

        return [
            (new WithoutOverlapping("job:heartbeat-task:{$this->taskKey}"))
                ->releaseAfter(30)
                ->expireAfter(max($task->lockTtlSeconds(), $this->timeout + 60)),
        ];
    }

    public function handle(
        HeartbeatTaskRegistry $registry,
        HeartbeatTaskRunner $runner,
        ScheduleTickRepository $ticks,
    ): void {
        $task = $this->resolveTask($registry);
        if ($task === null) {
            // 插件卸载或任务键变更后的遗留 Job 直接收敛终态，不再消耗队列重试次数。
            $this->finalizeUnregisteredTask();

            return;
        }

        $tick = $ticks->findContext($this->tickId);
        $finalFailure = $this->isFinalAttempt();

        Log::info('[心跳定时任务] 开始执行', [
            'task' => $this->taskKey,
            'source' => $this->source,
            'tick_id' => $this->tickId,
            'task_run_id' => $this->taskRunId,
            'admin_user_id' => $this->adminUserId,
            'attempt' => $this->currentAttempt(),
            'final_attempt' => $finalFailure,
        ]);

        $result = $runner->run($task, new TaskContext(
            taskKey: $this->taskKey,
            source: $this->source,
            tick: $tick,
            taskRunId: $this->taskRunId,
            adminUserId: $this->adminUserId,
            attempt: $this->currentAttempt(),
            triggeredAt: CarbonImmutable::now(),
        ), $finalFailure);

        Log::info('[心跳定时任务] 执行完成', [
            'task' => $this->taskKey,
            'source' => $this->source,
            'tick_id' => $this->tickId,
            'task_run_id' => $this->taskRunId,
            'admin_user_id' => $this->adminUserId,
            'result' => $result,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        try {
            // failed() 只负责最终失败或在进入 Runner 前失败的 Job；条件更新不会覆盖 success。
            app(ScheduleTaskRunRepository::class)
                ->markTerminalFailed($this->taskRunId, $exception->getMessage(), null);
        } catch (\Throwable $statusException) {
            Log::warning('[心跳定时任务] 写入失败状态时出错', [
                'task' => $this->taskKey,
                'task_run_id' => $this->taskRunId,
                'message' => $statusException->getMessage(),
                'exception' => $statusException::class,
            ]);
        }

        Log::error('[心跳定时任务] 执行失败，已交由队列失败机制记录', [
            'task' => $this->taskKey,
            'source' => $this->source,
            'tick_id' => $this->tickId,
            'task_run_id' => $this->taskRunId,
            'admin_user_id' => $this->adminUserId,
            'message' => $exception->getMessage(),
            'exception' => $exception::class,
        ]);
    }

    private function currentAttempt(): int
    {
        try {
            return max(1, (int) $this->attempts());
        } catch (\Throwable) {
            return 1;
        }
    }

    private function isFinalAttempt(): bool
    {
        return $this->currentAttempt() >= max(1, $this->tries);
    }

    private function resolveTask(?HeartbeatTaskRegistry $registry = null): ?ScheduledTask
    {
        try {
            return ($registry ?? app(HeartbeatTaskRegistry::class))->get($this->taskKey);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    private function finalizeUnregisteredTask(): void
    {
        try {
            app(ScheduleTaskRunRepository::class)
                ->markTerminalFailed($this->taskRunId, '任务未注册（插件可能已卸载或任务键已变更），不再重试', null);
        } catch (\Throwable $statusException) {
            Log::warning('[心跳定时任务] 未注册任务收敛状态时出错', [
                'task' => $this->taskKey,
                'task_run_id' => $this->taskRunId,
                'message' => $statusException->getMessage(),
                'exception' => $statusException::class,
            ]);
        }

        Log::warning('[心跳定时任务] 任务未注册，已收敛为终态失败', [
            'task' => $this->taskKey,
            'source' => $this->source,
            'tick_id' => $this->tickId,
            'task_run_id' => $this->taskRunId,
        ]);
    }
}
