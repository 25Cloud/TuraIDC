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

    public const DEFAULT_SITE_LOGO = '/branding/logo.svg';

    public const DEFAULT_SITE_FAVICON = '/branding/logo1.svg';

    public const DEFAULT_SERVICE_QQ_GROUP = '待补充群号';

    public const DEFAULT_SUPPORT_GROUP_QR = '';

    public const DEFAULT_SUPPORT_GROUP_LINK = '';

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
}
