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
    public function run(ScheduledTask $task, TaskContext $context): array
    {
        $this->taskRuns->markRunning($context->taskRunId);

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
            $this->taskRuns->markFailed($context->taskRunId, $exception->getMessage(), $durationMs);

            throw $exception;
        }
    }
}
