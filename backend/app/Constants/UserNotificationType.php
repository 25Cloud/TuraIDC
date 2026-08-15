<?php

namespace App\Constants;

/**
 * 站内信（个性化通知）类型常量
 *
 * 公告类（notice / notice_update）不在此表存储，由 content_articles + notice_reads 聚合。
 */
class UserNotificationType
{
    /** 订购/开通成功 */
    public const ORDER_PAID = 'order_paid';

    /** 服务续费提醒（到期前） */
    public const SERVICE_RENEW_REMINDER = 'service_renew_reminder';

    /** 即将自动续费提醒 */
    public const SERVICE_AUTO_RENEW_UPCOMING = 'service_auto_renew_upcoming';

    /** 服务到期/暂停提醒 */
    public const SERVICE_EXPIRE_REMINDER = 'service_expire_reminder';

    /** 账单待支付提醒 */
    public const INVOICE_PAYMENT_REMINDER = 'invoice_payment_reminder';

    /** 账单逾期提醒 */
    public const INVOICE_OVERDUE_REMINDER = 'invoice_overdue_reminder';

    /** 工单收到客服回复 */
    public const TICKET_STAFF_REPLY = 'ticket_staff_reply';

    /** 工单待客户处理（超时未跟进，即将自动关闭） */
    public const TICKET_PENDING_REMINDER = 'ticket_pending_reminder';

    /** 工单已自动关闭 */
    public const TICKET_AUTO_CLOSED = 'ticket_auto_closed';

    /** 工单已重新开启 */
    public const TICKET_REOPENED = 'ticket_reopened';

    /**
     * 类型对应的中文标签，用于前端展示分组/图标。
     */
    public const LABELS = [
        self::ORDER_PAID => '订购提醒',
        self::SERVICE_RENEW_REMINDER => '续费提醒',
        self::SERVICE_AUTO_RENEW_UPCOMING => '自动续费提醒',
        self::SERVICE_EXPIRE_REMINDER => '到期提醒',
        self::INVOICE_PAYMENT_REMINDER => '账单提醒',
        self::INVOICE_OVERDUE_REMINDER => '逾期提醒',
        self::TICKET_STAFF_REPLY => '工单回复',
        self::TICKET_PENDING_REMINDER => '工单提醒',
        self::TICKET_AUTO_CLOSED => '工单自动关闭',
        self::TICKET_REOPENED => '工单重新开启',
    ];

    public static function label(string $type): string
    {
        return self::LABELS[$type] ?? '系统通知';
    }
}
