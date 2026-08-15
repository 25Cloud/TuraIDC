<?php

declare(strict_types=1);

namespace App\Services\Admin\V2;

use App\Services\Automation\ScheduleTaskTriggerService;
use App\Services\Content\MediaFileService;
use App\Services\System\AdminLogService;

class AdminOperationalActionV2Service
{
    public function __construct(
        private readonly AdminLogService $logs,
        private readonly ScheduleTaskTriggerService $scheduleTasks,
        private readonly MediaFileService $mediaFiles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function logCleanupOverview(): array
    {
        return $this->logs->getCleanupOverview();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function cleanupLogs(array $payload): array
    {
        $result = $this->logs->cleanup($payload);

        return [
            'id' => 0,
            'status' => 'completed',
            'message' => '清理完成',
            'detail' => [
                'type' => 'log_cleanup',
                'cleanup' => [
                    'target' => (string) ($result['type'] ?? ''),
                    'keep_days' => (int) ($result['keep_days'] ?? 0),
                    'cutoff_at' => (string) ($result['cutoff_at'] ?? ''),
                    'affected' => is_array($result['affected'] ?? null) ? $result['affected'] : [],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function triggerScheduleTask(string $task, ?int $adminUserId): array
    {
        $result = $this->scheduleTasks->dispatch($task, $adminUserId);
        $taskKey = (string) ($result['task'] ?? $task);

        return [
            'id' => $taskKey,
            'status' => 'queued',
            'message' => '任务已触发',
            'detail' => [
                'type' => 'schedule_trigger',
                'task' => [
                    'key' => $taskKey,
                    'title' => (string) ($result['title'] ?? ''),
                    'execution_mode' => (string) ($result['execution_mode'] ?? ''),
                    'task_run_id' => isset($result['task_run_id']) ? (int) $result['task_run_id'] : null,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function retryScheduleTaskRun(
        int $runId,
        ?int $adminUserId,
        string $reason,
        ?string $ipAddress = null,
    ): array {
        $result = $this->scheduleTasks->retryFailedRun(
            runId: $runId,
            adminUserId: $adminUserId,
            reason: $reason,
            ipAddress: $ipAddress,
        );

        return [
            'id' => (int) ($result['task_run_id'] ?? 0),
            'status' => (string) ($result['status'] ?? 'queued'),
            'message' => '失败任务已重新排队',
            'detail' => [
                'type' => 'schedule_retry',
                'task' => [
                    'key' => (string) ($result['task'] ?? ''),
                    'title' => (string) ($result['title'] ?? ''),
                    'execution_mode' => (string) ($result['execution_mode'] ?? ''),
                ],
                'run' => [
                    'task_run_id' => (int) ($result['task_run_id'] ?? 0),
                    'parent_run_id' => (int) ($result['parent_run_id'] ?? 0),
                    'source' => (string) ($result['source'] ?? 'manual_retry'),
                    'status' => (string) ($result['status'] ?? 'queued'),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function reindexMediaFiles(int $adminUserId): array
    {
        $result = $this->mediaFiles->reindexMediaDirectory($adminUserId);

        return [
            'id' => 0,
            'status' => 'completed',
            'message' => '重新获取成功',
            'detail' => [
                'type' => 'media_reindex',
                'media' => [
                    'created' => (int) ($result['created'] ?? 0),
                    'skipped' => (int) ($result['skipped'] ?? 0),
                    'total' => (int) ($result['total'] ?? 0),
                    'unrecognized' => (array) ($result['unrecognized'] ?? []),
                ],
            ],
        ];
    }
}
