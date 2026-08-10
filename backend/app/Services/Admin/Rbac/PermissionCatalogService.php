<?php

declare(strict_types=1);

namespace App\Services\Admin\Rbac;

use App\Support\AdminPermissions;
use ReflectionClass;

class PermissionCatalogService
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $constants = (new ReflectionClass(AdminPermissions::class))->getConstants();
        $items = [];

        foreach ($constants as $permission) {
            if (! is_string($permission) || trim($permission) === '') {
                continue;
            }

            $group = $this->detectGroup($permission);
            $riskLevel = $this->riskLevel($permission);

            $items[] = [
                'key' => $permission,
                'module' => $this->detectModule($permission),
                'module_label' => $this->moduleLabel($permission),
                'group' => $group,
                'group_label' => $this->groupLabel($group),
                'name' => $this->label($permission),
                'description' => $this->description($permission),
                'action' => $this->detectAction($permission),
                'action_label' => $this->actionLabel($permission),
                'risk_level' => $riskLevel,
                'is_dangerous' => $riskLevel === 'high',
                'is_all' => $permission === AdminPermissions::ALL,
                'sort' => $this->permissionOrder($permission),
            ];
        }

        usort($items, fn (array $a, array $b) => [
            $this->groupOrder((string) $a['group']),
            (int) $a['sort'],
            $a['key'],
        ] <=> [
            $this->groupOrder((string) $b['group']),
            (int) $b['sort'],
            $b['key'],
        ]);

        return $items;
    }

    public function validKeys(): array
    {
        return array_column($this->list(), 'key');
    }

    private function detectModule(string $permission): string
    {
        if ($permission === AdminPermissions::ALL) {
            return 'system';
        }

        $module = explode('.', $permission)[0] ?? 'system';

        return $module !== '' ? $module : 'system';
    }

    private function detectAction(string $permission): string
    {
        if ($permission === AdminPermissions::ALL) {
            return 'all';
        }

        $segments = explode('.', $permission);

        return $segments[count($segments) - 1] ?? 'view';
    }

    private function detectGroup(string $permission): string
    {
        return match ($permission) {
            AdminPermissions::ALL => 'system_root',
            AdminPermissions::STAFF_LIST, AdminPermissions::STAFF_MANAGE => 'organization_staff',
            AdminPermissions::ROLE_LIST, AdminPermissions::ROLE_MANAGE => 'organization_role',
            AdminPermissions::PERMISSION_LIST => 'organization_permission',
            AdminPermissions::DASHBOARD_VIEW => 'dashboard_workbench',
            AdminPermissions::USER_LIST, AdminPermissions::USER_DETAIL, AdminPermissions::USER_MANAGE, AdminPermissions::USER_LOGIN_AS, AdminPermissions::PRIVACY_VIEW_RAW => 'customer_profile',
            AdminPermissions::VERIFICATION_LIST, AdminPermissions::VERIFICATION_UNBIND => 'customer_verification',
            AdminPermissions::ORDER_LIST, AdminPermissions::ORDER_DETAIL, AdminPermissions::ORDER_MANAGE => 'finance_order',
            AdminPermissions::INVOICE_LIST, AdminPermissions::INVOICE_DETAIL, AdminPermissions::INVOICE_MANAGE => 'finance_invoice',
            AdminPermissions::USER_RECHARGE, AdminPermissions::FINANCE_WITHDRAW => 'finance_funds',
            AdminPermissions::FINANCE_REPORT => 'finance_report',
            AdminPermissions::TICKET_LIST, AdminPermissions::TICKET_REPLY, AdminPermissions::TICKET_MANAGE => 'support_ticket',
            AdminPermissions::PRODUCT_LIST, AdminPermissions::PRODUCT_MANAGE, AdminPermissions::PRODUCT_SYNC => 'product_catalog',
            AdminPermissions::SUPPLIER_LIST, AdminPermissions::SUPPLIER_DETAIL, AdminPermissions::SUPPLIER_MANAGE, AdminPermissions::SUPPLIER_SYNC, AdminPermissions::SUPPLIER_SECRET_REVEAL => 'product_supplier',
            AdminPermissions::CONTENT_LIST, AdminPermissions::CONTENT_MANAGE => 'content_ops',
            AdminPermissions::REFERRAL_LIST, AdminPermissions::REFERRAL_WITHDRAWAL_LIST, AdminPermissions::MEMBER_LEVEL_LIST, AdminPermissions::MEMBER_LEVEL_MANAGE => 'marketing_growth',
            AdminPermissions::SETTINGS_VIEW, AdminPermissions::SETTINGS_MANAGE, AdminPermissions::SETTINGS_SECRET_REVEAL => 'system_config',
            AdminPermissions::DATABASE_VIEW, AdminPermissions::DATABASE_MANAGE => 'system_database',
            AdminPermissions::INTEGRATION_PLUGIN_VIEW, AdminPermissions::INTEGRATION_PLUGIN_MANAGE, AdminPermissions::INTEGRATION_PLUGIN_TEST, AdminPermissions::INTEGRATION_PLUGIN_SECRET_REVEAL => 'system_integration',
            AdminPermissions::SCHEDULE_VIEW, AdminPermissions::SCHEDULE_TRIGGER => 'system_schedule',
            AdminPermissions::SITE_VIEW, AdminPermissions::SITE_MANAGE => 'site_ops',
            AdminPermissions::LOG_LIST, AdminPermissions::LOG_MANAGE => 'system_audit',
            default => $this->detectModule($permission),
        };
    }

    private function moduleLabel(string $permission): string
    {
        $module = $this->detectModule($permission);

        return [
            'dashboard' => '工作台',
            'user' => '用户',
            'verification' => '实名',
            'order' => '订单',
            'invoice' => '账单',
            'ticket' => '工单',
            'product' => '商品',
            'supplier' => '供应商',
            'settings' => '设置',
            'database' => '数据库',
            'integration_plugin' => '插件',
            'schedule' => '自动化',
            'site' => '站点',
            'log' => '日志',
            'referral' => '推广',
            'referral_withdrawal' => '推荐提现',
            'finance' => '财务',
            'member_level' => '会员',
            'content' => '内容',
            'staff' => '员工',
            'role' => '角色',
            'permission' => '权限',
            'privacy' => '隐私',
            'system' => '系统',
        ][$module] ?? $module;
    }

    private function groupLabel(string $group): string
    {
        return [
            'system_root' => '超级权限',
            'organization_staff' => '员工账号',
            'organization_role' => '角色授权',
            'organization_permission' => '权限目录',
            'dashboard_workbench' => '工作台',
            'customer_profile' => '客户资料',
            'customer_verification' => '实名审核',
            'finance_order' => '订单管理',
            'finance_invoice' => '账单管理',
            'finance_funds' => '资金操作',
            'finance_report' => '财务报表',
            'support_ticket' => '工单支持',
            'product_catalog' => '商品配置',
            'product_supplier' => '供应商接口',
            'content_ops' => '内容运营',
            'marketing_growth' => '推广会员',
            'system_database' => '数据库运维',
            'system_config' => '系统设置',
            'system_integration' => '集成插件',
            'system_schedule' => '自动化任务',
            'site_ops' => '站点展示',
            'system_audit' => '日志审计',
        ][$group] ?? $group;
    }

    private function actionLabel(string $permission): string
    {
        if ($permission === AdminPermissions::ALL) {
            return '全部';
        }

        return [
            'view' => '查看',
            'list' => '列表',
            'detail' => '详情',
            'manage' => '管理',
            'recharge' => '充值',
            'unbind' => '驳回/解绑',
            'reply' => '回复',
            'conversations' => '对话列表',
            'settings' => '配置',
            'delete' => '删除',
            'report' => '报表',
            'withdraw' => '提现',
            'sync' => '同步',
            'trigger' => '触发',
            'test' => '测试',
            'secret_reveal' => '查看密钥',
            'view_raw' => '查看原始隐私',
        ][$this->detectAction($permission)] ?? $this->detectAction($permission);
    }

    private function riskLevel(string $permission): string
    {
        if ($permission === AdminPermissions::ALL) {
            return 'high';
        }

        return match ($permission) {
            AdminPermissions::ROLE_MANAGE,
            AdminPermissions::STAFF_MANAGE,
            AdminPermissions::SETTINGS_MANAGE,
            AdminPermissions::DATABASE_MANAGE,
            AdminPermissions::SETTINGS_SECRET_REVEAL,
            AdminPermissions::SUPPLIER_SECRET_REVEAL,
            AdminPermissions::INTEGRATION_PLUGIN_SECRET_REVEAL,
            AdminPermissions::PRIVACY_VIEW_RAW,
            AdminPermissions::FINANCE_WITHDRAW,
            AdminPermissions::USER_RECHARGE,
            AdminPermissions::VERIFICATION_UNBIND,
            AdminPermissions::USER_LOGIN_AS => 'high',
            default => (
                str_ends_with($permission, '.manage')
                || str_ends_with($permission, '.reply')
                || str_ends_with($permission, '.sync')
                || str_ends_with($permission, '.trigger')
                || str_ends_with($permission, '.test')
            ) ? 'medium' : 'low',
        };
    }

    private function groupOrder(string $group): int
    {
        $order = [
            'system_root',
            'organization_staff', 'organization_role', 'organization_permission',
            'dashboard_workbench',
            'customer_profile', 'customer_verification',
            'finance_invoice', 'finance_order', 'finance_funds', 'finance_report',
            'support_ticket', 'product_catalog', 'product_supplier', 'content_ops', 'marketing_growth',
            'site_ops', 'system_config', 'system_database', 'system_integration', 'system_schedule', 'system_audit',
        ];

        $index = array_search($group, $order, true);

        return $index === false ? 999 : (int) $index;
    }

    private function permissionOrder(string $permission): int
    {
        if ($permission === AdminPermissions::ALL) {
            return 0;
        }

        return [
            'view' => 10,
            'list' => 20,
            'detail' => 30,
            'reply' => 40,
            'recharge' => 50,
            'unbind' => 60,
            'report' => 70,
            'withdraw' => 80,
            'sync' => 82,
            'trigger' => 84,
            'test' => 86,
            'secret_reveal' => 88,
            'view_raw' => 89,
            'manage' => 90,
        ][$this->detectAction($permission)] ?? 99;
    }

    private function label(string $permission): string
    {
        return [
            AdminPermissions::ALL => '全部权限',
            AdminPermissions::DASHBOARD_VIEW => '查看仪表盘',
            AdminPermissions::USER_LIST => '查看用户列表',
            AdminPermissions::USER_DETAIL => '查看用户详情',
            AdminPermissions::USER_MANAGE => '管理用户',
            AdminPermissions::USER_LOGIN_AS => '代登录用户',
            AdminPermissions::USER_RECHARGE => '用户充值',
            AdminPermissions::VERIFICATION_LIST => '查看实名认证',
            AdminPermissions::VERIFICATION_UNBIND => '驳回解绑实名',
            AdminPermissions::ORDER_LIST => '查看订单',
            AdminPermissions::ORDER_DETAIL => '查看订单详情',
            AdminPermissions::ORDER_MANAGE => '管理订单',
            AdminPermissions::INVOICE_LIST => '查看账单',
            AdminPermissions::INVOICE_DETAIL => '查看账单详情',
            AdminPermissions::INVOICE_MANAGE => '管理账单',
            AdminPermissions::TICKET_LIST => '查看工单',
            AdminPermissions::TICKET_REPLY => '回复工单',
            AdminPermissions::TICKET_MANAGE => '管理工单',
            AdminPermissions::PRODUCT_LIST => '查看商品',
            AdminPermissions::PRODUCT_MANAGE => '管理商品',
            AdminPermissions::PRODUCT_SYNC => '同步商品',
            AdminPermissions::SUPPLIER_LIST => '查看供应商列表',
            AdminPermissions::SUPPLIER_DETAIL => '查看供应商详情',
            AdminPermissions::SUPPLIER_MANAGE => '管理供应商',
            AdminPermissions::SUPPLIER_SYNC => '同步供应商数据',
            AdminPermissions::SUPPLIER_SECRET_REVEAL => '查看供应商密钥',
            AdminPermissions::SETTINGS_VIEW => '查看系统设置',
            AdminPermissions::SETTINGS_MANAGE => '管理系统设置',
            AdminPermissions::DATABASE_VIEW => '查看数据库状态',
            AdminPermissions::DATABASE_MANAGE => '管理数据库运维',
            AdminPermissions::SETTINGS_SECRET_REVEAL => '查看系统密钥',
            AdminPermissions::INTEGRATION_PLUGIN_VIEW => '查看集成插件',
            AdminPermissions::INTEGRATION_PLUGIN_MANAGE => '管理集成插件',
            AdminPermissions::INTEGRATION_PLUGIN_TEST => '测试集成插件',
            AdminPermissions::INTEGRATION_PLUGIN_SECRET_REVEAL => '查看插件密钥',
            AdminPermissions::SCHEDULE_VIEW => '查看自动化任务',
            AdminPermissions::SCHEDULE_TRIGGER => '触发自动化任务',
            AdminPermissions::SITE_VIEW => '查看站点展示',
            AdminPermissions::SITE_MANAGE => '管理站点展示',
            AdminPermissions::LOG_LIST => '查看日志',
            AdminPermissions::LOG_MANAGE => '管理日志',
            AdminPermissions::REFERRAL_LIST => '查看推广返利',
            AdminPermissions::FINANCE_REPORT => '查看财务报表',
            AdminPermissions::FINANCE_WITHDRAW => '处理提现',
            AdminPermissions::REFERRAL_WITHDRAWAL_LIST => '查看推荐提现',
            AdminPermissions::MEMBER_LEVEL_LIST => '查看会员等级',
            AdminPermissions::MEMBER_LEVEL_MANAGE => '管理会员等级',
            AdminPermissions::CONTENT_LIST => '查看内容',
            AdminPermissions::CONTENT_MANAGE => '管理内容',
            AdminPermissions::STAFF_LIST => '查看员工',
            AdminPermissions::STAFF_MANAGE => '管理员工',
            AdminPermissions::ROLE_LIST => '查看角色',
            AdminPermissions::ROLE_MANAGE => '管理角色',
            AdminPermissions::PERMISSION_LIST => '查看权限目录',
            AdminPermissions::PRIVACY_VIEW_RAW => '查看原始隐私信息',
        ][$permission] ?? str_replace('.', ' / ', $permission);
    }

    private function description(string $permission): string
    {
        if ($permission === AdminPermissions::ALL) {
            return '拥有全部后台权限';
        }

        return $this->label($permission);
    }
}
