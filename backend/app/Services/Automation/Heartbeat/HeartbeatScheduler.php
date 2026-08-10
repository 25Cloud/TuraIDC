<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat;

use App\Jobs\RunHeartbeatTaskJob;
use App\Services\Automation\Heartbeat\Data\TickSummary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class HeartbeatScheduler
{
    public function __construct(
        private ScheduleTickRepository $ticks,
        private ScheduleTaskRunRepository $taskRuns,
        private HeartbeatTaskRegistry $registry,
        private TriggerRuleMatcher $matcher,
        private QueueDrainService $queueDrain,
    ) {}

    public function tick(CarbonImmutable $now): TickSummary
    {
        $slot = TickSlot::floorToFifteenMinutes($now);
        $tickModel = $this->ticks->firstOrCreateSlot($now);
        $tick = $this->ticks->toContext($tickModel);
        $lock = Cache::lock('scheduler:heartbeat:'.$slot->format('YmdHi'), 840);

        if (! $lock->get()) {
            return new TickSummary(
                $tick,
                [],
                [],
                [],
                $this->queueDrain->drainOnceIfDatabaseQueue(),
            );
        }

        $queued = [];
        $skipped = [];
        $duplicates = [];

        try {
            foreach ($this->registry->enabledTasks() as $task) {
                $matched = $this->matcher->firstMatchedRule($task->triggers(), $tick);
                if ($matched === null) {
                    $skipped[] = $task->key();

                    continue;
                }

                if ($this->taskRuns->activeRunForTask($task->key()) !== null) {
                    $duplicates[] = $task->key();

                    continue;
                }

                $run = $this->taskRuns->markQueued($tickModel, $task, $matched);
                if ($run === null) {
                    $duplicates[] = $task->key();

                    continue;
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
                    $this->taskRuns->markFailed((int) $run->id, '队列派发失败：'.$exception->getMessage(), 0);

                    Log::error('[心跳定时任务] 队列派发失败', [
                        'task' => $task->key(),
                        'tick_id' => $tickModel->id,
                        'task_run_id' => $run->id,
                        'message' => $exception->getMessage(),
                        'exception' => $exception::class,
                    ]);
                }
            }
        } finally {
            $lock->release();
        }

        return new TickSummary($tick, $queued, $skipped, $duplicates, $this->queueDrain->drainOnceIfDatabaseQueue());
    }
}
