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
