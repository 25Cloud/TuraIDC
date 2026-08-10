<?php

use Illuminate\Support\Facades\Schedule;

/**
 * 唯一时钟源：Laravel Schedule 每分钟驱动一次全局心跳。
 * 具体业务任务仍由 HeartbeatTaskRegistry 按其 15 分钟槽位规则去重派发，
 * 队列则可在每分钟获得一次消费机会。
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

if ($shouldUseScheduleMutex) {
    $heartbeat->withoutOverlapping(2);
}
