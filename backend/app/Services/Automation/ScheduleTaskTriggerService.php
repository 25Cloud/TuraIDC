<?php

namespace App\Services\Automation;

use App\Jobs\RunHeartbeatTaskJob;
use App\Services\Automation\Heartbeat\HeartbeatTaskRegistry;
use App\Services\Automation\Heartbeat\ScheduleTaskRunRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Predis\Client;

class ScheduleTaskTriggerService
{
    private ?bool $databaseQueueReady = null;

    public function __construct(
        private HeartbeatTaskRegistry $registry,
        private ScheduleTaskRunRepository $taskRuns,
    ) {}

    public function supports(string $taskKey): bool
    {
        return $this->registry->supportsManualTrigger(trim($taskKey));
    }

    public function dispatch(string $taskKey, ?int $adminUserId = null): array
    {
        $taskKey = trim($taskKey);

        if (! $this->supports($taskKey)) {
            throw new InvalidArgumentException('不支持的任务：'.$taskKey);
        }

        $task = $this->registry->get($taskKey);
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

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            RunHeartbeatTaskJob::dispatchSync(
                $taskKey,
                null,
                $run?->id ? (int) $run->id : null,
                $adminUserId,
                'manual_trigger',
                $task->timeout(),
            );

            return [
                'task' => $taskKey,
                'title' => $task->title(),
                'execution_mode' => 'sync',
            ];
        }

        if ($this->shouldUseQueue()) {
            try {
                RunHeartbeatTaskJob::dispatch(
                    $taskKey,
                    null,
                    $run?->id ? (int) $run->id : null,
                    $adminUserId,
                    'manual_trigger',
                    $task->timeout(),
                )->onQueue($task->queue());
            } catch (\Throwable $exception) {
                $this->taskRuns->markFailed($run?->id ? (int) $run->id : null, '队列派发失败：'.$exception->getMessage(), 0);

                throw $exception;
            }

            return [
                'task' => $taskKey,
                'title' => $task->title(),
                'execution_mode' => 'queue',
            ];
        }

        $this->logFallbackDispatch($taskKey, $adminUserId);
        RunHeartbeatTaskJob::dispatchAfterResponse(
            $taskKey,
            null,
            $run?->id ? (int) $run->id : null,
            $adminUserId,
            'manual_trigger',
            $task->timeout(),
        )->onQueue($task->queue());

        return [
            'task' => $taskKey,
            'title' => $task->title(),
            'execution_mode' => 'after_response',
        ];
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

        $table = (string) config('queue.connections.database.table', 'jobs');

        try {
            $this->databaseQueueReady = $table !== '' && Schema::hasTable($table);
        } catch (\Throwable $exception) {
            Log::warning('[手动任务触发] 检查队列表失败，回退为 afterResponse', [
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
        Log::info('[手动任务触发] 管理端立即执行采用 afterResponse/同步执行', [
            'task' => $taskKey,
            'admin_user_id' => $adminUserId,
            'queue_driver' => (string) config('queue.default', 'sync'),
            'running_in_console' => app()->runningInConsole(),
        ]);
    }
}
