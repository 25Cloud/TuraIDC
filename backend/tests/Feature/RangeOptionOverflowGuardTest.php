<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Services\Order\Concerns\HandlesOrderCalculation;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RangeOptionOverflowGuardTest extends TestCase
{
    #[Test]
    public function range_option_throws_when_value_exceeds_all_steps_without_open_ended_tier(): void
    {
        $probe = new CalculationProbe;

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('配置项「CPU 核心数」超出可选范围');

        $probe->probeRangeOption([
            'name' => 'CPU 核心数',
            'field' => 'cpu',
            'qty_minimum' => 1,
            'qty_stage' => 1,
            'sub' => [
                ['qty_minimum' => 1, 'qty_maximum' => 4, 'pricing' => ['monthly' => '10.00']],
                ['qty_minimum' => 5, 'qty_maximum' => 8, 'pricing' => ['monthly' => '20.00']],
            ],
        ], 'monthly', ['cpu' => 999999], 'cpu');
    }

    #[Test]
    public function range_option_charges_normally_when_open_ended_tier_exists(): void
    {
        $probe = new CalculationProbe;

        $result = $probe->probeRangeOption([
            'name' => 'IP 数量',
            'field' => 'ip_num',
            'qty_minimum' => 1,
            'qty_stage' => 1,
            'sub' => [
                ['qty_minimum' => 1, 'qty_maximum' => 2, 'pricing' => ['monthly' => '5.00']],
                // 无上限兜底段（qty_maximum = 0）
                ['qty_minimum' => 3, 'qty_maximum' => 0, 'pricing' => ['monthly' => '3.00']],
            ],
        ], 'monthly', ['ip_num' => 10], 'ip_num');

        $this->assertGreaterThan(0, $result['amount']);
        $this->assertSame(10, $result['selected_value']);
    }

    #[Test]
    public function range_option_charges_within_defined_steps(): void
    {
        $probe = new CalculationProbe;

        $result = $probe->probeRangeOption([
            'name' => '内存',
            'field' => 'memory',
            'qty_minimum' => 1,
            'qty_stage' => 1,
            'sub' => [
                ['qty_minimum' => 1, 'qty_maximum' => 4, 'pricing' => ['monthly' => '8.00']],
                ['qty_minimum' => 5, 'qty_maximum' => 8, 'pricing' => ['monthly' => '15.00']],
            ],
        ], 'monthly', ['memory' => 4], 'memory');

        $this->assertSame(32.0, $result['amount']);
        $this->assertSame(4, $result['selected_value']);
    }

    #[Test]
    public function range_option_without_visible_steps_keeps_zero_amount(): void
    {
        $probe = new CalculationProbe;

        $result = $probe->probeRangeOption([
            'name' => '带宽',
            'field' => 'bw',
            'qty_minimum' => 1,
            'qty_stage' => 1,
            'sub' => [],
        ], 'monthly', ['bw' => 100], 'bw');

        $this->assertSame(0.0, $result['amount']);
    }
}

final class CalculationProbe
{
    use HandlesOrderCalculation;

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function probeRangeOption(array $item, string $billingCycle, array $config, string $field): array
    {
        return $this->calculateRangeOptionExtraDetail($item, $billingCycle, $config, $field);
    }
}
