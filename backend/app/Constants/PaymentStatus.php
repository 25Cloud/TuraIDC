<?php

namespace App\Constants;

class PaymentStatus
{
    const PENDING = 0; // 待支付

    const SUCCESS = 1; // 成功

    const FAILED = 2; // 失败

    const REFUNDED = 3; // 已退款

    const CANCELLED = 4; // 已取消

    public static array $labels = [
        self::PENDING => '待支付',
        self::SUCCESS => '成功',
        self::FAILED => '失败',
        self::REFUNDED => '已退款',
        self::CANCELLED => '已取消',
    ];
}
