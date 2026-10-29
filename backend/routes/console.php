<?php

use Illuminate\Support\Facades\Schedule;

/**
 * 唯一时钟源：Laravel Schedule 每分钟驱动一次全局心跳与队列消费。
 * 心跳负责按 15 分钟槽位规则去重派发定时任务，不再内联消费队列；
 * 队列由独立的 queue:drain 后台消费，二者互不阻塞（对应参考系统 cron/task 分离）。
 */
$shouldUseScheduleMutex = ! (
    PHP_OS_FAMILY === 'Windows'
    && (string) config('cache.default', 'file') === 'file'
);

config([
    'idc.schedule_runtime' => array_replace_recursive(
        (array) config('idc.schedule_runtime', []),
        [
            'mutex' => [
                'enabled' => $shouldUseScheduleMutex,
                'degraded' => ! $shouldUseScheduleMutex,
                'mode' => $shouldUseScheduleMutex ? 'without_overlapping' : 'degraded',
                'reason' => $shouldUseScheduleMutex ? '' : 'windows_file_cache_lock_unreliable',
                'cache_store' => (string) config('cache.default', 'file'),
                'os_family' => PHP_OS_FAMILY,
            ],
            'automation_config' => [
                'status' => 'loaded',
                'fallback_reason' => '',
            ],
        ],
    ),
]);

$heartbeat = Schedule::command('scheduler:heartbeat')
    ->everyMinute()
    ->name('scheduler-heartbeat');

// 队列消费与心跳解耦：queue:drain 在后台子进程中运行，长任务不会阻塞
// schedule:run 与心跳派发；drain 锁保证同一队列不并发消费。
Schedule::command('queue:drain')
    ->everyMinute()
    ->name('queue-drain')
    ->runInBackground();

// 存活探针与心跳解耦：即使心跳命令抛异常，探针仍能发现并告警。
Schedule::command('scheduler:liveness')
    ->everyMinute()
    ->name('scheduler-liveness');

// 上游上传文件防滥用：删除超过保留期（默认 5 分钟）且未被工单回复引用的上传文件。
// 上传即占用磁盘，需高频清理；withoutOverlapping 防止并发重复扫描。
$cleanupUpstreamUploads = Schedule::command('tickets:cleanup-unused-upstream-uploads')
    ->everyMinute()
    ->name('tickets-cleanup-unused-upstream-uploads')
    ->runInBackground();

// 开放接口使用日志防膨胀：api_key_usage_logs 只增不减，按保留期（默认 90 天，
// open_api.usage_log_retention_days 可调）每周清理一次。
$pruneOpenApiUsageLogs = Schedule::command('open-api:prune-usage-logs')
    ->weekly()
    ->name('open-api-prune-usage-logs')
    ->runInBackground();

// AI 会话保留期清理不在这里登记：它由 ai_assistant 插件清单的 extra.scheduled_tasks
// 声明，走 PluginScheduledTaskProvider → 心跳派发（见插件规范第 7 节）。核心去 schedule
// 插件命令会在插件停用/卸载后留下每天必然「命令不存在」的失败记录，而且核心得额外
// 知道这个功能装没装。清单声明的话，插件不在，任务就没了，这里一行都不用改。

if ($shouldUseScheduleMutex) {
    // 每分钟跑的秒级任务，2 分钟锁足够。
    $cleanupUpstreamUploads->withoutOverlapping(2);
    // 批量清理可能跑几分钟：锁 TTL 短于实际耗时时，下一轮会拿到已过期锁再起一个实例，
    // 两个进程同时 DELETE 同一批数据只会叠加锁等待。按「最长容忍耗时」而不是「预期耗时」设。
    $pruneOpenApiUsageLogs->withoutOverlapping(30);
}

if ($shouldUseScheduleMutex) {
    $heartbeat->withoutOverlapping(2);
}
