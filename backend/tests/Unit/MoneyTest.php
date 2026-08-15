<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Money;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_add_avoids_float_drift(): void
    {
        // 0.1 + 0.2 在 float 下为 0.30000000000000004，集中舍入应归一为 0.30
        $this->assertSame(0.3, Money::add(0.1, 0.2));
        $this->assertSame('0.30', Money::format(Money::add(0.1, 0.2)));
    }

    public function test_multiply_rounds_to_cents(): void
    {
        $this->assertSame(3.33, Money::multiply(1.11, 3));
        $this->assertSame(0.01, Money::multiply(0.1, 0.1));
        $this->assertSame('9.99', Money::format(Money::multiply(3.33, 3)));
    }

    public function test_subtract_rounds_to_cents(): void
    {
        $this->assertSame(0.1, Money::subtract(0.3, 0.2));
        $this->assertSame('0.10', Money::format(Money::subtract(0.3, 0.2)));
    }

    public function test_equals_uses_epsilon(): void
    {
        $this->assertTrue(Money::equals(0.1 + 0.2, 0.3));
        $this->assertTrue(Money::equals(19.99, 19.99));
        $this->assertFalse(Money::equals(19.99, 20.00));
    }

    public function test_format_normalizes_to_two_decimals(): void
    {
        $this->assertSame('0.00', Money::format(0));
        $this->assertSame('1.50', Money::format(1.5));
        $this->assertSame('2.35', Money::format(2.346));
        $this->assertSame('2.34', Money::format(2.344));
    }
}
