<?php

namespace App\Services\Automation;

use App\Models\ScheduleRunLog;
use App\Models\ScheduleTaskRun;
use App\Services\Automation\Heartbeat\Contracts\ScheduledTask;
use App\Services\Automation\Heartbeat\Contracts\ScheduledTaskCadence;
use App\Services\Automation\Heartbeat\HeartbeatTaskRegistry;
use App\Services\Automation\Heartbeat\Providers\LegacyScheduleHookTaskProvider;
use App\Services\Automation\Heartbeat\Providers\PluginScheduledTaskProvider;
use App\Services\Automation\Heartbeat\Rules\CronRule;
use App\Services\Automation\Heartbeat\Rules\DailyTick;
use App\Services\Automation\Heartbeat\Rules\EveryTicks;
use App\Services\Automation\Heartbeat\ScheduleTaskRunRepository;
use App\Services\System\SettingService;
use App\Support\AutomationScheduleExpression;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as ConcreteLengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

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
        [$jobsConnection, $jobsTable] = $this->databaseQueueTable();
        [$failedJobsConnection, $failedJobsTable] = $this->failedQueueTable();
        $jobsTableReady = $this->tableReady($jobsConnection, $jobsTable);
        $failedJobsTableReady = $this->tableReady($failedJobsConnection, $failedJobsTable);
        $appTimezone = (string) config('app.timezone', date_default_timezone_get());
        $phpBinary = PHP_BINARY;
        $artisanPath = base_path('artisan');
        $thirdPartyTaskKeys = $this->thirdPartyTaskKeys();
        // 插件 Provider 可能在扫描过程中更新降级状态，环境快照应反映本次扫描结果。
        $runtimeState = $this->resolveScheduleRuntimeState();

        return [
            'environment' => [
                'app_env' => (string) config('app.env', 'production'),
                'app_timezone' => $appTimezone,
                'php_binary' => $phpBinary,
                'artisan_path' => $artisanPath,
                'schedule_source' => base_path('routes/console.php'),
                'queue_driver' => (string) config('queue.default', 'sync'),
                'business_queue' => (string) config('queue.turaidc_business_queues', 'provision,referral,notification,coupon,default'),
                'automation_queue' => (string) config('queue.turaidc_schedule_queue', 'automation'),
                'queue_connection' => $jobsConnection,
                'jobs_table' => $jobsTable,
                'jobs_table_ready' => $jobsTableReady,
                'failed_jobs_connection' => $failedJobsConnection,
                'failed_jobs_table' => $failedJobsTable,
                'failed_jobs_table_ready' => $failedJobsTableReady,
                'pending_jobs' => $jobsTableReady ? $this->tableCount($jobsConnection, $jobsTable) : null,
                'failed_jobs' => $failedJobsTableReady ? $this->tableCount($failedJobsConnection, $failedJobsTable) : null,
                'queue_runtime_mode' => $this->resolveQueueRuntimeMode($jobsTableReady),
                'missed_slot_policy' => 'strict_current_slot',
                'schedule_mutex' => $runtimeState['mutex'],
                'automation_config' => $runtimeState['automation_config'],
                'plugin_tasks' => $runtimeState['plugin_tasks'],
            ],
            'commands' => $this->buildCommands($phpBinary, $artisanPath),
            'tasks' => collect($this->registry->enabledTasks())
                ->map(fn (ScheduledTask $task): array => $this->serializeTask($task, $appTimezone, $thirdPartyTaskKeys))
                ->values()
                ->all(),
            'runs_summary' => $this->taskRuns->runStatsSummary(),
            'recent_logs' => $this->recentLogs(),
            'settings_snapshot' => $this->buildSettingsSnapshot(),
        ];
    }

    /**
     * 查询运行台账。表尚未在当前环境落库时返回空分页，避免只读管理端被基础设施状态拖垮。
     *
     * @param  array<string, mixed>  $filters
     */
    public function paginateRuns(array $filters, int $page = 1, int $perPage = 20): LengthAwarePaginator
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        if (! $this->taskRuns->tableReady()) {
            return new ConcreteLengthAwarePaginator([], 0, $perPage, $page);
        }

        return $this->taskRuns->paginateRuns($filters, $page, $perPage);
    }

    public function runDetail(int $runId): ?ScheduleTaskRun
    {
        return $this->taskRuns->findRun($runId);
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
            'declared_cadence' => $task instanceof ScheduledTaskCadence ? $task->declaredCadence() : null,
            'effective_cadence' => $this->effectiveCadenceFor($task),
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
     * 按 15 分钟槽位真实语义推断有效频率；兼容名称（every_minute 等）不作为推断依据。
     */
    private function effectiveCadenceFor(ScheduledTask $task): string
    {
        $parts = [];

        foreach ($task->triggers() as $rule) {
            if ($rule instanceof EveryTicks) {
                $minutes = $rule->interval() * 15;
                $parts[] = $minutes === 15 ? '15分钟' : "{$minutes}分钟";
            } elseif ($rule instanceof CronRule) {
                $parts[] = 'cron '.$rule->describe();
            } elseif ($rule instanceof DailyTick) {
                $parts[] = '每日第 '.$rule->index().' 个心跳';
            } else {
                $parts[] = $rule->describe();
            }
        }

        return implode('；', $parts) ?: '手动触发';
    }

    /**
     * @return array<string, bool>
     */
    private function thirdPartyTaskKeys(): array
    {
        $keys = [];

        foreach ([$this->legacyScheduleHookTaskProvider, $this->pluginScheduledTaskProvider] as $provider) {
            try {
                foreach ($provider->tasks() as $task) {
                    if ($task instanceof ScheduledTask) {
                        $taskKey = trim($task->key());
                        if ($taskKey !== '') {
                            $keys[$taskKey] = true;
                        }
                    }
                }
            } catch (Throwable $exception) {
                Log::warning('[定时任务] 任务来源标记失败，已按系统任务展示其余任务', [
                    'provider' => $provider::class,
                    'message' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);
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
        $pluginTaskState = is_array($runtimeState['plugin_tasks'] ?? null)
            ? $runtimeState['plugin_tasks']
            : ['status' => 'loaded', 'error_count' => 0];

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
            'plugin_tasks' => [
                'status' => trim((string) ($pluginTaskState['status'] ?? 'loaded')) ?: 'loaded',
                'error_count' => max(0, (int) ($pluginTaskState['error_count'] ?? 0)),
            ],
        ];
    }

    private function buildCommands(string $phpBinary, string $artisanPath): array
    {
        $quotedPhp = $this->quoteShellArgument($phpBinary);
        $quotedArtisan = $this->quoteShellArgument($artisanPath);
        $businessQueues = (string) config('queue.turaidc_business_queues', 'provision,referral,notification,coupon,default');
        $automationQueue = (string) config('queue.turaidc_schedule_queue', 'automation');
        $queueWorkerTries = (int) config('queue.turaidc_worker_tries', 3);
        $queueWorkerTimeout = max(
            (int) config('queue.turaidc_worker_timeout', 1200),
            (int) config('queue.turaidc_worker_max_timeout', 3600),
        );

        return [
            [
                'key' => 'schedule_run',
                'title' => '调度入口',
                'description' => '宝塔生产环境请仅保留这一条，每 1 分钟运行一次；心跳派发定时任务，queue:drain 后台消费业务与 automation 队列。',
                'command' => "{$quotedPhp} {$quotedArtisan} schedule:run",
            ],
            [
                'key' => 'scheduler_heartbeat',
                'title' => '心跳命令',
                'description' => '由 schedule:run 自动触发；排查时可手动执行一次心跳。',
                'command' => "{$quotedPhp} {$quotedArtisan} scheduler:heartbeat",
            ],
            [
                'key' => 'queue_drain',
                'title' => '队列消费命令',
                'description' => '由 schedule:run 每分钟后台触发；排查时可手动执行一次消费业务与 automation 队列。',
                'command' => "{$quotedPhp} {$quotedArtisan} queue:drain",
            ],
            [
                'key' => 'schedule_work',
                'title' => '本地调度 Worker',
                'description' => '本地开发环境可常驻运行以下命令，无需额外系统 Cron。',
                'command' => "{$quotedPhp} {$quotedArtisan} schedule:work",
            ],
            [
                'key' => 'app_serve_schedule_without_vnc',
                'title' => '本地调度入口（独立 Relay）',
                'description' => '本地需要同时运行 HTTP 和调度时使用；VNC Relay 另行运行 vnc:relay。',
                'command' => "{$quotedPhp} {$quotedArtisan} app:serve --with-schedule --without-vnc",
            ],
            [
                'key' => 'queue_work_business',
                'title' => '业务队列 Worker',
                'description' => '独立消费新购、续费履约、通知、返佣、优惠券和默认业务队列。',
                'command' => "{$quotedPhp} {$quotedArtisan} queue:work --queue={$businessQueues} --sleep=1 --tries={$queueWorkerTries} --timeout={$queueWorkerTimeout}",
            ],
            [
                'key' => 'queue_work_automation',
                'title' => '定时队列 Worker',
                'description' => '只消费 automation 队列，避免长定时任务占用业务队列。',
                'command' => "{$quotedPhp} {$quotedArtisan} queue:work --queue={$automationQueue} --sleep=1 --tries={$queueWorkerTries} --timeout={$queueWorkerTimeout}",
            ],
            [
                'key' => 'vnc_relay',
                'title' => 'VNC Relay 守护',
                'description' => '生产环境由宝塔守护或进程管理器常驻运行并自动重启。',
                'command' => "{$quotedPhp} {$quotedArtisan} vnc:relay",
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
            'database' => $jobsTableReady ? 'database_queue_drain_command' : 'after_response_fallback',
            'redis' => 'redis_queue',
            'sync' => 'sync_inline',
            default => $driver,
        };
    }

    private function quoteShellArgument(string $value): string
    {
        return '"'.str_replace('"', '\"', $value).'"';
    }

    /** @return array{0:string,1:string} */
    private function databaseQueueTable(): array
    {
        $config = (array) config('queue.connections.database', []);

        return [
            trim((string) ($config['connection'] ?? '')) ?: (string) config('database.default'),
            trim((string) ($config['table'] ?? 'jobs')),
        ];
    }

    /** @return array{0:string,1:string} */
    private function failedQueueTable(): array
    {
        $config = (array) config('queue.failed', []);

        return [
            trim((string) ($config['database'] ?? '')) ?: (string) config('database.default'),
            trim((string) ($config['table'] ?? 'failed_jobs')),
        ];
    }

    private function tableReady(string $connection, string $table): bool
    {
        if ($connection === '' || $table === '') {
            return false;
        }

        try {
            return Schema::connection($connection)->hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }

    private function tableCount(string $connection, string $table): ?int
    {
        try {
            return (int) DB::connection($connection)->table($table)->count();
        } catch (Throwable) {
            return null;
        }
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
