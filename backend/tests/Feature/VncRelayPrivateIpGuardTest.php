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
            // ── 其余「非全局可达」的 special-purpose 段（RFC 6890）──
            // 这几个此前被漏掉，且本测试原先把 203.0.113.10 当成公网放行，是错的：
            // 203.0.113.0/24 是 TEST-NET-3，文档保留、不可全局路由。
            ['192.0.2.1', 'TEST-NET-1'],
            ['198.51.100.1', 'TEST-NET-2'],
            ['203.0.113.10', 'TEST-NET-3'],
            ['::ffff:203.0.113.10', 'IPv4-mapped TEST-NET-3'],
            ['198.18.0.1', '网络设备基准测试段'],
            ['192.88.99.1', '6to4 中继任播（已废弃）'],
            ['224.0.0.1', '组播'],
            ['239.255.255.250', '组播（SSDP）'],
            ['240.0.0.1', '保留段'],
            ['255.255.255.255', '受限广播'],
            ['::ffff:224.0.0.1', 'IPv4-mapped 组播'],
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
            ['9.9.9.9', '公网 IPv4'],
            ['104.16.0.1', '公网 IPv4（CDN 段）'],
            ['::ffff:8.8.8.8', 'IPv4-mapped 公网地址不应被误拦'],
            ['2001:4860:4860::8888', '公网 IPv6'],
            ['2400:3200::1', '公网 IPv6'],
            // 边界：紧邻保留段但确属可路由，防止掩码写宽误伤
            ['192.0.1.255', '192.0.0.0/24 与 192.0.2.0/24 之间'],
            ['198.17.255.255', '198.18.0.0/15 之前'],
            ['198.20.0.1', '198.18.0.0/15 之后'],
            ['223.255.255.255', '224.0.0.0/4 之前'],
            ['100.63.255.255', '100.64.0.0/10 之前'],
            ['100.128.0.1', '100.64.0.0/10 之后'],
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
