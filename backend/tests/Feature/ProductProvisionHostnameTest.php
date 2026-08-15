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
}
