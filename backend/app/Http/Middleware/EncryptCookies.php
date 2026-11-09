<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Cookie\Middleware\EncryptCookies as FrameworkEncryptCookies;

/**
 * 应用 Cookie 加密中间件。
 *
 * 控制台区域票据由 API 在 iframe 请求中读取。该请求刻意不带 Referer，
 * 因此 Sanctum 不会进入 stateful 解密链路；票据统一保持明文 Cookie，
 * 由短 TTL、服务路径和后端缓存校验提供边界，避免签发和读取采用两套编码。
 */
final class EncryptCookies extends FrameworkEncryptCookies
{
    public function isDisabled($name): bool
    {
        return str_starts_with((string) $name, 'tura_console_area_ticket_')
            || parent::isDisabled($name);
    }
}
