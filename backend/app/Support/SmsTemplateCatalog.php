<?php

declare(strict_types=1);

namespace App\Support;

final class SmsTemplateCatalog
{
    public const TEMPLATE_VERIFY_CODE = '100001';

    public const TEMPLATE_LOGIN_ALERT = '100002';

    public const TEMPLATE_SERVICE_RENEW_REMINDER = '100003';

    public const TEMPLATE_INVOICE_PAYMENT_REMINDER = '100004';

    public const TEMPLATE_INVOICE_OVERDUE_REMINDER = '100005';

    public const TEMPLATE_SERVICE_SUSPENDED = '100006';

    public const TEMPLATE_SERVICE_RESTORED = '100007';

    public const TEMPLATE_INVOICE_NOTICE = '100008';

    public const TEMPLATE_MANUAL_PAYMENT_CONFIRM = '100009';

    public const TEMPLATE_TICKET_CREATED = '100010';

    public const TEMPLATE_TICKET_CLIENT_REPLY = '100012';

    public const TEMPLATE_TICKET_STAFF_REPLY = '100012';

    public const TEMPLATE_ADMIN_ORDER_CREATED = '100013';

    public const TEMPLATE_ADMIN_ORDER_PAID = '100014';

    public const TEMPLATE_LOGIN_FAILURE_ALERT = '100002';

    public const TEMPLATE_LOGIN_LOCATION_ALERT = '100002';

    public const TEMPLATE_PASSWORD_CHANGED_ALERT = '100033';

    public const TEMPLATE_PHONE_CHANGED_ALERT = '100033';

    public const TEMPLATE_EMAIL_CHANGED_ALERT = '100033';

    public const TEMPLATE_AUTO_RENEW_UPCOMING = '100020';

    public const TEMPLATE_ORDER_REFUND = '100021';

    public const TEMPLATE_CLIENT_ORDER_CREATED = '100013';

    public const TEMPLATE_SERVICE_ACTIVATED = '100025';

    public const TEMPLATE_SERVICE_TERMINATED = '100026';

    public const TEMPLATE_TICKET_AUTO_CLOSED = '100028';

    public const TEMPLATE_ACCOUNT_BOUND = '100029';

    public const TEMPLATE_REGISTRATION_SUCCESS = '100030';

    public const TEMPLATE_SERVICE_UNCERTIFIED_SUSPENDED = '100027';

    public const TEMPLATE_CREDIT_INVOICE_NOTICE = '100008';

    public const TEMPLATE_CREDIT_INVOICE_OVERDUE = '100005';

    public const TEMPLATE_CREDIT_INVOICE_SUSPENDED = '100006';

    public const TEMPLATE_SERVICE_UNSUSPENDED = '100007';

    public const TEMPLATE_REALNAME_APPROVED = '100032';

    public const TEMPLATE_ACCOUNT_BINDING_ALERT = '100033';

    public static function contentSettingKey(string $code): string
    {
        return 'sms_template_content_'.trim($code);
    }

    public static function providerTemplateIdSettingKey(string $code): string
    {
        return 'sms_template_provider_template_id_'.trim($code);
    }

    public static function enabledSettingKey(string $code): string
    {
        return 'sms_template_enabled_'.trim($code);
    }
}
