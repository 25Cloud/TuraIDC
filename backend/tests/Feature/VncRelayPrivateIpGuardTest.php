<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\VncRelayCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use Tests\TestCase;

/**
 * VNC Relay 内网拦截：IPv4-mapped IPv6 必须先归一化再判定。
 *
 * `::ffff:127.0.0.1` 只通得过 FILTER_FLAG_IPV6，会落进 IPv6 分支；而
 * explode(':', '::ffff:127.0.0.1')[0] 取到的是空串（开头就是 `::`），既不匹配
 * fe[89ab] 也不匹配 f[cd]，于是被判成公网放行——随后内核按 mapped 语义直连
 * IPv4 回环，整道拦截形同不存在。
 */
class VncRelayPrivateIpGuardTest extends TestCase
{
    /** @return list<array{0: string, 1: string}> */
    public static function privateIpProvider(): array
    {
        return [
            // ── IPv4-mapped 形态（本次修复的绕过面）──
            ['::ffff:127.0.0.1', 'IPv4-mapped 回环'],
            ['::ffff:169.254.169.254', 'IPv4-mapped 云元数据端点'],
            ['::ffff:10.0.0.5', 'IPv4-mapped 私网 10/8'],
            ['::ffff:192.168.1.1', 'IPv4-mapped 私网 192.168/16'],
            ['::ffff:172.16.0.1', 'IPv4-mapped 私网 172.16/12'],
            ['::ffff:100.64.0.1', 'IPv4-mapped 运营商级 NAT'],
            ['::ffff:0.0.0.0', 'IPv4-mapped 全零'],
            // 大写与压缩写法同样要拦住
            ['::FFFF:127.0.0.1', '大写 mapped 前缀'],
            // ── IPv4-compatible 形态 ──
            ['::127.0.0.1', 'IPv4-compatible 回环'],
            // ── 原本已能拦住的，防修复过程中改坏 ──
            ['127.0.0.1', '裸 IPv4 回环'],
            ['10.1.2.3', '裸私网 10/8'],
            ['169.254.169.254', '裸云元数据端点'],
            ['192.168.0.10', '裸私网 192.168/16'],
            ['::1', 'IPv6 回环'],
            ['::', 'IPv6 全零'],
            ['fe80::1', 'IPv6 链路本地'],
            ['fc00::1', 'IPv6 唯一本地'],
            ['fd12:3456::1', 'IPv6 唯一本地 fd'],
            ['not-an-ip', '非法输入一律视为内网'],
        ];
    }

    #[DataProvider('privateIpProvider')]
    public function test_private_or_reserved_ip_is_rejected(string $ip, string $label): void
    {
        $this->assertTrue(
            $this->isPrivateOrReservedIp($ip),
            "{$label}（{$ip}）必须判为内网/保留地址并拒绝连接"
        );
    }

    /** @return list<array{0: string, 1: string}> */
    public static function publicIpProvider(): array
    {
        return [
            ['8.8.8.8', '公网 IPv4'],
            ['1.1.1.1', '公网 IPv4'],
            ['203.0.113.10', '文档用公网段仍属可路由'],
            ['::ffff:8.8.8.8', 'IPv4-mapped 公网地址不应被误拦'],
            ['2001:4860:4860::8888', '公网 IPv6'],
            ['2400:3200::1', '公网 IPv6'],
        ];
    }

    /** 归一化不得把公网地址误判成内网——否则修复会打断正常的 VNC 连接。 */
    #[DataProvider('publicIpProvider')]
    public function test_public_ip_is_allowed(string $ip, string $label): void
    {
        $this->assertFalse(
            $this->isPrivateOrReservedIp($ip),
            "{$label}（{$ip}）应判为公网并放行"
        );
    }

    private function isPrivateOrReservedIp(string $ip): bool
    {
        $method = new ReflectionMethod(VncRelayCommand::class, 'isPrivateOrReservedIp');
        $method->setAccessible(true);

        return (bool) $method->invoke(app(VncRelayCommand::class), $ip);
    }
}
