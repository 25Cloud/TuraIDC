<?php

namespace App\Constants;

class ServiceStatus
{
    const PENDING = 0; // 开通中

    const ACTIVE = 1; // 已开通

    const SUSPENDED = 2; // 已暂停

    const EXPIRED = 3; // 已到期

    const CANCELLED = 4; // 已取消

    public static array $labels = [
        self::PENDING => '开通中',
        self::ACTIVE => '已开通',
        self::SUSPENDED => '已暂停',
        self::EXPIRED => '已到期',
        self::CANCELLED => '已取消',
    ];
}
