<?php

declare(strict_types=1);

namespace App\Support;

/**
 * API 密钥 IP 白名单匹配。
 *
 * 支持两种条目形态：
 *  - 精确 IP：`203.0.113.10`、`2001:db8::1`（IPv6 十六进制大小写不敏感）
 *  - CIDR 网段：`203.0.113.0/24`、`2001:db8::/32`
 *
 * 统一走 inet_pton 的二进制比较，不依赖字符串前缀，IPv4 与 IPv6 按地址族
 * 分别匹配；条目非法或地址族不一致时视为不匹配，绝不放行。
 */
final class IpAllowlistMatcher
{
    public static function matchesAny(string $ip, array $entries): bool
    {
        foreach ($entries as $entry) {
            if (self::matches($ip, (string) $entry)) {
                return true;
            }
        }

        return false;
    }

    public static function matches(string $ip, string $entry): bool
    {
        $ip = trim($ip);
        $entry = trim($entry);
        if ($ip === '' || $entry === '') {
            return false;
        }

        if (! str_contains($entry, '/')) {
            return strcasecmp($ip, $entry) === 0;
        }

        [$subnet, $bitsRaw] = explode('/', $entry, 2);
        $bits = (int) $bitsRaw;
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton(trim($subnet));

        if ($ipBin === false || $subnetBin === false || $bits < 0) {
            return false;
        }

        // IPv4 与 IPv6 的二进制长度不同，地址族不一致直接不匹配
        if (strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $maxBits = strlen($ipBin) * 8;
        if ($bits > $maxBits) {
            return false;
        }

        $fullBytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }

        $restBits = $bits % 8;
        if ($restBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $restBits)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }
}
