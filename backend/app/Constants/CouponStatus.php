<?php

namespace App\Constants;

class CouponStatus
{
    public const DISABLED = 0;

    public const ACTIVE = 1;

    public static array $labels = [
        self::DISABLED => '已停用',
        self::ACTIVE => '生效中',
    ];
}
