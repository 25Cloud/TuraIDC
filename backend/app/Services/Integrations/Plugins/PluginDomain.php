<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins;

use InvalidArgumentException;

final class PluginDomain
{
    public const PAYMENT = 'payment';

    public const VERIFICATION = 'verification';

    public const CAPTCHA = 'captcha';

    public const MAIL = 'mail';

    public const SMS = 'sms';

    public const UPSTREAM = 'upstream';

    public const ADDONS = 'addons';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [
            self::PAYMENT,
            self::VERIFICATION,
            self::CAPTCHA,
            self::MAIL,
            self::SMS,
            self::UPSTREAM,
            self::ADDONS,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function singleEnabledDomains(): array
    {
        return [
            self::CAPTCHA,
            self::VERIFICATION,
            self::MAIL,
            self::SMS,
        ];
    }

    public static function requiresSingleEnabledPlugin(string $domain): bool
    {
        return in_array(self::assertValid($domain), self::singleEnabledDomains(), true);
    }

    public static function assertValid(string $domain): string
    {
        $normalized = trim($domain);

        if (! in_array($normalized, self::values(), true)) {
            throw new InvalidArgumentException("Unsupported plugin domain [{$domain}]");
        }

        return $normalized;
    }

    public static function directoryName(string $domain): string
    {
        return match (self::assertValid($domain)) {
            self::PAYMENT => 'gateways',
            self::VERIFICATION => 'certification',
            self::CAPTCHA => 'captcha',
            self::MAIL => 'mail',
            self::SMS => 'sms',
            self::UPSTREAM => 'servers',
            self::ADDONS => 'addons',
        };
    }
}
