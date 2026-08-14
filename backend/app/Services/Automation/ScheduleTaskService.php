<?php

namespace App\Services\Automation;

use App\Models\ScheduleRunLog;
use App\Services\Automation\Heartbeat\Contracts\ScheduledTask;
use App\Services\Automation\Heartbeat\HeartbeatTaskRegistry;
use App\Services\Automation\Heartbeat\Providers\LegacyScheduleHookTaskProvider;
use App\Services\Automation\Heartbeat\Providers\PluginScheduledTaskProvider;
use App\Services\Automation\Heartbeat\ScheduleTaskRunRepository;
use App\Services\System\SettingService;
use App\Support\AutomationScheduleExpression;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ScheduleTaskService
{
    public function __construct(
        private SettingService $settingService,
        private ScheduleTaskTriggerService $scheduleTaskTriggerService,
        private HeartbeatTaskRegistry $registry,
        private ScheduleTaskRunRepository $taskRuns,
        private LegacyScheduleHookTaskProvider $legacyScheduleHookTaskProvider,
        private PluginScheduledTaskProvider $pluginScheduledTaskProvider,
    ) {}

    public function overview(): array
    {
        $jobsTableReady = Schema::hasTable('jobs');
        $failedJobsTableReady = Schema::hasTable('failed_jobs');
        $appTimezone = (string) config('app.timezone', date_default_timezone_get());
        $phpBinary = PHP_BINARY;
        $artisanPath = base_path('artisan');
        $runtimeState = $this->resolveScheduleRuntimeState();
        $thirdPartyTaskKeys = $this->thirdPartyTaskKeys();

        return [
            'environment' => [
                'app_env' => (string) config('app.env', 'production'),
                'app_timezone' => $appTimezone,
                'php_binary' => $phpBinary,
                'artisan_path' => $artisanPath,
                'schedule_source' => base_path('routes/console.php'),
                'queue_driver' => (string) config('queue.default', 'sync'),
                'jobs_table_ready' => $jobsTableReady,
                'failed_jobs_table_ready' => $failedJobsTableReady,
                'pending_jobs' => $jobsTableReady ? (int) DB::table('jobs')->count() : null,
                'failed_jobs' => $failedJobsTableReady ? (int) DB::table('failed_jobs')->count() : null,
                'queue_runtime_mode' => $this->resolveQueueRuntimeMode($jobsTableReady),
                'schedule_mutex' => $runtimeState['mutex'],
                'automation_config' => $runtimeState['automation_config'],
            ],
            'commands' => $this->buildCommands($phpBinary, $artisanPath),
            'tasks' => collect($this->registry->enabledTasks())
                ->map(fn (ScheduledTask $task): array => $this->serializeTask($task, $appTimezone, $thirdPartyTaskKeys))
                ->values()
                ->all(),
            'recent_logs' => $this->recentLogs(),
            'settings_snapshot' => $this->buildSettingsSnapshot(),
        ];
    }

    /**
     * @param  array<string, bool>  $thirdPartyTaskKeys
     */
    private function serializeTask(ScheduledTask $task, string $appTimezone, array $thirdPartyTaskKeys): array
    {
        $now = now($appTimezone);
        $nextRunAt = collect($task->triggers())
            ->map(fn ($rule) => $rule->nextDueAfter($now))
            ->filter()
            ->sortBy(fn ($date) => $date->getTimestamp())
            ->first();
        $expression = collect($task->triggers())
            ->map(fn ($rule): string => $rule->describe())
            ->filter()
            ->implode('；');
        $sourceType = isset($thirdPartyTaskKeys[$task->key()]) ? 'third_party' : 'system';

        return [
            'key' => $task->key(),
            'title' => $task->title(),
            'category' => $task->category(),
            'source_type' => $sourceType,
            'source_label' => $sourceType === 'third_party' ? '第三方任务' : '系统任务',
            'description' => $task->description(),
            'manual_triggerable' => $this->scheduleTaskTriggerService->supports($task->key()),
            'expression' => $expression !== '' ? $expression : '手动触发',
            'summary' => $expression !== '' ? $expression : '手动触发',
            'timezone' => $appTimezone,
            'next_run_at' => $nextRunAt?->setTimezone($appTimezone)->format('Y-m-d H:i:s'),
            'without_overlapping' => true,
            'run_in_background' => false,
            'overlap_expires_minutes' => max(1, (int) ceil($task->lockTtlSeconds() / 60)),
            'last_log' => $this->taskRuns->latestRunForTask($task->key()) ?? $this->latestLegacyLogForTask($task->title(), $task->key()),
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function thirdPartyTaskKeys(): array
    {
        $keys = [];

        foreach ([$this->legacyScheduleHookTaskProvider, $this->pluginScheduledTaskProvider] as $provider) {
            foreach ($provider->tasks() as $task) {
                if ($task instanceof ScheduledTask) {
                    $taskKey = trim($task->key());
                    if ($taskKey !== '') {
                        $keys[$taskKey] = true;
                    }
                }
            }
        }

        return $keys;
    }

    private function resolveScheduleRuntimeState(): array
    {
        $runtimeState = (array) config('idc.schedule_runtime', []);
        $cacheStore = trim((string) ($runtimeState['mutex']['cache_store'] ?? config('cache.default', 'file')));
        $osFamily = trim((string) ($runtimeState['mutex']['os_family'] ?? PHP_OS_FAMILY));
        $mutexEnabled = (bool) ($runtimeState['mutex']['enabled'] ?? ! ($osFamily === 'Windows' && $cacheStore === 'file'));
        $mutexDegraded = (bool) ($runtimeState['mutex']['degraded'] ?? ! $mutexEnabled);
        $mutexMode = trim((string) ($runtimeState['mutex']['mode'] ?? ($mutexEnabled ? 'without_overlapping' : 'degraded')));
        $mutexReason = trim((string) ($runtimeState['mutex']['reason'] ?? ''));
        $automationStatus = trim((string) ($runtimeState['automation_config']['status'] ?? 'loaded'));
        $fallbackReason = trim((string) ($runtimeState['automation_config']['fallback_reason'] ?? ''));

        return [
            'mutex' => [
                'enabled' => $mutexEnabled,
                'degraded' => $mutexDegraded,
                'mode' => $mutexMode !== '' ? $mutexMode : ($mutexEnabled ? 'without_overlapping' : 'degraded'),
                'reason' => $mutexReason,
                'cache_store' => $cacheStore,
                'os_family' => $osFamily,
            ],
            'automation_config' => [
                'status' => $automationStatus !== '' ? $automationStatus : 'loaded',
                'fallback_reason' => $fallbackReason,
            ],
        ];
    }

    private function buildCommands(string $phpBinary, string $artisanPath): array
    {
        $quotedPhp = $this->quoteShellArgument($phpBinary);
        $quotedArtisan = $this->quoteShellArgument($artisanPath);
        $queueWorkerQueues = (string) config('queue.TuraIDC_worker_queues', 'provision,referral,notification,coupon,default');
        $queueWorkerTries = (int) config('queue.TuraIDC_worker_tries', 3);
        $queueWorkerTimeout = (int) config('queue.TuraIDC_worker_timeout', 1200);

        return [
            [
                'key' => 'schedule_run',
                'title' => '调度入口',
                'description' => '宝塔生产环境请仅保留这一条，每 1 分钟运行一次；业务任务按 15 分钟槽位去重，队列也会每分钟尝试消费。',
                'command' => "{$quotedPhp} {$quotedArtisan} schedule:run",
            ],
            [
                'key' => 'scheduler_heartbeat',
                'title' => '心跳命令',
                'description' => '由 schedule:run 自动触发；排查时可手动执行一次心跳。',
                'command' => "{$quotedPhp} {$quotedArtisan} scheduler:heartbeat",
            ],
            [
                'key' => 'schedule_work',
                'title' => '本地调度 Worker',
                'description' => '本地开发环境可常驻运行以下命令，无需额外系统 Cron。',
                'command' => "{$quotedPhp} {$quotedArtisan} schedule:work",
            ],
            [
                'key' => 'queue_work',
                'title' => '队列 Worker（可选）',
                'description' => '仅在你需要更低延迟时再单独常驻运行；宝塔单计划任务方案下不是必需。',
                'command' => "{$quotedPhp} {$quotedArtisan} queue:work --queue={$queueWorkerQueues} --sleep=1 --tries={$queueWorkerTries} --timeout={$queueWorkerTimeout}",
            ],
        ];
    }

    private function recentLogs(): array
    {
        $runs = $this->taskRuns->recentRuns(24);
        if ($runs !== []) {
            return $runs;
        }

        if (! Schema::hasTable('schedule_run_logs')) {
            return [];
        }

        return ScheduleRunLog::query()
            ->latest('id')
            ->limit(24)
            ->get()
            ->map(fn (ScheduleRunLog $log): array => [
                'time' => $log->finished_at?->toDateTimeString() ?? $log->created_at?->toDateTimeString(),
                'level' => strtoupper((string) $log->status),
                'message' => (string) $log->task_name,
                'task_key' => null,
                'status' => (string) $log->status,
                'duration_ms' => $log->duration_ms,
                'summary' => $log->summary,
                'error_msg' => $log->error_msg,
            ])
            ->values()
            ->all();
    }

    private function latestLegacyLogForTask(string $taskName, string $taskKey): ?array
    {
        if (! Schema::hasTable('schedule_run_logs')) {
            return null;
        }

        $log = ScheduleRunLog::query()
            ->where('task_name', $taskName)
            ->latest('id')
            ->first();

        if (! $log instanceof ScheduleRunLog) {
            return null;
        }

        return [
            'time' => $log->finished_at?->toDateTimeString() ?? $log->created_at?->toDateTimeString(),
            'level' => strtoupper((string) $log->status),
            'message' => (string) $log->task_name,
            'task_key' => $taskKey,
            'status' => (string) $log->status,
            'duration_ms' => $log->duration_ms,
            'summary' => $log->summary,
            'error_msg' => $log->error_msg,
        ];
    }

    private function resolveQueueRuntimeMode(bool $jobsTableReady): string
    {
        $driver = (string) config('queue.default', 'sync');

        return match ($driver) {
            'database' => $jobsTableReady ? 'database_queue_heartbeat_drained' : 'after_response_fallback',
            'redis' => 'redis_queue',
            'sync' => 'sync_inline',
            default => $driver,
        };
    }

    private function quoteShellArgument(string $value): string
    {
        return '"'.str_replace('"', '\"', $value).'"';
    }

    private function buildSettingsSnapshot(): array
    {
        $config = $this->settingService->getAutomationConfig();

        return [
            [
                'label' => '到期自动暂停',
                'value' => $config['expire_suspend_enabled'] ? '已开启' : '已关闭',
                'note' => $config['expire_suspend_enabled']
                    ? "到期后 {$config['expire_suspend_after_days']} 天暂停，任务周期："
                        .AutomationScheduleExpression::describe(
                            (string) $config['service_lifecycle_schedule_mode'],
                            (string) $config['service_lifecycle_schedule_time'],
                            AutomationScheduleExpression::MODE_EVERY_FIFTEEN_MINUTES,
                            '00:00:00'
                        )
                    : '不会自动暂停到期服务',
            ],
            [
                'label' => '续费提醒',
                'value' => $config['renew_notice_enabled'] ? '已开启' : '已关闭',
                'note' => $config['renew_notice_enabled']
                    ? '提醒天数：'.implode(' / ', $config['renew_notice_days_before']).' 天前，任务周期：'
                        .AutomationScheduleExpression::describe(
                            (string) $config['billing_maintenance_schedule_mode'],
                            (string) $config['billing_maintenance_schedule_time'],
                            AutomationScheduleExpression::MODE_HOURLY,
                            '00:00:00'
                        )
                    : '不会主动发送续费提醒',
            ],
            [
                'label' => '工单自动关闭',
                'value' => $config['ticket_auto_close_enabled'] ? '已开启' : '已关闭',
                'note' => $config['ticket_auto_close_enabled']
                    ? "员工回复后 {$config['ticket_auto_close_after_hours']} 小时自动关闭，任务周期："
                        .AutomationScheduleExpression::describe(
                            (string) $config['ticket_auto_close_schedule_mode'],
                            (string) $config['ticket_auto_close_schedule_time'],
                            AutomationScheduleExpression::MODE_HOURLY,
                            '00:00:00'
                        )
                    : '工单仅支持人工关闭',
            ],
            [
                'label' => '未付款账单清理',
                'value' => $config['pending_order_cleanup_enabled'] ? '已开启' : '已关闭',
                'note' => $config['pending_order_cleanup_enabled']
                    ? '未付款订单、账单和充值 5 分钟后自动取消，任务周期：'
                        .AutomationScheduleExpression::describe(
                            (string) $config['order_cleanup_schedule_mode'],
                            (string) $config['order_cleanup_schedule_time'],
                            AutomationScheduleExpression::MODE_EVERY_FIFTEEN_MINUTES,
                            '00:00:00'
                        )
                    : '不会自动取消未付款账单',
            ],
        ];
    }
}
