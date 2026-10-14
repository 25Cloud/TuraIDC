<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;

/**
 * Builds the public site branding/config payload from basic settings.
 */
class SiteConfigPayload
{
    public const SETTING_GROUP_BASIC = 'basic';

    public const DEFAULT_SITE_NAME = '图拉云';

    // 默认品牌资源必须指向 public/branding 下实际存在的文件（该目录只有 PNG）；
    // 历史默认值 logo.svg / logo1.svg 并不存在，og:image 与 favicon 会 404。
    public const DEFAULT_SITE_LOGO = '/branding/logo.png';

    public const DEFAULT_SITE_FAVICON = '/branding/favicon-32.png';

    public const DEFAULT_SERVICE_QQ_GROUP = '待补充群号';

    public const DEFAULT_SUPPORT_GROUP_QR = '';

    public const DEFAULT_SUPPORT_GROUP_LINK = '';

    /** 全站默认品牌主色：TDesign 品牌蓝，商家未配置时使用 */
    public const DEFAULT_BRAND_PRIMARY_COLOR = '#165DFF';

    /** 全站默认圆角（px）：对齐项目规范「容器 6px、控件 3px」，即取 TDesign medium */
    public const DEFAULT_BRAND_RADIUS = 6;

    /** 圆角上限：超过后控件会呈胶囊状，界面比例失衡，直接回退默认 */
    private const BRAND_RADIUS_MAX = 24;

    /**
     * @return array<string, string>
     */
    public static function payload(): array
    {
        $defaultSiteName = (string) config('idc.site_name', self::DEFAULT_SITE_NAME);

        $siteName = self::read(self::SETTING_GROUP_BASIC, 'site_name', $defaultSiteName);
        $browserTitle = self::read(self::SETTING_GROUP_BASIC, 'browser_title', $siteName);
        $siteLogo = self::read(self::SETTING_GROUP_BASIC, 'site_logo', self::DEFAULT_SITE_LOGO);
        $siteFavicon = self::read(self::SETTING_GROUP_BASIC, 'site_favicon', self::DEFAULT_SITE_FAVICON);
        $clientConsoleIcon = self::read(self::SETTING_GROUP_BASIC, 'client_console_icon', $siteFavicon);
        $serviceQqGroup = self::readFirstAvailable(
            self::SETTING_GROUP_BASIC,
            ['service_qq_group', 'service_phone'],
            self::DEFAULT_SERVICE_QQ_GROUP
        );

        return [
            'site_name' => $siteName !== '' ? $siteName : $defaultSiteName,
            'browser_title' => $browserTitle !== '' ? $browserTitle : ($siteName !== '' ? $siteName : $defaultSiteName),
            'site_logo' => self::resolveManagedAsset($siteLogo !== '' ? $siteLogo : self::DEFAULT_SITE_LOGO),
            'site_favicon' => self::resolveManagedAsset($siteFavicon !== '' ? $siteFavicon : self::DEFAULT_SITE_FAVICON),
            'client_console_icon' => self::resolveManagedAsset($clientConsoleIcon !== '' ? $clientConsoleIcon : $siteFavicon),
            'service_qq_group' => $serviceQqGroup !== '' ? $serviceQqGroup : self::DEFAULT_SERVICE_QQ_GROUP,
            'service_phone' => $serviceQqGroup !== '' ? $serviceQqGroup : self::DEFAULT_SERVICE_QQ_GROUP,
            'service_email' => self::read(self::SETTING_GROUP_BASIC, 'service_email', ''),
            'service_hours' => self::read(self::SETTING_GROUP_BASIC, 'service_hours', ''),
            'support_group_title' => self::read(self::SETTING_GROUP_BASIC, 'support_group_title', ''),
            'support_group_text' => self::read(self::SETTING_GROUP_BASIC, 'support_group_text', ''),
            'support_group_qr' => self::resolveManagedAsset(self::read(self::SETTING_GROUP_BASIC, 'support_group_qr', self::DEFAULT_SUPPORT_GROUP_QR)),
            'support_group_link' => self::read(self::SETTING_GROUP_BASIC, 'support_group_link', self::DEFAULT_SUPPORT_GROUP_LINK),
            'terms_url' => self::read(self::SETTING_GROUP_BASIC, 'terms_url', ''),
            'privacy_url' => self::read(self::SETTING_GROUP_BASIC, 'privacy_url', ''),
            'icp_record' => self::read(self::SETTING_GROUP_BASIC, 'icp_record', ''),
            'value_added_license' => self::read(self::SETTING_GROUP_BASIC, 'value_added_license', ''),
            'brand_primary_color' => self::normalizeColor(
                self::read(self::SETTING_GROUP_BASIC, 'brand_primary_color', self::DEFAULT_BRAND_PRIMARY_COLOR)
            ),
            'brand_radius' => (string) self::normalizeRadius(
                self::read(self::SETTING_GROUP_BASIC, 'brand_radius', (string) self::DEFAULT_BRAND_RADIUS)
            ),
            'frontend_url' => (string) config('app.frontend_url', ''),
        ];
    }

    private static function read(string $group, string $key, string $default): string
    {
        $value = trim((string) Setting::getValue($group, $key, $default));

        return $value !== '' ? $value : $default;
    }

    /**
     * @param  array<int, string>  $keys
     */
    private static function readFirstAvailable(string $group, array $keys, string $default): string
    {
        foreach ($keys as $key) {
            $value = trim((string) Setting::getValue($group, $key, ''));
            if ($value !== '') {
                return $value;
            }
        }

        return $default;
    }

    private static function resolveManagedAsset(string $value): string
    {
        return UploadUrl::resolve($value) ?? '';
    }

    /**
     * 品牌主色会原样内联进首屏 HTML 的 <style>，因此只放行严格的十六进制颜色。
     *
     * 三位写法展开成六位并统一小写；任何带分号、花括号、逗号、url() 的输入都会
     * 让整条 style 规则失效，或直接成为样式注入点，一律回退默认色。
     */
    private static function normalizeColor(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^#([0-9a-fA-F]{3})$/', $value, $matches) === 1) {
            $hex = $matches[1];

            return '#'.strtolower($hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2]);
        }

        if (preg_match('/^#([0-9a-fA-F]{6})$/', $value, $matches) === 1) {
            return '#'.strtolower($matches[1]);
        }

        return self::DEFAULT_BRAND_PRIMARY_COLOR;
    }

    /**
     * 圆角只接受 0–24 的整数像素，超范围或非数字回退默认，避免出现胶囊化控件。
     */
    private static function normalizeRadius(string $value): int
    {
        $value = trim($value);

        if (preg_match('/^\d{1,3}$/', $value) !== 1) {
            return self::DEFAULT_BRAND_RADIUS;
        }

        $radius = (int) $value;

        return $radius <= self::BRAND_RADIUS_MAX ? $radius : self::DEFAULT_BRAND_RADIUS;
    }
}
