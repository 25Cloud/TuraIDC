<?php

namespace App\Constants;

class UserCouponStatus
{
    public const OWNED = 1;

    public const USED = 2;

    public const REVOKED = 3;

    public static array $labels = [
        self::OWNED => '可使用',
        self::USED => '已使用',
        self::REVOKED => '已作废',
    ];
}
