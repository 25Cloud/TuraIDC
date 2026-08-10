<?php

namespace App\Constants;

class InvoiceStatus
{
    const UNPAID = 0; // 未付

    const PAID = 1; // 已付

    const CANCELLED = 2; // 已取消

    const OVERDUE = 3; // 逾期

    const REFUNDED = 5; // 已退款

    const PARTIALLY_REFUNDED = 6; // 部分退款

    public static array $labels = [
        self::UNPAID => '未付',
        self::PAID => '已付',
        self::CANCELLED => '已取消',
        self::OVERDUE => '逾期',
        self::REFUNDED => '已退款',
        self::PARTIALLY_REFUNDED => '部分退款',
    ];
}
