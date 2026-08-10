<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat\Providers;

use App\Services\Automation\Heartbeat\CallbackScheduledTask;
use App\Services\Automation\Heartbeat\Contracts\ScheduledTaskProvider;
use App\Services\Automation\Heartbeat\ScheduleRule;
use App\Services\Automation\ScheduleHookService;

class LegacyScheduleHookTaskProvider implements ScheduledTaskProvider
{
    private const HOOK_TASKS = [
        [
            'key' => 'schedule-hook-every-minute',
            'title' => '调度扩展 Hook（每分钟）',
            'hook' => ScheduleHookService::HOOK_EVERY_MINUTE,
            'description' => '兼容 tick.every_minute 监听器名称，当前按每 15 分钟心跳触发。',
            'interval' => 1,
        ],
        [
            'key' => 'schedule-hook-every-five-minutes',
            'title' => '调度扩展 Hook（每五分钟）',
            'hook' => ScheduleHookService::HOOK_EVERY_FIVE_MINUTES,
            'description' => '兼容 tick.every_five_minutes 监听器名称，当前按每 15 分钟心跳触发。',
            'interval' => 1,
        ],
        [
            'key' => 'schedule-hook-after-five-minute-cron',
            'title' => '调度扩展 Hook（旧系统每五分钟后）',
            'hook' => ScheduleHookService::HOOK_AFTER_FIVE_MINUTE_CRON,
            'description' => '兼容旧系统 after_five_minute_cron 钩子，当前按每 15 分钟心跳触发。',
            'interval' => 1,
        ],
        [
            'key' => 'schedule-hook-after-half-hour-minute-cron',
            'title' => '调度扩展 Hook（旧系统半小时后）',
            'hook' => ScheduleHookService::HOOK_AFTER_HALF_HOUR_MINUTE_CRON,
            'description' => '兼容旧系统 after_half_hour_minute_cron 钩子。',
            'interval' => 2,
        ],
        [
            'key' => 'schedule-hook-hourly',
            'title' => '调度扩展 Hook（每小时）',
            'hook' => ScheduleHookService::HOOK_HOURLY,
            'description' => '兼容 tick.hourly 监听器名称。',
            'interval' => 4,
        ],
        [
            'key' => 'schedule-hook-daily',
            'title' => '调度扩展 Hook（每日）',
            'hook' => ScheduleHookService::HOOK_DAILY,
            'description' => '兼容 tick.daily 监听器名称。',
            'cron' => '0 3 * * *',
        ],
        [
            'key' => 'schedule-hook-before-daily-cron',
            'title' => '调度扩展 Hook（旧系统每日前）',
            'hook' => ScheduleHookService::HOOK_BEFORE_DAILY_CRON,
            'description' => '兼容旧系统 before_daily_cron 钩子。',
            'cron' => '0 3 * * *',
        ],
        [
            'key' => 'schedule-hook-after-daily-cron',
            'title' => '调度扩展 Hook（旧系统每日后）',
            'hook' => ScheduleHookService::HOOK_AFTER_DAILY_CRON,
            'description' => '兼容旧系统 after_daily_cron 钩子。',
            'cron' => '0 3 * * *',
        ],
    ];

    public function __construct(
        private ScheduleHookService $hookService,
    ) {}

    public function tasks(): array
    {
        $tasks = [];

        foreach (self::HOOK_TASKS as $definition) {
            $hook = (string) $definition['hook'];
            if (! $this->hookService->hasListeners($hook)) {
                continue;
            }

            $triggers = isset($definition['cron'])
                ? [ScheduleRule::cron((string) $definition['cron'])]
                : [ScheduleRule::everyTicks((int) $definition['interval'])];

            $tasks[] = new CallbackScheduledTask(
                key: (string) $definition['key'],
                title: (string) $definition['title'],
                description: (string) $definition['description'],
                category: '调度扩展',
                triggers: $triggers,
                handler: fn (): array => [
                    'hook' => $hook,
                    'results' => $this->hookService->run($hook, [
                        'task_key' => $definition['key'],
                        'hook' => $hook,
                        'source' => 'heartbeat_schedule_hook',
                    ]),
                ],
                queue: 'default',
                timeout: 600,
                lockTtlSeconds: 900,
                manualTriggerable: false,
            );
        }

        return $tasks;
    }
}
