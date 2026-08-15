<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat;

use App\Services\Automation\Heartbeat\Contracts\ScheduledTask;
use App\Services\Automation\Heartbeat\Data\TaskContext;
use App\Services\System\ScheduleRunLogService;

class HeartbeatTaskRunner
{
    public function __construct(
        private ScheduleRunLogService $scheduleRunLogService,
        private ScheduleTaskRunRepository $taskRuns,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(ScheduledTask $task, TaskContext $context, bool $finalFailure = false): array
    {
        // 队列重复投递、锁过期后的迟到 Job 不得再次执行业务处理。
        // 旧的测试替身/调用方可能没有返回值；只有明确返回 false 才表示 CAS 拒绝执行。
        if ($this->taskRuns->markRunning($context->taskRunId, $context->attempt) === false) {
            if ($context->taskRunId !== null && $context->taskRunId > 0) {
                $this->taskRuns->recordLateJobRejection(
                    (int) $context->taskRunId,
                    $context->attempt,
                    'schedule_run_not_active',
                );
            }

            return [
                'status' => 'skipped',
                'reason' => 'schedule_run_not_active',
                'task_run_id' => $context->taskRunId,
            ];
        }

        $startedAt = (int) (microtime(true) * 1000);

        try {
            $result = $this->scheduleRunLogService->record(
                $task->title(),
                fn (): array => $task->handle($context),
                $context->toLogContext(),
            );

            $durationMs = (int) (microtime(true) * 1000) - $startedAt;
            $summary = is_array($result) ? $result : ['result' => $result];
            $this->taskRuns->markSucceeded($context->taskRunId, $summary, $durationMs);

            return $summary;
        } catch (\Throwable $exception) {
            $durationMs = (int) (microtime(true) * 1000) - $startedAt;
            if ($finalFailure) {
                $this->taskRuns->markTerminalFailed($context->taskRunId, $exception->getMessage(), $durationMs);
            } else {
                // 队列仍会重试，记录保持 active，阻止新的心跳/手动触发重复入队。
                $this->taskRuns->markRetrying($context->taskRunId, $exception->getMessage(), $durationMs);
            }

            throw $exception;
        }
    }
}
