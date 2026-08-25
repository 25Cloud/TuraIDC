<?php

namespace App\Services\System;

use App\Exceptions\BusinessException;
use App\Models\Setting;
use App\Models\SystemSetting;
use App\Models\ThirdProductGroup;
use App\Support\AutomationScheduleExpression;
use Illuminate\Support\Collection;

class SettingService
{
    private const FIXED_RENEW_NOTICE_DAYS = [7, 3, 1];

    private const LOG_ARCHIVE_SETTING_KEYS = [
        'retention_days',
        'file_retention_days',
        'pt_archiver_binary',
        'pt_archiver_defaults_file',
        'concurrency',
        'batch_size',
        'sleep_seconds',
    ];

    private const PLUGIN_OWNED_SETTING_GROUPS = [
        'message_limit',
    ];

    private const PLUGIN_SETTING_KEYS = [
        'system' => [
            'captcha_enabled',
            'captcha_driver',
            'geetest_enabled',
            'geetest_captcha_id',
            'geetest_captcha_key',
        ],
        'payment' => [
            'alipay_enabled',
            'alipay_name',
            'alipay_app_id',
            'alipay_private_key',
            'alipay_public_key',
        ],
        'notification' => [
            'email_host',
            'email_port',
            'email_username',
            'email_password',
            'email_from_name',
            'email_encryption',
            'email_timeout_seconds',
            'sms_driver',
            'sms_provider',
            'sms_access_key',
            'sms_secret_key',
            'sms_sign_name',
            'sms_template_code',
        ],
        'verification' => [
            'verification_driver',
            'verification_api',
            'verification_key',
            'verification_biz_code',
            'free_attempts',
            'retry_fee',
        ],
    ];

    public static function defaultTrafficPackageConfig(): array
    {
        return [
            'traffic_package_enabled' => true,
            'traffic_package_display_threshold_percent' => 0,
            'traffic_package_button_text' => '购买流量包',
            'traffic_package_option_field' => 'flow_limit',
            'traffic_package_option_keyword' => '流量',
            'traffic_package_allow_choice_mode' => true,
            'traffic_package_allow_quantity_mode' => true,
        ];
    }

    public static function defaultAutomationConfig(): array
    {
        return [
            'expire_suspend_enabled' => true,
            'expire_suspend_after_days' => 0,
            'expire_suspend_notify_enabled' => true,
            'expire_unsuspend_notify_enabled' => true,
            'expire_terminate_enabled' => false,
            'expire_terminate_after_days' => 7,
            'service_lifecycle_schedule_mode' => AutomationScheduleExpression::MODE_EVERY_FIFTEEN_MINUTES,
            'service_lifecycle_schedule_time' => '00:00:00',
            'renew_notice_enabled' => true,
            'renew_notice_days_before' => self::FIXED_RENEW_NOTICE_DAYS,
            'renew_create_invoice_enabled' => true,
            'invoice_unpaid_reminder_enabled' => true,
            'invoice_unpaid_before_due_days' => 1,
            'invoice_overdue_reminder_days' => [1, 3, 5],
            'invoice_overdue_after_days' => 0,
            'billing_maintenance_schedule_mode' => AutomationScheduleExpression::MODE_HOURLY,
            'billing_maintenance_schedule_time' => '00:00:00',
            'ticket_auto_close_enabled' => true,
            'ticket_auto_close_after_hours' => 48,
            'ticket_auto_close_schedule_mode' => AutomationScheduleExpression::MODE_HOURLY,
            'ticket_auto_close_schedule_time' => '00:00:00',
            'pending_order_cleanup_enabled' => true,
            'pending_order_cleanup_after_hours' => 1,
            'pending_recharge_cleanup_enabled' => true,
            'pending_recharge_cleanup_after_days' => 3,
            'order_cleanup_schedule_mode' => AutomationScheduleExpression::MODE_EVERY_FIFTEEN_MINUTES,
            'order_cleanup_schedule_time' => '00:00:00',
            'supplier_low_balance_alert_repeat_hours' => 24,
            'supplier_balance_log_retention_days' => 30,
        ];
    }

