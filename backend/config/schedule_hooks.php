<?php

use App\Services\Automation\ScheduleHookService;

return [
    /*
    |--------------------------------------------------------------------------
    | Schedule hooks
    |--------------------------------------------------------------------------
    |
    | Register lightweight listeners for scheduler extension points.
    |
    | Supported listener formats:
    | - App\Services\Automation\Hooks\ExampleHook::class
    | - [App\Services\Automation\Hooks\ExampleHook::class, 'handle']
    | - ['class' => App\Services\Automation\Hooks\ExampleHook::class, 'method' => 'handle']
    |
    | Listener classes receive: handle(string $hook, array $context): mixed.
    | Hook failures are logged and will not interrupt the scheduler.
    |
    | 频率语义：tick.every_minute / tick.every_five_minutes / *_five_minute_cron
    | 只是兼容旧命名的“声明频率”，平台真实执行粒度是 15 分钟心跳槽位，错过槽位
    | 不自动补跑。调度总览会同时输出 declared_cadence（声明）与 effective_cadence
    | （按 15 分钟槽位推断的真实频率），插件不得把兼容名称当作真实 1/5 分钟依据。
    |
    */
    'listeners' => [
        ScheduleHookService::HOOK_BEFORE_CRON => [
            //
        ],

        ScheduleHookService::HOOK_AFTER_CRON => [
            //
        ],

        ScheduleHookService::HOOK_BEFORE_DAILY_CRON => [
            //
        ],

        ScheduleHookService::HOOK_AFTER_DAILY_CRON => [
            //
        ],

        ScheduleHookService::HOOK_AFTER_FIVE_MINUTE_CRON => [
            //
        ],

        ScheduleHookService::HOOK_AFTER_HALF_HOUR_MINUTE_CRON => [
            //
        ],

        ScheduleHookService::HOOK_TASK_BEFORE => [
            //
        ],

        ScheduleHookService::HOOK_TASK_AFTER => [
            //
        ],

        ScheduleHookService::HOOK_TASK_FAILED => [
            //
        ],

        ScheduleHookService::HOOK_EVERY_MINUTE => [
            //
        ],

        ScheduleHookService::HOOK_EVERY_FIVE_MINUTES => [
            //
        ],

        ScheduleHookService::HOOK_HOURLY => [
            //
        ],

        ScheduleHookService::HOOK_DAILY => [
            //
        ],
    ],
];
