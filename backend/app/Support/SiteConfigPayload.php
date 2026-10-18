<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Setting;
use App\Services\ProductCatalog\ProductDescriptionService;

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

    /** 卡片式商品卡片是否展示库存：默认展示，运营可在后台独立页面关掉 */
    public const DEFAULT_PRODUCT_CARD_SHOW_STOCK = '1';

    /** 独立配置页是否展示「机型对比」表：默认展示 */
    public const DEFAULT_PRODUCT_DETAIL_SPEC_COMPARE = '1';

    /** 选购页版心：contained=居中并限制最大宽度，full=铺满 */
    public const DEFAULT_PRODUCT_PAGE_LAYOUT = 'contained';

    /** 选购页版心最大宽度（px），仅 contained 生效 */
    public const DEFAULT_PRODUCT_PAGE_MAX_WIDTH = 1280;

    private const PRODUCT_PAGE_LAYOUTS = ['contained', 'full'];

    private const PRODUCT_PAGE_MAX_WIDTH_MIN = 960;

    private const PRODUCT_PAGE_MAX_WIDTH_MAX = 1920;

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
            // 全站商品展示方式：列表式（商品表格）/ 卡片式（商品卡片）。
            // 与魔方的 order_page_style 同义，只是我们的取值只有两种形态。
            'product_display_mode' => ProductDescriptionService::normalizeDisplayMode(
                self::read(
                    self::SETTING_GROUP_BASIC,
                    ProductDescriptionService::SETTING_KEY_DISPLAY_MODE,
                    ProductDescriptionService::DEFAULT_DISPLAY_MODE
                )
            ),
            // 选购页展示层的四个开关（后台「商品展示」独立页面维护）：
            // 卡片是否展示库存、独立配置页是否展示机型对比、版心是否居中、版心最大宽度。
            'product_card_show_stock' => self::normalizeFlag(
                self::read(self::SETTING_GROUP_BASIC, 'product_card_show_stock', self::DEFAULT_PRODUCT_CARD_SHOW_STOCK),
                self::DEFAULT_PRODUCT_CARD_SHOW_STOCK
            ),
            'product_detail_spec_compare' => self::normalizeFlag(
                self::read(
                    self::SETTING_GROUP_BASIC,
                    'product_detail_spec_compare',
                    self::DEFAULT_PRODUCT_DETAIL_SPEC_COMPARE
                ),
                self::DEFAULT_PRODUCT_DETAIL_SPEC_COMPARE
            ),
            'product_page_layout' => self::normalizePageLayout(
                self::read(self::SETTING_GROUP_BASIC, 'product_page_layout', self::DEFAULT_PRODUCT_PAGE_LAYOUT)
            ),
            'product_page_max_width' => (string) self::normalizePageMaxWidth(
                self::read(
                    self::SETTING_GROUP_BASIC,
                    'product_page_max_width',
                    (string) self::DEFAULT_PRODUCT_PAGE_MAX_WIDTH
                )
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
     * 布尔开关统一收敛成 '1' / '0'，脏值回落默认值。
     *
     * 开关值会进首屏配置与运营决策路径（卡片是否显示库存、是否显示机型对比），
     * 出现第三种取值会让前台判断分叉，所以这里只认常见的真/假写法。
     */
    private static function normalizeFlag(string $value, string $default): string
    {
        $normalized = strtolower(trim($value));

        if (in_array($normalized, ['1', 'true', 'on', 'yes'], true)) {
            return '1';
        }

        if (in_array($normalized, ['0', 'false', 'off', 'no'], true)) {
            return '0';
        }

        return $default;
    }

    private static function normalizePageLayout(string $value): string
    {
        $normalized = strtolower(trim($value));

        return in_array($normalized, self::PRODUCT_PAGE_LAYOUTS, true)
            ? $normalized
            : self::DEFAULT_PRODUCT_PAGE_LAYOUT;
    }

    /**
     * 版心最大宽度限制在可读区间：太窄会挤坏机型表，太宽等于没限制。
     */
    private static function normalizePageMaxWidth(string $value): int
    {
        $width = (int) trim($value);

        if ($width <= 0) {
            return self::DEFAULT_PRODUCT_PAGE_MAX_WIDTH;
        }

        return min(max($width, self::PRODUCT_PAGE_MAX_WIDTH_MIN), self::PRODUCT_PAGE_MAX_WIDTH_MAX);
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