    /**
     * @return array{retention_days: int, file_retention_days: int, pt_archiver_binary: string, pt_archiver_defaults_file: string, concurrency: int, batch_size: int, sleep_seconds: int}
     */
    public static function defaultLogArchiveConfig(): array
    {
        return [
            'retention_days' => (int) config('log_archive.retention_days', 30),
            'file_retention_days' => (int) config('log_archive.file_retention_days', 180),
            'pt_archiver_binary' => (string) config('log_archive.pt_archiver_binary', '/usr/bin/pt-archiver'),
            'pt_archiver_defaults_file' => (string) config('log_archive.pt_archiver_defaults_file', '/etc/turaidc/pt-archiver.cnf'),
            'concurrency' => (int) config('log_archive.concurrency', 2),
            'batch_size' => (int) config('log_archive.batch_size', 1000),
            'sleep_seconds' => (int) config('log_archive.sleep_seconds', 1),
        ];
    }

    public function getGroupSettings(string $group): Collection
    {
        if ($this->isPluginOwnedSettingGroup($group)) {
            return collect();
        }

        $fallbackSettingMap = $this->getGroupFallbackSettings($group);
        $storedSettings = $this->getStoredSettings($group);
        $automationConfig = trim($group) === 'automation' ? $this->getAutomationConfig() : [];

        $fallbackSettings = collect($fallbackSettingMap)
            ->map(fn (mixed $fallbackValue, string $key) => $this->formatSettingPayload(
                $group,
                $key,
                $this->resolveSettingValue($group, $key, $fallbackValue)
            ));

        $dynamicSettings = $storedSettings
            ->reject(fn ($setting, string $key) => array_key_exists($key, $fallbackSettingMap))
            ->reject(fn ($setting, string $key) => $this->isPluginSettingKey($group, $key))
            ->reject(fn ($setting, string $key) => $this->isNotificationTemplateSettingKey($group, $key))
            ->reject(fn ($setting, string $key) => $this->isUnsupportedLogArchiveSettingKey($group, $key))
            ->map(function (SystemSetting $setting) use ($group, $automationConfig): array {
                $key = (string) ($setting->item_key ?? '');
                $shouldNormalizeScheduleValue = str_ends_with($key, '_schedule_mode')
                    || str_ends_with($key, '_schedule_time');

                return $this->formatSettingPayload(
                    (string) ($setting->group_key ?? $group),
                    $key,
                    $shouldNormalizeScheduleValue
                        ? ($automationConfig[$key] ?? $setting->item_value ?? '')
                        : ($setting->item_value ?? ''),
                );
            });

        return $fallbackSettings
            ->concat($dynamicSettings)
            ->values();
    }

    public function saveGroupSettings(string $group, array $settings): void
    {
        if ($this->isPluginOwnedSettingGroup($group)) {
            return;
        }

        $prepared = $this->prepareSettingsForSave($group, $settings);
        if (trim($group) === 'notification') {
            $templateSettingKeys = $this->notificationTemplateSettingKeys($group, $prepared);
            $prepared = app(NotificationTemplateService::class)->extractTemplateSettings($prepared);
            $this->deleteStoredSettings($group, array_values(array_diff($templateSettingKeys, array_map('strval', array_keys($prepared)))));
        }

        Setting::setValues($group, $this->filterPluginSettings($group, $prepared));
    }

    public function revealSensitiveSetting(string $group, string $key): array
    {
        $settingKey = trim($key);
        $settingGroup = trim($group);
        if ($settingKey === '' || $this->isPluginSettingKey($settingGroup, $settingKey) || ! Setting::isSensitiveKey($settingKey)) {
            throw new BusinessException('敏感配置不存在', 42200);
        }

        $value = Setting::getValue($settingGroup, $settingKey, '');
        if (! $this->hasSettingValue($value)) {
            throw new BusinessException('敏感配置尚未填写', 42200);
        }

        return [
            'group' => $settingGroup,
            'key' => $settingKey,
            'value' => $value,
        ];
    }

