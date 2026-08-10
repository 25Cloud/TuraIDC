<?php

namespace App\Support;

final class AutomationScheduleExpression
{
    public const MODE_EVERY_FIFTEEN_MINUTES = 'every_fifteen_minutes';

    public const MODE_EVERY_THIRTY_MINUTES = 'every_thirty_minutes';

    public const MODE_HOURLY = 'hourly';

    public const MODE_DAILY = 'daily';

    private const CRON_BY_MODE = [
        self::MODE_EVERY_FIFTEEN_MINUTES => '*/15 * * * *',
        self::MODE_EVERY_THIRTY_MINUTES => '*/30 * * * *',
    ];

    /**
     * @return list<string>
     */
    public static function modes(): array
    {
        return [
            self::MODE_EVERY_FIFTEEN_MINUTES,
            self::MODE_EVERY_THIRTY_MINUTES,
            self::MODE_HOURLY,
            self::MODE_DAILY,
        ];
    }

    public static function resolve(
        string $mode,
        ?string $time = null,
        string $defaultMode = self::MODE_HOURLY,
        string $defaultTime = '00:00:00',
    ): string {
        $resolvedMode = self::normalizeMode($mode, $defaultMode);
        $resolvedTime = self::normalizeTime($time, $defaultTime);

        if (isset(self::CRON_BY_MODE[$resolvedMode])) {
            return self::CRON_BY_MODE[$resolvedMode];
        }

        [$hour, $minute] = self::extractHourMinute($resolvedTime);

        return match ($resolvedMode) {
            self::MODE_HOURLY => sprintf('%d * * * *', $minute),
            self::MODE_DAILY => sprintf('%d %d * * *', $minute, $hour),
            default => self::CRON_BY_MODE[self::normalizeMode($defaultMode, self::MODE_HOURLY)]
                ?? '0 * * * *',
        };
    }

    public static function describe(
        string $mode,
        ?string $time = null,
        string $defaultMode = self::MODE_HOURLY,
        string $defaultTime = '00:00:00',
    ): string {
        $resolvedMode = self::normalizeMode($mode, $defaultMode);
        $resolvedTime = self::normalizeTime($time, $defaultTime);
        [$hour, $minute] = self::extractHourMinute($resolvedTime);

        return match ($resolvedMode) {
            self::MODE_EVERY_FIFTEEN_MINUTES => '每 15 分钟',
            self::MODE_EVERY_THIRTY_MINUTES => '每 30 分钟',
            self::MODE_HOURLY => sprintf('每小时第 %02d 分钟', $minute),
            self::MODE_DAILY => sprintf('每天 %02d:%02d', $hour, $minute),
            default => '自定义周期',
        };
    }

    public static function normalizeMode(string $mode, string $defaultMode = self::MODE_HOURLY): string
    {
        $resolvedMode = trim($mode);

        return in_array($resolvedMode, self::modes(), true)
            ? $resolvedMode
            : (in_array($defaultMode, self::modes(), true) ? $defaultMode : self::MODE_HOURLY);
    }

    public static function normalizeTime(?string $time, string $defaultTime = '00:00:00'): string
    {
        $resolvedTime = trim((string) $time);
        if (self::isHeartbeatAlignedTime($resolvedTime)) {
            return strlen($resolvedTime) === 5 ? $resolvedTime.':00' : $resolvedTime;
        }

        return self::isHeartbeatAlignedTime($defaultTime)
            ? (strlen($defaultTime) === 5 ? $defaultTime.':00' : $defaultTime)
            : '00:00:00';
    }

    public static function isHeartbeatAlignedTime(?string $time): bool
    {
        $value = trim((string) $time);
        if (preg_match('/^(\d{2}):(\d{2})(?::(\d{2}))?$/', $value, $matches) !== 1) {
            return false;
        }

        $hour = (int) $matches[1];
        $minute = (int) $matches[2];
        $second = isset($matches[3]) ? (int) $matches[3] : 0;

        return $hour <= 23 && $minute <= 59 && $second === 0 && $minute % 15 === 0;
    }

    /**
     * @return array{0:int,1:int}
     */
    private static function extractHourMinute(string $time): array
    {
        $parts = explode(':', self::normalizeTime($time));

        return [
            max(0, min(23, (int) ($parts[0] ?? 0))),
            max(0, min(59, (int) ($parts[1] ?? 0))),
        ];
    }
}
