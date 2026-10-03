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
            || isset($parts['fragment'])) {
            throw new LogicException(sprintf('%s 必须是无账号信息、无查询串的 HTTP(S) 地址。', $environmentKey));
        }

        // 允许带路径前缀：单域名部署下三端靠 /console、/admin 等子路径区分，
        // join() 会在该前缀之后继续追加具体页面路径。
        return $value;
    }
}