    public function getProvisionHostnameConfig(): array
    {
        $enforce = $this->getBool('system', 'provision_hostname_enforce', false);
        $prefix = $this->sanitizeHostnamePrefix(
            $this->getString('system', 'provision_hostname_prefix', 'srv')
        );
        $charsets = $this->parseProvisionHostnameCharsets(
            $this->getString('system', 'provision_hostname_charsets', 'number')
        );
        $length = $this->getInt('system', 'provision_hostname_length', 12, 4, 200);
        $prefix = $prefix !== '' ? $prefix : 'srv';

        return [
            'enforce' => $enforce,
            'prefix' => $prefix,
            'length' => max($length, mb_strlen($prefix)),
            'charsets' => $charsets,
            'pool' => $this->buildProvisionHostnamePool($charsets),
        ];
    }

    public function normalizeHostname(string $hostname, bool $forceLowercase = true): string
    {
        $value = trim($hostname);
        if ($forceLowercase) {
            $value = mb_strtolower($value);
        }

        $value = preg_replace('/[^a-zA-Z0-9-]+/', '-', $value) ?? '';
        $value = preg_replace('/-+/', '-', $value) ?? '';
        $value = trim($value, '-');

        if ($value === '') {
            return '';
        }

        return mb_substr($value, 0, 63);
    }

    public function sanitizeHostnameFragment(string $value): string
    {
        $value = $this->normalizeHostname($value, true);

        return trim($value, '-');
    }

    public function sanitizeHostnamePrefix(string $value): string
    {
        $value = preg_replace('/[^a-zA-Z]+/', '', trim($value)) ?? '';
        $value = mb_strtolower($value);

        return mb_substr($value, 0, 10);
    }

