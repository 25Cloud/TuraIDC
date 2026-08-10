<?php

namespace App\Constants;

class OrderStatus
{
    const PENDING = 0; // 待付款

    const PAID = 1; // 已付款

    const PROCESSING = 2; // 开通中

    const COMPLETED = 3; // 已完成

    const CANCELLED = 4; // 已取消

    const REFUNDED = 5; // 已退款

    public static array $labels = [
        self::PENDING => '待付款',
        self::PAID => '已付款',
        self::PROCESSING => '开通中',
        self::COMPLETED => '已完成',
        self::CANCELLED => '已取消',
        self::REFUNDED => '已退款',
    ];
}
