<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat;

use App\Services\Automation\Heartbeat\Data\TickContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class TickSlot
{
    public const SECONDS = 900;

    public static function floorToFifteenMinutes(CarbonInterface $time): CarbonImmutable
    {
        $timezone = (string) config('app.timezone', date_default_timezone_get());
        $local = CarbonImmutable::instance($time)->setTimezone($timezone);
        $minute = intdiv((int) $local->minute, 15) * 15;

        return $local->setTime((int) $local->hour, $minute, 0);
    }

    public static function globalNumber(CarbonInterface $slot): int
    {
        return intdiv($slot->getTimestamp(), self::SECONDS) + 1;
    }

    public static function dailyIndex(CarbonInterface $slot): int
    {
        return ((int) $slot->hour * 4) + intdiv((int) $slot->minute, 15) + 1;
    }

    public static function context(?int $id, CarbonInterface $slot): TickContext
    {
        $resolvedSlot = self::floorToFifteenMinutes($slot);

        return new TickContext(
            id: $id,
            slotStartedAt: $resolvedSlot,
            globalNumber: self::globalNumber($resolvedSlot),
            dailyIndex: self::dailyIndex($resolvedSlot),
        );
    }
}