    public function getAutomationConfig(): array
    {
        $defaults = self::defaultAutomationConfig();

        return [
            'expire_suspend_enabled' => $this->getBool('automation', 'expire_suspend_enabled', $defaults['expire_suspend_enabled']),
            'expire_suspend_after_days' => $this->getInt('automation', 'expire_suspend_after_days', $defaults['expire_suspend_after_days'], 0, 365),
            'expire_suspend_notify_enabled' => $this->getBool('automation', 'expire_suspend_notify_enabled', $defaults['expire_suspend_notify_enabled']),
            'expire_unsuspend_notify_enabled' => $this->getBool('automation', 'expire_unsuspend_notify_enabled', $defaults['expire_unsuspend_notify_enabled']),
            'expire_terminate_enabled' => $this->getBool('automation', 'expire_terminate_enabled', $defaults['expire_terminate_enabled']),
            'expire_terminate_after_days' => $this->getInt('automation', 'expire_terminate_after_days', $defaults['expire_terminate_after_days'], 1, 365),
            'service_lifecycle_schedule_mode' => $this->getScheduleMode('automation', 'service_lifecycle_schedule_mode', $defaults['service_lifecycle_schedule_mode']),
            'service_lifecycle_schedule_time' => $this->getScheduleTime('automation', 'service_lifecycle_schedule_time', $defaults['service_lifecycle_schedule_time']),
            'renew_notice_enabled' => $this->getBool('automation', 'renew_notice_enabled', $defaults['renew_notice_enabled']),
            'renew_notice_days_before' => self::FIXED_RENEW_NOTICE_DAYS,
            'renew_create_invoice_enabled' => $this->getBool('automation', 'renew_create_invoice_enabled', $defaults['renew_create_invoice_enabled']),
            'invoice_unpaid_reminder_enabled' => $this->getBool('automation', 'invoice_unpaid_reminder_enabled', $defaults['invoice_unpaid_reminder_enabled']),
            'invoice_unpaid_before_due_days' => $this->getInt('automation', 'invoice_unpaid_before_due_days', $defaults['invoice_unpaid_before_due_days'], 0, 30),
            'invoice_overdue_reminder_days' => $this->parseIntegerList($this->getString('automation', 'invoice_overdue_reminder_days', '1,3,5'), 0, 365),
            'invoice_overdue_after_days' => $this->getInt('automation', 'invoice_overdue_after_days', $defaults['invoice_overdue_after_days'], 0, 365),
            'billing_maintenance_schedule_mode' => $this->getScheduleMode('automation', 'billing_maintenance_schedule_mode', $defaults['billing_maintenance_schedule_mode']),
            'billing_maintenance_schedule_time' => $this->getScheduleTime('automation', 'billing_maintenance_schedule_time', $defaults['billing_maintenance_schedule_time']),
            'ticket_auto_close_enabled' => $this->getBool('automation', 'ticket_auto_close_enabled', $defaults['ticket_auto_close_enabled']),
            'ticket_auto_close_after_hours' => $this->getInt('automation', 'ticket_auto_close_after_hours', $defaults['ticket_auto_close_after_hours'], 1, 720),
            'ticket_auto_close_schedule_mode' => $this->getScheduleMode('automation', 'ticket_auto_close_schedule_mode', $defaults['ticket_auto_close_schedule_mode']),
            'ticket_auto_close_schedule_time' => $this->getScheduleTime('automation', 'ticket_auto_close_schedule_time', $defaults['ticket_auto_close_schedule_time']),
            'pending_order_cleanup_enabled' => $this->getBool('automation', 'pending_order_cleanup_enabled', $defaults['pending_order_cleanup_enabled']),
            'pending_order_cleanup_after_hours' => $this->getInt('automation', 'pending_order_cleanup_after_hours', $defaults['pending_order_cleanup_after_hours'], 1, 720),
            'pending_recharge_cleanup_enabled' => $this->getBool('automation', 'pending_recharge_cleanup_enabled', $defaults['pending_recharge_cleanup_enabled']),
            'pending_recharge_cleanup_after_days' => $this->getInt('automation', 'pending_recharge_cleanup_after_days', $defaults['pending_recharge_cleanup_after_days'], 0, 365),
            'order_cleanup_schedule_mode' => $this->getScheduleMode('automation', 'order_cleanup_schedule_mode', $defaults['order_cleanup_schedule_mode']),
            'order_cleanup_schedule_time' => $this->getScheduleTime('automation', 'order_cleanup_schedule_time', $defaults['order_cleanup_schedule_time']),
            // 余额跌破阈值后的重复告警间隔；余额回升到阈值以上会立即清除标记，
            // 因此这个间隔只约束"持续不足"期间的重复提醒，不会延误"再次跌破"的首报。
            'supplier_low_balance_alert_repeat_hours' => $this->getInt('automation', 'supplier_low_balance_alert_repeat_hours', $defaults['supplier_low_balance_alert_repeat_hours'], 1, 720),
            'supplier_balance_log_retention_days' => $this->getInt('automation', 'supplier_balance_log_retention_days', $defaults['supplier_balance_log_retention_days'], 1, 365),
        ];
    }

    /**
     * @return array{retention_days: int, file_retention_days: int, pt_archiver_binary: string, pt_archiver_defaults_file: string, concurrency: int, batch_size: int, sleep_seconds: int}
     */
    public function getLogArchiveConfig(): array
    {
        $defaults = self::defaultLogArchiveConfig();

        return [
            'retention_days' => $this->getInt('log_archive', 'retention_days', $defaults['retention_days'], 1, 3650),
            'file_retention_days' => $this->getInt('log_archive', 'file_retention_days', $defaults['file_retention_days'], 1, 3650),
            'pt_archiver_binary' => $this->getString('log_archive', 'pt_archiver_binary', $defaults['pt_archiver_binary']),
            'pt_archiver_defaults_file' => $this->getString('log_archive', 'pt_archiver_defaults_file', $defaults['pt_archiver_defaults_file']),
            'concurrency' => $this->getInt('log_archive', 'concurrency', $defaults['concurrency'], 1, 8),
            'batch_size' => $this->getInt('log_archive', 'batch_size', $defaults['batch_size'], 100, 10000),
            'sleep_seconds' => $this->getInt('log_archive', 'sleep_seconds', $defaults['sleep_seconds'], 0, 60),
        ];
    }

