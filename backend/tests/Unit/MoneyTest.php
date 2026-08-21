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

    /**
     * add() 是「先求和、再一次舍入」，不是「逐项舍入后相加」。
     *
     * 这个区别对小于一分的入参可见：两个 0.004 单独舍入都是 0，但求和后为 0.008，
     * 舍入得 0.01。改动 Money 的实现时必须保住这个语义——改成逐项分位化会让
     * 同样的输入得到 0.00，属于静默的金额语义变更。
     */
    public function test_add_rounds_once_after_summing(): void
    {
        $this->assertSame(0.01, Money::add(0.004, 0.004));
        $this->assertSame(0.0, Money::round(0.004));
    }

    /**
     * 常规明细数量下，末尾一次舍入足以吸收中间的浮点误差。
     */
    public function test_add_absorbs_float_drift_across_many_terms(): void
    {
        $this->assertSame(10.0, Money::add(...array_fill(0, 1000, 0.01)));
        $this->assertSame(21.0, Money::add(...array_fill(0, 300, 0.07)));
    }

    public function test_divide_rejects_zero_divisor(): void
    {
        $this->expectException(\DivisionByZeroError::class);

        Money::divide(10.00, 0);
    }

    public function test_format_normalizes_to_two_decimals(): void
    {
        $this->assertSame('0.00', Money::format(0));
        $this->assertSame('1.50', Money::format(1.5));
        $this->assertSame('2.35', Money::format(2.346));
        $this->assertSame('2.34', Money::format(2.344));
    }
}
