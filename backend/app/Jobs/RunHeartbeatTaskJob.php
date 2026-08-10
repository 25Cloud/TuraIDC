<?php

namespace App\Jobs;

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
        $this->onQueue('default');
        $this->afterCommit();
    }

    public function middleware(): array
    {
        $task = app(HeartbeatTaskRegistry::class)->get($this->taskKey);

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
        $task = $registry->get($this->taskKey);
        $tick = $ticks->findContext($this->tickId);

        Log::info('[心跳定时任务] 开始执行', [
            'task' => $this->taskKey,
            'source' => $this->source,
            'tick_id' => $this->tickId,
            'task_run_id' => $this->taskRunId,
            'admin_user_id' => $this->adminUserId,
        ]);

        $result = $runner->run($task, new TaskContext(
            taskKey: $this->taskKey,
            source: $this->source,
            tick: $tick,
            taskRunId: $this->taskRunId,
            adminUserId: $this->adminUserId,
            triggeredAt: CarbonImmutable::now(),
        ));

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
            app(ScheduleTaskRunRepository::class)
                ->markFailed($this->taskRunId, $exception->getMessage(), 0);
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
}
