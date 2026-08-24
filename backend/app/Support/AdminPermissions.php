<?php

namespace App\Support;

class AdminPermissions
{
    public const ALL = '*';

    public const DASHBOARD_VIEW = 'dashboard.view';

    public const USER_LIST = 'user.list';

    public const USER_DETAIL = 'user.detail';

    public const USER_MANAGE = 'user.manage';

    public const USER_LOGIN_AS = 'user.login_as';

    public const USER_RECHARGE = 'user.recharge';

    public const VERIFICATION_LIST = 'verification.list';

    public const VERIFICATION_UNBIND = 'verification.unbind';

    public const ORDER_LIST = 'order.list';

    public const ORDER_DETAIL = 'order.detail';

    public const ORDER_MANAGE = 'order.manage';

    public const INVOICE_LIST = 'invoice.list';

    public const INVOICE_DETAIL = 'invoice.detail';

    public const INVOICE_MANAGE = 'invoice.manage';

    public const TICKET_LIST = 'ticket.list';

    public const TICKET_REPLY = 'ticket.reply';

    public const TICKET_MANAGE = 'ticket.manage';

    public const PRODUCT_LIST = 'product.list';

    public const PRODUCT_MANAGE = 'product.manage';

    public const PRODUCT_SYNC = 'product.sync';

    public const SUPPLIER_LIST = 'supplier.list';

    public const SUPPLIER_DETAIL = 'supplier.detail';

    public const SUPPLIER_MANAGE = 'supplier.manage';

    public const SUPPLIER_SYNC = 'supplier.sync';

    public const SUPPLIER_SECRET_REVEAL = 'supplier.secret_reveal';

    public const SETTINGS_VIEW = 'settings.view';

    public const SETTINGS_MANAGE = 'settings.manage';

    public const SETTINGS_SECRET_REVEAL = 'settings.secret_reveal';

    public const DATABASE_VIEW = 'database.view';

    public const DATABASE_MANAGE = 'database.manage';

    public const INTEGRATION_PLUGIN_VIEW = 'integration_plugin.view';

    public const INTEGRATION_PLUGIN_MANAGE = 'integration_plugin.manage';

    public const INTEGRATION_PLUGIN_TEST = 'integration_plugin.test';

    public const INTEGRATION_PLUGIN_SECRET_REVEAL = 'integration_plugin.secret_reveal';

    public const SCHEDULE_VIEW = 'schedule.view';

    public const SCHEDULE_TRIGGER = 'schedule.trigger';

    public const SCHEDULE_RETRY = 'schedule.retry';

    public const SITE_VIEW = 'site.view';

    public const SITE_MANAGE = 'site.manage';

    public const LOG_LIST = 'log.list';

    public const LOG_MANAGE = 'log.manage';

    public const REFERRAL_LIST = 'referral.list';

    public const FINANCE_REPORT = 'finance.report';

    public const FINANCE_WITHDRAW = 'finance.withdraw';

    public const MEMBER_LEVEL_MANAGE = 'member_level.manage';

    public const MEMBER_LEVEL_LIST = 'member_level.list';

    public const AGENT_DISCOUNT_MANAGE = 'agent_discount.manage';

    public const AGENT_DISCOUNT_LIST = 'agent_discount.list';

    public const REFERRAL_WITHDRAWAL_LIST = 'referral_withdrawal.list';

    public const CONTENT_LIST = 'content.list';

    public const CONTENT_MANAGE = 'content.manage';

    public const STAFF_LIST = 'staff.list';

    public const STAFF_MANAGE = 'staff.manage';

    public const ROLE_LIST = 'role.list';

    public const ROLE_MANAGE = 'role.manage';

    public const PERMISSION_LIST = 'permission.list';

    public const PRIVACY_VIEW_RAW = 'privacy.view_raw';

