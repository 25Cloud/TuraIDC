<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Jobs\RunHeartbeatTaskJob;
use App\Jobs\RunScheduleTaskJob;
use App\Services\Automation\Heartbeat\ScheduleTaskRunRepository;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Worker 因任务执行超时被 SIGKILL 前，Laravel 会同步派发 JobTimedOut。
 * 心跳任务超时进程外被杀时 handle() 的 catch 不会执行，运行台账会永久停在 running，
 * 导致队列自动重试被 markRunning() 的 CAS 拒绝。本监听器在信号回调中把状态收敛为
 * retrying（仍有重试机会）或 failed（最后一次尝试），让重试真正执行。
 */
class HeartbeatTaskTimedOutListener
{
    public function __construct(
        private ScheduleTaskRunRepository $taskRuns,
    ) {}

    public function handle(JobTimedOut $event): void
    {
        try {
            $command = $this->resolveCommand($event);
            if (! $command instanceof RunHeartbeatTaskJob || $command->taskRunId === null) {
                return;
            }

            $attempts = max(1, $event->job->attempts());
            $final = $attempts >= max(1, $command->tries);
            $message = $final
                ? '任务执行超时，已终止进程并达到最大重试次数'
                : '任务执行超时，已终止进程，等待队列重试';

            if ($final) {
                $this->taskRuns->markTerminalFailed($command->taskRunId, $message, null);
            } else {
                $this->taskRuns->markRetrying($command->taskRunId, $message, 0);
            }

            Log::warning('[心跳定时任务] 任务执行超时，运行状态已收敛', [
                'task' => $command->taskKey,
                'task_run_id' => $command->taskRunId,
                'attempt' => $attempts,
                'tries' => $command->tries,
                'timeout' => $command->timeout,
                'final' => $final,
            ]);
        } catch (Throwable $exception) {
            // 收敛失败不能阻断 worker 的 kill 流程；同任务下次触发槽位的租约回收仍会兜底。
            Log::error('[心跳定时任务] 超时收敛运行状态失败', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }
    }

    private function resolveCommand(JobTimedOut $event): mixed
    {
        $payload = json_decode((string) $event->job->getRawBody(), true);
        if (! is_array($payload)) {
            return null;
        }

        $command = $payload['data']['command'] ?? null;
        if (! is_string($command) || $command === '') {
            return null;
        }

        // 白名单需覆盖继承同 Job 的 RunScheduleTaskJob 子类实例，否则超时收敛会失效。
        return unserialize($command, ['allowed_classes' => [RunHeartbeatTaskJob::class, RunScheduleTaskJob::class]]);
    }
}