    public function getTrafficPackageConfig(): array
    {
        $defaults = self::defaultTrafficPackageConfig();

        return [
            'enabled' => $this->getBool('traffic_package', 'traffic_package_enabled', $defaults['traffic_package_enabled']),
            'display_threshold_percent' => $this->getInt(
                'traffic_package',
                'traffic_package_display_threshold_percent',
                $defaults['traffic_package_display_threshold_percent'],
                0,
                100
            ),
            'button_text' => $this->getString(
                'traffic_package',
                'traffic_package_button_text',
                $defaults['traffic_package_button_text']
            ),
            'option_field' => $this->getString(
                'traffic_package',
                'traffic_package_option_field',
                $defaults['traffic_package_option_field']
            ),
            'option_keyword' => $this->getString(
                'traffic_package',
                'traffic_package_option_keyword',
                $defaults['traffic_package_option_keyword']
            ),
            'allow_choice_mode' => $this->getBool(
                'traffic_package',
                'traffic_package_allow_choice_mode',
                $defaults['traffic_package_allow_choice_mode']
            ),
            'allow_quantity_mode' => $this->getBool(
                'traffic_package',
                'traffic_package_allow_quantity_mode',
                $defaults['traffic_package_allow_quantity_mode']
            ),
        ];
    }

    public function getTrafficPackageCatalog(): array
    {
        $raw = Setting::getValue('traffic_package_catalog', 'items', '[]');

        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return collect($decoded)
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $item) => $this->normalizeTrafficPackageCatalogItem($item))
            ->filter(fn (array $item) => (int) $item['category_id'] > 0 && (int) $item['target_value'] > 0)
            ->sortBy([
                ['product_type', 'asc'],
                ['category_id', 'asc'],
                ['sort_order', 'asc'],
                ['target_value', 'asc'],
            ])
            ->values()
            ->all();
    }

    public function getTrafficPackageCatalogForCategory(int $categoryId, string $productType = '', int $productId = 0): array
    {
        $normalizedType = trim($productType);
        $normalizedProductId = max($productId, 0);
        $categoryIds = $this->resolveTrafficPackageCategoryIds($categoryId);

        return collect($this->getTrafficPackageCatalog())
            ->filter(function (array $item) use ($categoryIds, $normalizedType, $normalizedProductId) {
                if (! in_array((int) $item['category_id'], $categoryIds, true)) {
                    return false;
                }

                if ($normalizedType === '') {
                    if ($normalizedProductId <= 0) {
                        return true;
                    }
                } elseif (trim((string) $item['product_type']) !== $normalizedType) {
                    return false;
                }

                if ($normalizedProductId <= 0) {
                    return true;
                }

                $productIds = is_array($item['product_ids'] ?? null) ? $item['product_ids'] : [];

                return $productIds === [] || in_array($normalizedProductId, $productIds, true);
            })
            ->values()
            ->all();
    }

    private function resolveTrafficPackageCategoryIds(int $categoryId): array
    {
        $categoryId = max($categoryId, 0);
        if ($categoryId <= 0) {
            return [];
        }

        $ids = [$categoryId];
        $thirdGroup = ThirdProductGroup::query()
            ->select(['id', 'second_product_group_id'])
            ->find($categoryId);

        if ($thirdGroup instanceof ThirdProductGroup && (int) ($thirdGroup->second_product_group_id ?? 0) > 0) {
            $ids[] = (int) $thirdGroup->second_product_group_id;
        }

        return array_values(array_unique($ids));
    }

    public function saveTrafficPackageCatalog(array $items): void
    {
        $normalized = collect($items)
            ->filter(fn ($item) => is_array($item))
            ->map(fn (array $item) => $this->normalizeTrafficPackageCatalogItem($item))
            ->filter(fn (array $item) => (int) $item['category_id'] > 0 && (int) $item['target_value'] > 0)
            ->sortBy([
                ['product_type', 'asc'],
                ['category_id', 'asc'],
                ['sort_order', 'asc'],
                ['target_value', 'asc'],
            ])
            ->values()
            ->all();

        Setting::setValue(
            'traffic_package_catalog',
            'items',
            json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    private function getString(string $group, string $key, string $default = ''): string
    {
        return trim((string) Setting::getValue($group, $key, $default));
    }

    /**
     * @return Collection<string, SystemSetting>
     */
    private function getStoredSettings(string $group): Collection
    {
        return SystemSetting::query()
            ->where('group_key', $group)
            ->get()
            ->keyBy(fn (SystemSetting $setting) => (string) $setting->item_key);
    }

    /**
     * 返回管理端需要直接回显的默认配置。
     *
     * @return array<string, mixed>
     */
    private function getGroupFallbackSettings(string $group): array
    {
        return match ($group) {
            'system' => [],
            'log_archive' => self::defaultLogArchiveConfig(),
            'traffic_package' => [
                'traffic_package_enabled' => '1',
                'traffic_package_display_threshold_percent' => '0',
                'traffic_package_button_text' => '购买流量包',
                'traffic_package_option_field' => 'flow_limit',
                'traffic_package_option_keyword' => '流量',
                'traffic_package_allow_choice_mode' => '1',
                'traffic_package_allow_quantity_mode' => '1',
            ],
            default => [],
        };
    }

    /**
     * 将配置文件中的默认值首次写入数据库，后续统一走数据库配置。
     *
     * @param  array<string, mixed>  $fallbackSettingMap
     * @param  Collection<string, SystemSetting>  $storedSettings
     */
    private function syncFallbackSettingsToDatabase(string $group, array $fallbackSettingMap, Collection $storedSettings): void
    {
        $pending = [];

        foreach ($fallbackSettingMap as $key => $value) {
            if ($storedSettings->has($key)) {
                continue;
            }

            if ($value === null) {
                continue;
            }

            if (is_string($value) && trim($value) === '' && ! $this->shouldPersistEmptyFallback($group, $key)) {
                continue;
            }

            $pending[$key] = $value;
        }

        Setting::setValues($group, $pending);
    }

    private function shouldPersistEmptyFallback(string $group, string $key): bool
    {
        return false;
    }

    private function resolveSettingValue(string $group, string $key, mixed $fallbackValue = ''): mixed
    {
        $value = Setting::getValue($group, $key);

        return ($value !== null && $value !== '') ? $value : $fallbackValue;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatSettingPayload(string $group, string $key, mixed $value): array
    {
        $isSecret = Setting::isSensitiveKey($key);

        return [
            'group' => $group,
            'key' => $key,
            'value' => $isSecret ? '' : $value,
            'is_secret' => $isSecret,
            'has_value' => $this->hasSettingValue($value),
            'masked_value' => $isSecret
                ? $this->maskSecretValue($value)
                : (is_string($value) ? trim($value) : (string) $value),
        ];
    }

    /**
     * 敏感配置空值表示保留旧值，避免后台读取掩码后误覆盖真实密钥。
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function prepareSettingsForSave(string $group, array $settings): array
    {
        foreach ($settings as $key => $value) {
            if (! Setting::isSensitiveKey((string) $key)) {
                continue;
            }

            if (is_string($value) && trim($value) === '') {
                unset($settings[$key]);
            }
        }

        $this->validateAutomationScheduleSettings($group, $settings);
        $this->validateLogArchiveSettings($group, $settings);

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function filterPluginSettings(string $group, array $settings): array
    {
        return array_filter(
            $settings,
            fn (string|int $key): bool => ! $this->isPluginSettingKey($group, (string) $key),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function isPluginSettingKey(string $group, string $key): bool
    {
        $group = trim($group);
        $key = trim($key);

        return $key !== '' && in_array($key, self::PLUGIN_SETTING_KEYS[$group] ?? [], true);
    }

    private function isUnsupportedLogArchiveSettingKey(string $group, string $key): bool
    {
        return trim($group) === 'log_archive'
            && ! in_array(trim($key), self::LOG_ARCHIVE_SETTING_KEYS, true);
    }

    private function isNotificationTemplateSettingKey(string $group, string $key): bool
    {
        if (trim($group) !== 'notification') {
            return false;
        }

        return preg_match('/^(email_template_(subject|content|css|enabled)|sms_template_(content|provider_template_id|enabled))_.+$/', trim($key)) === 1;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<int, string>
     */
    private function notificationTemplateSettingKeys(string $group, array $settings): array
    {
        return array_values(array_filter(
            array_map('strval', array_keys($settings)),
            fn (string $key): bool => $this->isNotificationTemplateSettingKey($group, $key)
        ));
    }

    /**
     * @param  array<int, string>  $keys
     */
    private function deleteStoredSettings(string $group, array $keys): void
    {
        $keys = array_values(array_filter(array_map('trim', $keys), fn (string $key): bool => $key !== ''));
        if ($keys === []) {
            return;
        }

        SystemSetting::query()
            ->where('group_key', trim($group))
            ->whereIn('item_key', $keys)
            ->delete();

        Setting::forgetCachedGroup(trim($group));
    }

    private function isPluginOwnedSettingGroup(string $group): bool
    {
        return in_array(trim($group), self::PLUGIN_OWNED_SETTING_GROUPS, true);
    }

    private function maskSecretValue(mixed $value): string
    {
        return $this->hasSettingValue($value) ? '******' : '';
    }

    private function hasSettingValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        return true;
    }

    private function getInt(string $group, string $key, int $default, int $min, int $max): int
    {
        $value = Setting::getValue($group, $key, $default);
        $value = is_numeric($value) ? (int) $value : $default;

        return max($min, min($max, $value));
    }

    private function getBool(string $group, string $key, bool $default): bool
    {
        $value = Setting::getValue($group, $key, $default ? '1' : '0');

        return in_array($value, [true, 1, '1', 'true', 'on'], true);
    }

    private function getScheduleMode(string $group, string $key, string $default): string
    {
        return AutomationScheduleExpression::normalizeMode(
            $this->getString($group, $key, $default),
            $default
        );
    }

    private function getScheduleTime(string $group, string $key, string $default): string
    {
        return AutomationScheduleExpression::normalizeTime(
            $this->getString($group, $key, $default),
            $default
        );
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function validateAutomationScheduleSettings(string $group, array $settings): void
    {
        if (trim($group) !== 'automation') {
            return;
        }

        $current = $this->getAutomationConfig();
        $definitions = [
            'service_lifecycle' => '服务生命周期',
            'billing_maintenance' => '账单自动化',
            'ticket_auto_close' => '工单自动关闭',
            'order_cleanup' => '账单与充值清理',
        ];

        foreach ($definitions as $prefix => $label) {
            $modeKey = $prefix.'_schedule_mode';
            $timeKey = $prefix.'_schedule_time';
            $mode = trim((string) ($settings[$modeKey] ?? $current[$modeKey] ?? ''));
            $time = trim((string) ($settings[$timeKey] ?? $current[$timeKey] ?? '00:00:00'));

            if (! in_array($mode, AutomationScheduleExpression::modes(), true)) {
                throw new BusinessException("{$label}的执行周期不受支持");
            }

            if (in_array($mode, [AutomationScheduleExpression::MODE_HOURLY, AutomationScheduleExpression::MODE_DAILY], true)
                && ! AutomationScheduleExpression::isHeartbeatAlignedTime($time)) {
                throw new BusinessException("{$label}的执行时间仅支持分钟为 00、15、30 或 45，且秒必须为 00");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private function validateLogArchiveSettings(string $group, array $settings): void
    {
        if (trim($group) !== 'log_archive') {
            return;
        }

        $keys = array_map('strval', array_keys($settings));
        if (array_diff($keys, self::LOG_ARCHIVE_SETTING_KEYS) !== []) {
            throw new BusinessException('日志归档配置包含不受支持的字段', 42200);
        }

        $resolved = array_replace($this->getLogArchiveConfig(), $settings);
        $pathLabels = [
            'pt_archiver_binary' => 'pt-archiver 可执行文件',
            'pt_archiver_defaults_file' => 'pt-archiver 配置文件',
        ];

        foreach ($pathLabels as $key => $label) {
            $path = trim((string) ($resolved[$key] ?? ''));
            if (! $this->isAbsolutePathWithoutParentTraversal($path)) {
                throw new BusinessException("{$label}必须为不含 .. 的绝对路径", 42200);
            }
        }

        $integerRules = [
            'retention_days' => ['日志保留天数', 1, 3650],
            'file_retention_days' => ['归档文件保留天数', 1, 3650],
            'concurrency' => ['并发数', 1, 8],
            'batch_size' => ['批量大小', 100, 10000],
            'sleep_seconds' => ['批次间隔', 0, 60],
        ];

        foreach ($integerRules as $key => [$label, $minimum, $maximum]) {
            $value = $resolved[$key] ?? null;
            if (filter_var($value, FILTER_VALIDATE_INT) === false
                || (int) $value < $minimum
                || (int) $value > $maximum) {
                throw new BusinessException("{$label}必须在 {$minimum} 到 {$maximum} 之间", 42200);
            }
        }
    }

    private function isAbsolutePathWithoutParentTraversal(string $path): bool
    {
        return $path !== ''
            && (str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1)
            && preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $path) !== 1;
    }

    private function parseProvisionHostnameCharsets(string $value): array
    {
        $items = collect(explode(',', $value))
            ->map(fn (string $item) => trim($item))
            ->filter(fn (string $item) => in_array($item, ['number', 'uppercase', 'lowercase'], true))
            ->unique()
            ->values()
            ->all();

        return $items !== [] ? $items : ['number'];
    }

    private function buildProvisionHostnamePool(array $charsets): string
    {
        $pool = '';

        if (in_array('number', $charsets, true)) {
            $pool .= '0123456789';
        }

        if (in_array('uppercase', $charsets, true)) {
            $pool .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        }

        if (in_array('lowercase', $charsets, true)) {
            $pool .= 'abcdefghijklmnopqrstuvwxyz';
        }

        return $pool !== '' ? $pool : '0123456789';
    }

    private function parseIntegerList(string $value, int $min = 0, int $max = 365): array
    {
        return collect(explode(',', $value))
            ->map(fn (string $item) => trim($item))
            ->filter(fn (string $item) => $item !== '' && is_numeric($item))
            ->map(fn (string $item) => max($min, min($max, (int) $item)))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function normalizeTrafficPackageCatalogItem(array $item): array
    {
        $categoryId = max((int) ($item['category_id'] ?? 0), 0);
        $targetValue = max((int) ($item['target_value'] ?? 0), 0);
        $price = is_numeric($item['price'] ?? null)
            ? number_format(max((float) $item['price'], 0), 2, '.', '')
            : '0.00';
        $sortOrder = max((int) ($item['sort_order'] ?? 0), 0);
        $label = trim((string) ($item['label'] ?? ''));

        if ($label === '' && $targetValue > 0) {
            $label = $targetValue >= 1024 && $targetValue % 1024 === 0
                ? ((int) ($targetValue / 1024)).'T'
                : $targetValue.'G';
        }

        return [
            'category_id' => $categoryId,
            'product_type' => trim((string) ($item['product_type'] ?? '')),
            'product_ids' => collect((array) ($item['product_ids'] ?? []))
                ->map(fn ($value) => (int) $value)
                ->filter(fn (int $value) => $value > 0)
                ->unique()
                ->values()
                ->all(),
            'label' => $label,
            'target_value' => $targetValue,
            'price' => $price,
            'enabled' => in_array($item['enabled'] ?? 1, [true, 1, '1', 'true', 'on'], true) ? 1 : 0,
            'sort_order' => $sortOrder,
        ];
    }
}
