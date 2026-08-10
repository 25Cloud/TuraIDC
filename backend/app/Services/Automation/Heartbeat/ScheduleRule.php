<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat;

use App\Services\Automation\Heartbeat\Contracts\TriggerRule;
use App\Services\Automation\Heartbeat\Rules\CronRule;
use App\Services\Automation\Heartbeat\Rules\DailyTick;
use App\Services\Automation\Heartbeat\Rules\EveryTicks;
use App\Support\AutomationScheduleExpression;

final class ScheduleRule
{
    public static function everyTicks(int $interval, int $offset = 0): TriggerRule
    {
        return new EveryTicks($interval, $offset);
    }

    public static function dailyTick(int $index): TriggerRule
    {
        return new DailyTick($index);
    }

    public static function cron(string $expression): TriggerRule
    {
        return new CronRule($expression);
    }

    public static function automation(
        string $mode,
        ?string $time,
        string $defaultMode,
        string $defaultTime,
    ): TriggerRule {
        $resolvedMode = AutomationScheduleExpression::normalizeMode($mode, $defaultMode);

        return match ($resolvedMode) {
            AutomationScheduleExpression::MODE_EVERY_FIFTEEN_MINUTES => self::everyTicks(1),
            AutomationScheduleExpression::MODE_EVERY_THIRTY_MINUTES => self::everyTicks(2),
            default => self::cron(AutomationScheduleExpression::resolve(
                $resolvedMode,
                $time,
                $defaultMode,
                $defaultTime,
            )),
        };
    }
}
