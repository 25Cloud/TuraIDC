<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ProductProvisionHostname;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProductProvisionHostnameTest extends TestCase
{
    #[Test]
    public function it_uses_upstream_hard_constraints_for_prefix_generation(): void
    {
        $rule = ProductProvisionHostname::buildGenerationRule(
            [
                'prefix' => 'srv',
                'length' => 12,
                'pool' => '0123456789',
            ],
            [
                'mode' => ProductProvisionHostname::MODE_PREFIX,
                'value' => 'ser',
                'length' => 12,
            ],
            [
                'prefix' => 'ser',
                'length' => 13,
                'pool' => '0123456789',
            ]
        );

        $this->assertSame('ser', $rule['prefix']);
        $this->assertSame(13, $rule['length']);
        $this->assertSame('0123456789', $rule['pool']);
    }

    #[Test]
    public function it_keeps_local_prefix_rule_when_upstream_rule_is_missing(): void
    {
        $rule = ProductProvisionHostname::buildGenerationRule(
            [
                'prefix' => 'srv',
                'length' => 12,
                'pool' => '0123456789',
            ],
            [
                'mode' => ProductProvisionHostname::MODE_PREFIX,
                'value' => 'abc',
                'length' => 11,
            ]
        );

        $this->assertSame('abc', $rule['prefix']);
        $this->assertSame(11, $rule['length']);
        $this->assertSame('0123456789', $rule['pool']);
    }

    /**
     * 线上事故回归：上游紫熙云的虚拟主机商品把前缀设成 `chengdumn`（8 位）并配
     * `len_num: 12`，历史实现按「12 + 8 = 20/21 位」生成主机名，撞穿 MySQL 5.6
     * 的 16 位用户名上限，客户在 phpMyAdmin 里连不上数据库。
     */
    #[Test]
    public function it_never_exceeds_the_max_length_even_when_upstream_length_is_larger(): void
    {
        $rule = ProductProvisionHostname::buildGenerationRule(
            [
                'prefix' => 'ser',
                'length' => 12,
                'pool' => '0123456789',
                'max_length' => 16,
            ],
            [],
            [
                'prefix' => 'chengdumn',
                'length' => 21,
                'pool' => '0123456789',
            ]
        );

        $this->assertSame('chengdumn', $rule['prefix']);
        $this->assertSame(16, $rule['length']);
        $this->assertSame(16, $rule['max_length']);
    }

    /**
     * 前缀本身就超过上限时，长度只能取上限，不能反过来把长度顶到前缀长度——
     * 那正是 21 位主机名的来源。
     */
    #[Test]
    public function it_caps_length_at_max_length_when_prefix_is_longer_than_the_cap(): void
    {
        $rule = ProductProvisionHostname::buildGenerationRule(
            [
                'prefix' => 'srv',
                'length' => 12,
                'pool' => '0123456789',
                'max_length' => 10,
            ],
            [],
            [
                'prefix' => 'abcdefghijklmnop',
                'length' => 30,
                'pool' => '0123456789',
            ]
        );

        $this->assertSame(10, $rule['length']);
        $this->assertLessThanOrEqual($rule['max_length'], $rule['length']);
    }

    #[Test]
    public function it_ignores_product_and_upstream_prefix_when_unify_prefix_is_enabled(): void
    {
        $rule = ProductProvisionHostname::buildGenerationRule(
            [
                'prefix' => 'ser',
                'length' => 12,
                'pool' => '0123456789',
                'max_length' => 16,
                'unify_prefix' => true,
            ],
            [
                'mode' => ProductProvisionHostname::MODE_PREFIX,
                'value' => 'hk',
                'length' => 20,
            ],
            [
                'prefix' => 'chengdumn',
                'length' => 21,
                'pool' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',
            ]
        );

        $this->assertSame('ser', $rule['prefix']);
        $this->assertSame(12, $rule['length']);
        $this->assertTrue($rule['unify_prefix']);
        // 统一前缀时商品与上游的字符集也不再生效，避免随机段混进大写字母后难以和用户名校验对齐
        $this->assertSame('0123456789', $rule['pool']);
    }

    #[Test]
    public function it_clamps_an_over_long_hostname_to_the_cap(): void
    {
        $this->assertSame(
            'chengdumn6811979',
            ProductProvisionHostname::clamp('chengdumn681197919307', 16)
        );
        $this->assertSame('shorthost', ProductProvisionHostname::clamp('shorthost', 16));
        $this->assertSame('', ProductProvisionHostname::clamp('', 16));
    }
}
