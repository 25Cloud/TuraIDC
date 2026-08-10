<?php

namespace App\Constants;

class OrderType
{
    public const NEW = 'new';

    public const RENEW = 'renew';

    public const UPGRADE = 'upgrade';

    public static array $labels = [
        self::NEW => '新购',
        self::RENEW => '续费',
        self::UPGRADE => '附加配置',
    ];

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_keys(self::$labels);
    }

    public static function label(string $type): string
    {
        return self::$labels[$type] ?? $type;
    }
}
