<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Setting;
use App\Support\EmailNotificationTemplateDefaults;
use App\Support\SmsNotificationTemplateDefaults;
use App\Support\SmsTemplateCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SettingsSeeder
{
    /**
     * 系统核心默认配置。
     * 敏感字段（支付密钥、邮箱密码等）写入占位值，部署后通过管理后台更新。
     */
    public static function seed(): void
    {
        static::seedGroup('system', [
            'provision_hostname_enforce' => '0',
            'provision_hostname_prefix' => 'srv',
            'provision_hostname_charsets' => 'number',
            'provision_hostname_length' => '12',
        ]);

        static::seedGroup('basic', [
            'site_name' => '图拉云',
            'browser_title' => '图拉云 - 云计算服务平台',
            'site_logo' => '',
            'site_favicon' => '',
            'client_console_icon' => '',
            'service_phone' => '',
            'service_email' => '',
            'service_hours' => '',
            'icp_number' => '',
            'icp_license_number' => '',
            'terms_url' => '',
            'privacy_url' => '',
        ]);

        $notificationDefaults = [
            'email_enabled' => '0',
            'sms_enabled' => '0',
            'sms_template_code' => SmsTemplateCatalog::TEMPLATE_VERIFY_CODE,
        ];

        static::seedGroup('notification', $notificationDefaults);

        static::seedGroup('automation', [
            'expire_suspend_enabled' => '1',
            'expire_suspend_after_days' => '1',
            'expire_suspend_notify_enabled' => '1',
            'expire_unsuspend_notify_enabled' => '1',
            'expire_terminate_enabled' => '0',
            'expire_terminate_after_days' => '7',
            'renew_notice_enabled' => '1',
            'renew_notice_days_before' => '7',
            'renew_create_invoice_enabled' => '0',
            'invoice_unpaid_reminder_enabled' => '1',
            'invoice_unpaid_before_due_days' => '3',
            'invoice_overdue_reminder_days' => '3',
            'invoice_overdue_after_days' => '7',
            'ticket_auto_close_enabled' => '1',
            'ticket_auto_close_after_hours' => '72',
            'ticket_auto_close_schedule_mode' => 'daily',
            'ticket_auto_close_schedule_time' => '03:00',
            'pending_order_cleanup_enabled' => '1',
            'pending_order_cleanup_after_days' => '3',
            'pending_order_cleanup_after_hours' => '0',
            'pending_recharge_cleanup_enabled' => '1',
            'pending_recharge_cleanup_after_days' => '3',
            'service_lifecycle_schedule_mode' => 'daily',
            'service_lifecycle_schedule_time' => '02:00',
            'billing_maintenance_schedule_mode' => 'daily',
            'billing_maintenance_schedule_time' => '02:30',
            'order_cleanup_schedule_mode' => 'daily',
            'order_cleanup_schedule_time' => '03:30',
        ]);

        static::seedGroup('product', [
            'product_types' => '',
            'product_auto_publish' => '1',
            'product_sort_mode' => 'manual',
            'show_out_of_stock' => '1',
            'enable_stock_warning' => '0',
            'stock_warning_value' => '10',
            'default_billing_cycle' => 'monthly',
            'order_auto_cancel_minutes' => '30',
            'instance_spec_catalog' => '',
            'cpu_model_catalog' => '',
        ]);

        static::seedGroup('referral', [
            'enabled' => '0',
            'reward_freeze_days' => '30',
            'withdraw_min_amount' => '50.00',
        ]);

        static::seedGroup('traffic_package', [
            'traffic_package_enabled' => '0',
            'traffic_package_display_threshold_percent' => '80',
            'traffic_package_button_text' => '购买流量包',
            'traffic_package_option_field' => '',
            'traffic_package_option_keyword' => '',
            'traffic_package_allow_choice_mode' => '0',
            'traffic_package_allow_quantity_mode' => '0',
        ]);

        static::seedGroup('traffic_package_catalog', [
            'groups' => '[]',
            'items' => '[]',
        ]);

        static::seedGroup('home_hero', [
            'slides' => '[]',
            'features' => '[]',
        ]);

        static::seedGroup('content', [
            'published_cache_version' => '1',
        ]);

        // 通知模板默认数据（email 全量 + sms 验证码）：幂等种入，
        // 修复 schema baseline 只含表结构不含模板数据的部署缺口。
        static::seedNotificationTemplates();
    }

    /**
     * 幂等种入通知模板默认数据：按 channel+code 跳过已存在的模板，
     * 不会覆盖管理员在后台的个性化内容。
     */
    private static function seedNotificationTemplates(): void
    {
        if (! Schema::hasTable('notification_templates')) {
            return;
        }

        $definitions = [
            'email' => EmailNotificationTemplateDefaults::templates(),
            'sms' => SmsNotificationTemplateDefaults::templates(),
        ];

        foreach ($definitions as $channel => $templates) {
            foreach ($templates as $definition) {
                $code = trim((string) ($definition['code'] ?? ''));
                if ($code === '') {
                    continue;
                }

                // insertOrIgnore 依赖 (channel, code) 唯一约束原子跳过已存在模板，
                // 避免 exists() + insert() 之间的并发窗口造成重复键异常；不覆盖后台个性化内容。
                DB::table('notification_templates')->insertOrIgnore([
                    'channel' => $channel,
                    'code' => $code,
                    'name' => (string) ($definition['name'] ?? $code),
                    'description' => (string) ($definition['description'] ?? ''),
                    'audience' => (string) ($definition['audience'] ?? 'user'),
                    'subject' => isset($definition['subject']) && trim((string) $definition['subject']) !== ''
                        ? (string) $definition['subject']
                        : null,
                    'content' => (string) ($definition['content'] ?? ''),
                    'variables_json' => json_encode(array_values((array) ($definition['variables'] ?? []))),
                    'provider_variables_json' => '[]',
                    'provider_template_id' => null,
                    'is_enabled' => 1,
                    'is_custom' => 0,
                    'sort_order' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    private static function seedGroup(string $group, array $values): void
    {
        $existingKeys = DB::table('settings')
            ->where('group_key', $group)
            ->pluck('item_key')
            ->map(fn ($v) => (string) $v)
            ->toArray();

        $missing = [];
        foreach ($values as $key => $value) {
            if (! in_array($key, $existingKeys, true)) {
                $missing[$key] = $value;
            }
        }

        if ($missing === []) {
            return;
        }

        Setting::setValues($group, $missing);
    }
}
