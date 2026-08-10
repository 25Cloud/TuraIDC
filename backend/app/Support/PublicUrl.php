<?php

declare(strict_types=1);

namespace App\Support;

use LogicException;

/**
 * Public endpoint ownership for the four independently deployed applications.
 */
final class PublicUrl
{
    public static function api(string $path = ''): string
    {
        return self::join('app.url', 'APP_URL', $path);
    }

    public static function website(string $path = ''): string
    {
        return self::join('app.frontend_url', 'FRONTEND_URL', $path);
    }

    public static function console(string $path = ''): string
    {
        return self::join('app.client_console_url', 'CLIENT_CONSOLE_URL', $path);
    }

    public static function admin(string $path = ''): string
    {
        return self::join('app.admin_url', 'ADMIN_URL', $path);
    }

    private static function join(string $configKey, string $environmentKey, string $path): string
    {
        $base = self::base($configKey, $environmentKey);
        $path = trim($path);

        if ($path === '') {
            return $base;
        }

        return $base.'/'.ltrim($path, '/');
    }

    private static function base(string $configKey, string $environmentKey): string
    {
        $value = rtrim(trim((string) config($configKey, '')), '/');
        $parts = parse_url($value);

        if (! is_array($parts)
            || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($parts['host'] ?? '')) === ''
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')) {
            throw new LogicException(sprintf('%s 必须是无路径、无账号信息的 HTTP(S) 根地址。', $environmentKey));
        }

        return $value;
    }
}