    /**
     * @return array<string, string[]>
     */
    public static function builtInRolePermissions(): array
    {
        return [
            'super_admin' => [self::ALL],
            'admin' => self::adminDefaultPermissions(),
            'visitor' => self::visitorPermissions(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function builtInRoleLabels(): array
    {
        return [
            'super_admin' => '超级管理员',
            'admin' => '普通管理员',
            'visitor' => '访客',
        ];
    }

    /**
     * @return string[]
     */
    public static function builtInRoleNames(): array
    {
        return array_keys(self::builtInRoleLabels());
    }

    public static function isBuiltInRoleName(?string $roleName): bool
    {
        return in_array(trim((string) $roleName), self::builtInRoleNames(), true);
    }

    /**
     * @return string[]
     */
    public static function visitorPermissions(): array
    {
        return [
            self::DASHBOARD_VIEW,
            self::USER_LIST,
            self::USER_DETAIL,
            self::VERIFICATION_LIST,
            self::ORDER_LIST,
            self::ORDER_DETAIL,
            self::INVOICE_LIST,
            self::INVOICE_DETAIL,
            self::TICKET_LIST,
            self::PRODUCT_LIST,
            self::SUPPLIER_LIST,
            self::SUPPLIER_DETAIL,
            self::SETTINGS_VIEW,
            self::INTEGRATION_PLUGIN_VIEW,
            self::SCHEDULE_VIEW,
            self::SITE_VIEW,
            self::LOG_LIST,
            self::REFERRAL_LIST,
            self::REFERRAL_WITHDRAWAL_LIST,
            self::FINANCE_REPORT,
            self::MEMBER_LEVEL_LIST,
            self::CONTENT_LIST,
            self::STAFF_LIST,
            self::ROLE_LIST,
            self::PERMISSION_LIST,
        ];
    }

    /**
     * @return string[]
     */
    public static function adminDefaultPermissions(): array
    {
        return array_values(array_unique(array_merge(self::visitorPermissions(), [
            self::USER_MANAGE,
            self::VERIFICATION_UNBIND,
            self::ORDER_MANAGE,
            self::INVOICE_MANAGE,
            self::TICKET_REPLY,
            self::TICKET_MANAGE,
            self::PRODUCT_MANAGE,
            self::PRODUCT_SYNC,
            self::SUPPLIER_MANAGE,
            self::SUPPLIER_SYNC,
            self::SITE_MANAGE,
            self::REFERRAL_LIST,
            self::MEMBER_LEVEL_MANAGE,
            self::AGENT_DISCOUNT_MANAGE,
            self::CONTENT_MANAGE,
        ])));
    }

    /**
     * @return string[]
     */
    public static function resolveRolePermissions(?string $roleName, array $storedPermissions = []): array
    {
        $builtInPermissions = self::builtInRolePermissions()[trim((string) $roleName)] ?? null;
        $permissions = $builtInPermissions !== null
            ? $builtInPermissions
            : self::normalize($storedPermissions);

        if (in_array(self::ALL, $permissions, true)) {
            return [self::ALL];
        }

        $expanded = [];
        foreach ($permissions as $permission) {
            $expanded[$permission] = true;

            foreach (self::impliedPermissions($permission) as $implied) {
                $expanded[$implied] = true;
            }
        }

        return array_keys($expanded);
    }

    /**
     * @return string[]
     */
    public static function impliedPermissions(string $permission): array
    {
        return match ($permission) {
            self::USER_MANAGE => [self::USER_LIST, self::USER_DETAIL],
            self::ORDER_MANAGE => [self::ORDER_LIST, self::ORDER_DETAIL],
            self::INVOICE_MANAGE => [self::INVOICE_LIST, self::INVOICE_DETAIL],
            self::TICKET_MANAGE => [self::TICKET_LIST, self::TICKET_REPLY],
            self::PRODUCT_MANAGE => [self::PRODUCT_LIST],
            self::PRODUCT_SYNC => [self::PRODUCT_LIST],
            self::SUPPLIER_DETAIL => [self::SUPPLIER_LIST],
            self::SUPPLIER_MANAGE => [self::SUPPLIER_LIST, self::SUPPLIER_DETAIL],
            self::SUPPLIER_SYNC => [self::SUPPLIER_LIST, self::SUPPLIER_DETAIL],
            self::SETTINGS_MANAGE => [self::SETTINGS_VIEW],
            self::DATABASE_MANAGE => [self::DATABASE_VIEW],
            self::INTEGRATION_PLUGIN_MANAGE => [self::INTEGRATION_PLUGIN_VIEW],
            self::INTEGRATION_PLUGIN_TEST => [self::INTEGRATION_PLUGIN_VIEW],
            self::SCHEDULE_TRIGGER => [self::SCHEDULE_VIEW],
            self::SCHEDULE_RETRY => [self::SCHEDULE_VIEW],
            self::SITE_MANAGE => [self::SITE_VIEW],
            self::LOG_MANAGE => [self::LOG_LIST],
            self::MEMBER_LEVEL_MANAGE => [self::MEMBER_LEVEL_LIST],
            self::AGENT_DISCOUNT_MANAGE => [self::AGENT_DISCOUNT_LIST],
            self::CONTENT_MANAGE => [self::CONTENT_LIST],
            self::STAFF_MANAGE => [self::STAFF_LIST],
            self::ROLE_MANAGE => [self::ROLE_LIST, self::PERMISSION_LIST],
            default => [],
        };
    }

    /**
     * @return string[]
     */
    private static function normalize(array $permissions): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $permission) => is_string($permission) ? trim($permission) : '',
            $permissions
        )));
    }
}
