<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat;

use App\Models\ScheduleTaskRun;
use App\Models\ScheduleTick;
use App\Services\Automation\Heartbeat\Contracts\ScheduledTask;
use App\Services\Automation\Heartbeat\Contracts\TriggerRule;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;

class ScheduleTaskRunRepository
{
    public function markQueued(ScheduleTick $tick, ScheduledTask $task, TriggerRule $rule): ?ScheduleTaskRun
    {
        $run = ScheduleTaskRun::query()->firstOrCreate(
            [
                'schedule_tick_id' => (int) $tick->id,
                'task_key' => $task->key(),
                'source' => 'heartbeat',
            ],
            [
                'task_name' => $task->title(),
                'rule_description' => $rule->describe(),
                'queue' => $task->queue(),
                'status' => ScheduleTaskRun::STATUS_QUEUED,
                'queued_at' => now(),
            ],
        );

        return $run->wasRecentlyCreated ? $run : null;
    }

    public function markManualQueued(ScheduledTask $task, ?int $adminUserId = null): ?ScheduleTaskRun
    {
        if (! $this->tableReady()) {
            return null;
        }

        return ScheduleTaskRun::query()->create([
            'task_key' => $task->key(),
            'task_name' => $task->title(),
            'rule_description' => '手动触发',
            'source' => 'manual_trigger',
            'queue' => $task->queue(),
            'status' => ScheduleTaskRun::STATUS_QUEUED,
            'summary' => array_filter([
                'admin_user_id' => $adminUserId,
            ], static fn (mixed $value): bool => $value !== null),
            'queued_at' => now(),
        ]);
    }

    public function activeRunForTask(string $taskKey): ?ScheduleTaskRun
    {
        if (! $this->tableReady()) {
            return null;
        }

        $taskKey = trim($taskKey);
        if ($taskKey === '') {
            return null;
        }

        return ScheduleTaskRun::query()
            ->where('task_key', $taskKey)
            ->whereIn('status', [
                ScheduleTaskRun::STATUS_QUEUED,
                ScheduleTaskRun::STATUS_RUNNING,
            ])
            ->oldest('queued_at')
            ->first();
    }

    public function markRunning(?int $runId): void
    {
        if ($runId === null || $runId <= 0) {
            return;
        }

        ScheduleTaskRun::query()
            ->whereKey($runId)
            ->whereNull('started_at')
            ->update([
                'started_at' => now(),
            ]);

        $this->updateRun($runId, [
            'status' => ScheduleTaskRun::STATUS_RUNNING,
            'finished_at' => null,
            'error_msg' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function markSucceeded(?int $runId, array $summary, int $durationMs): void
    {
        $this->updateRun($runId, [
            'status' => ScheduleTaskRun::STATUS_SUCCESS,
            'duration_ms' => $durationMs,
            'summary' => $summary,
            'finished_at' => now(),
        ]);
    }

    public function markFailed(?int $runId, string $message, int $durationMs): void
    {
        $this->updateRun($runId, [
            'status' => ScheduleTaskRun::STATUS_FAILED,
            'duration_ms' => $durationMs,
            'error_msg' => mb_substr($message, 0, 2000),
            'finished_at' => now(),
        ]);
    }

    public function latestRunForTask(string $taskKey): ?array
    {
        if (! $this->tableReady()) {
            return null;
        }

        $run = ScheduleTaskRun::query()
            ->where('task_key', $taskKey)
            ->latest('id')
            ->first();

        return $run instanceof ScheduleTaskRun ? $this->serializeRun($run) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recentRuns(int $limit = 24): array
    {
        if (! $this->tableReady()) {
            return [];
        }

        return ScheduleTaskRun::query()
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (ScheduleTaskRun $run): array => $this->serializeRun($run))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRun(ScheduleTaskRun $run): array
    {
        $time = $run->finished_at ?? $run->started_at ?? $run->queued_at ?? $run->created_at;

        return [
            'time' => $time instanceof CarbonInterface ? $time->toDateTimeString() : null,
            'level' => strtoupper((string) $run->status),
            'message' => (string) $run->task_name,
            'task_key' => (string) $run->task_key,
            'status' => (string) $run->status,
            'source' => (string) $run->source,
            'duration_ms' => $run->duration_ms,
            'summary' => $run->summary,
            'error_msg' => $run->error_msg,
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function updateRun(?int $runId, array $attributes): void
    {
        if ($runId === null || $runId <= 0) {
            return;
        }

        ScheduleTaskRun::query()
            ->whereKey($runId)
            ->update($attributes);
    }

    public function tableReady(): bool
    {
        try {
            return Schema::hasTable('schedule_task_runs');
        } catch (\Throwable) {
            return false;
        }
    }
}
