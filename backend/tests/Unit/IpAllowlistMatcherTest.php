<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\IpAllowlistMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IpAllowlistMatcherTest extends TestCase
{
    #[Test]
    public function matches_exact_ipv4_and_ipv6(): void
    {
        $this->assertTrue(IpAllowlistMatcher::matches('203.0.113.10', '203.0.113.10'));
        $this->assertFalse(IpAllowlistMatcher::matches('203.0.113.10', '203.0.113.11'));
        // IPv6 十六进制大小写不敏感
        $this->assertTrue(IpAllowlistMatcher::matches('2001:DB8::1', '2001:db8::1'));
        $this->assertFalse(IpAllowlistMatcher::matches('2001:db8::1', '2001:db8::2'));
    }

    #[Test]
    public function matches_ipv4_cidr_ranges(): void
    {
        $this->assertTrue(IpAllowlistMatcher::matches('203.0.113.10', '203.0.113.0/24'));
        $this->assertTrue(IpAllowlistMatcher::matches('203.0.113.255', '203.0.113.0/24'));
        $this->assertFalse(IpAllowlistMatcher::matches('203.0.114.1', '203.0.113.0/24'));
        $this->assertTrue(IpAllowlistMatcher::matches('198.51.100.7', '198.51.100.7/32'));
        $this->assertTrue(IpAllowlistMatcher::matches('203.0.113.130', '203.0.113.128/25'));
        $this->assertFalse(IpAllowlistMatcher::matches('203.0.113.1', '203.0.113.128/25'));
    }

    #[Test]
    public function matches_ipv6_cidr_ranges(): void
    {
        $this->assertTrue(IpAllowlistMatcher::matches('2001:db8::1', '2001:db8::/32'));
        $this->assertTrue(IpAllowlistMatcher::matches('2001:db8:ffff::99', '2001:db8::/32'));
        $this->assertFalse(IpAllowlistMatcher::matches('2001:db9::1', '2001:db8::/32'));
        $this->assertTrue(IpAllowlistMatcher::matches('2001:db8::5', '2001:db8::5/128'));
    }

    #[Test]
    public function rejects_cross_family_and_malformed_entries(): void
    {
        // 地址族不一致一律不匹配
        $this->assertFalse(IpAllowlistMatcher::matches('203.0.113.10', '2001:db8::/32'));
        $this->assertFalse(IpAllowlistMatcher::matches('2001:db8::1', '203.0.113.0/24'));
        // 非法条目不匹配、绝不放行
        $this->assertFalse(IpAllowlistMatcher::matches('203.0.113.10', 'not-an-ip/24'));
        $this->assertFalse(IpAllowlistMatcher::matches('203.0.113.10', '999.999.999.999/24'));
        $this->assertFalse(IpAllowlistMatcher::matches('', '203.0.113.0/24'));
        $this->assertFalse(IpAllowlistMatcher::matches('203.0.113.10', ''));
    }

    #[Test]
    public function matches_any_scans_all_entries(): void
    {
        $this->assertTrue(IpAllowlistMatcher::matchesAny('198.51.100.7', [
            '10.0.0.1',
            '198.51.100.0/24',
        ]));
        $this->assertFalse(IpAllowlistMatcher::matchesAny('192.0.2.1', [
            '10.0.0.1',
            '198.51.100.0/24',
        ]));
    }

    #[Test]
    #[DataProvider('edgeBitMasksProvider')]
    public function handles_non_byte_aligned_prefixes(string $ip, string $entry, bool $expected): void
    {
        $this->assertSame($expected, IpAllowlistMatcher::matches($ip, $entry));
    }

    public static function edgeBitMasksProvider(): array
    {
        return [
            'v4 /1 lower half' => ['0.0.0.1', '0.0.0.0/1', true],
            'v4 /1 upper half' => ['128.0.0.1', '0.0.0.0/1', false],
            'v4 /9 second octet high bit' => ['203.128.0.1', '203.128.0.0/9', true],
            'v4 /9 low octet' => ['203.1.0.1', '203.128.0.0/9', false],
            'v4 /0 matches all' => ['8.8.8.8', '0.0.0.0/0', true],
            'v6 /16' => ['2001:db8::1', '2001:db8::/16', true],
            'v6 /16 other' => ['2002:db8::1', '2001:db8::/16', false],
        ];
    }
}
