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
    public function zero_priced_placeholder_tier_does_not_shadow_later_priced_tier(): void
    {
        $probe = new CalculationProbe;

        // 上游把「首档免费」写成 qty_minimum=0、qty_maximum=0 的占位 sub（0GB），
        // 而 0 在这套计费里同时表示「无上限」，于是零价档位先命中就把 0 元返回了，
        // 后面真正的「额外数据盘 1..300、每 10GB 3 元」永远轮不到。
        // 结果就是加硬盘一律 0 元（生产实测 datadisk=10/20/100 全为 0.00）。
        $result = $probe->probeRangeOption([
            'name' => '数据盘',
            'field' => 'datadisk',
            'qty_minimum' => 0,
            'qty_stage' => 10,
            'sub' => [
                ['qty_minimum' => 0, 'qty_maximum' => 0, 'pricing' => []],
                ['qty_minimum' => 1, 'qty_maximum' => 300, 'pricing' => ['monthly' => '3.00']],
            ],
        ], 'monthly', ['datadisk' => 30], 'datadisk');

        // 30GB = 3 个 10GB 台阶 × 3.00
        $this->assertSame(9.0, $result['amount']);
        $this->assertSame(30, $result['selected_value']);
    }

    #[Test]
    public function zero_quantity_still_costs_nothing_when_only_free_tier_matches(): void
    {
        $probe = new CalculationProbe;

        $result = $probe->probeRangeOption([
            'name' => '数据盘',
            'field' => 'datadisk',
            'qty_minimum' => 0,
            'qty_stage' => 10,
            'sub' => [
                ['qty_minimum' => 0, 'qty_maximum' => 0, 'pricing' => []],
                ['qty_minimum' => 1, 'qty_maximum' => 300, 'pricing' => ['monthly' => '3.00']],
            ],
        ], 'monthly', ['datadisk' => 0], 'datadisk');

        $this->assertSame(0.0, $result['amount']);
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

    #[Test]
    public function value_in_tier_gap_inside_declared_range_is_priced_by_next_tier(): void
    {
        $probe = new CalculationProbe;

        // 上游把「系统盘 120G 免费」和「131~1024 每 G 0.8 元」之间的 121~130 留成了空隙。
        // 声明范围是 120~1024，前台滑块能拖到 121，此前会拿到「超出可选范围」而无法下单。
        $item = [
            'name' => '系统盘',
            'field' => 'system_disk_size',
            'qty_minimum' => 120,
            'qty_maximum' => 1024,
            'qty_stage' => 1,
            'sub' => [
                ['qty_minimum' => 120, 'qty_maximum' => 120, 'pricing' => []],
                ['qty_minimum' => 131, 'qty_maximum' => 1024, 'pricing' => ['monthly' => '0.80']],
            ],
        ];

        foreach ([121, 125, 130, 131] as $value) {
            $result = $probe->probeRangeOption($item, 'monthly', ['system_disk_size' => $value], 'system_disk_size');

            $this->assertSame(0.8, $result['amount'], "取值 {$value} 应按下一档计 1 个台阶");
            $this->assertSame($value, $result['selected_value']);
        }

        $free = $probe->probeRangeOption($item, 'monthly', ['system_disk_size' => 120], 'system_disk_size');
        $this->assertSame(0.0, $free['amount']);
    }

    #[Test]
    public function value_in_tier_gap_without_following_tier_charges_by_lower_tier(): void
    {
        $probe = new CalculationProbe;

        // 只有 1~100 一档，声明范围却到 200：101~200 无档位覆盖，
        // 既不能 0 元放行，也不能让用户下不了单。
        $result = $probe->probeRangeOption([
            'name' => '数据盘',
            'field' => 'data_disk_size',
            'qty_minimum' => 1,
            'qty_maximum' => 200,
            'qty_stage' => 1,
            'sub' => [
                ['qty_minimum' => 1, 'qty_maximum' => 100, 'pricing' => ['monthly' => '1.00']],
            ],
        ], 'monthly', ['data_disk_size' => 150], 'data_disk_size');

        $this->assertSame(150.0, $result['amount']);
    }

    #[Test]
    public function value_beyond_declared_range_still_throws_with_zero_placeholder_tier_present(): void
    {
        $probe = new CalculationProbe;

        // 0/0 的「0GB 免费」占位档不是无上限档位（同一配置项里已有 1..300 的有界档位）。
        // 若按无上限处理，它会把任何数量都当成命中零价档，溢出守卫失效
        // ——数据盘填 999 就能 0 元拿到 999GB。
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('配置项「数据盘」超出可选范围');

        $probe->probeRangeOption([
            'name' => '数据盘',
            'field' => 'datadisk',
            'qty_minimum' => 0,
            'qty_maximum' => 300,
            'qty_stage' => 10,
            'sub' => [
                ['qty_minimum' => 0, 'qty_maximum' => 0, 'pricing' => []],
                ['qty_minimum' => 1, 'qty_maximum' => 300, 'pricing' => ['monthly' => '3.00']],
            ],
        ], 'monthly', ['datadisk' => 999], 'datadisk');
    }

    #[Test]
    public function free_only_placeholder_group_keeps_legacy_free_reading(): void
    {
        $probe = new CalculationProbe;

        // 整组只有不带单价的 0/0 档位、组内没有有界档位时，它承担整段父项范围，
        // 按 0 元（与引入本条判据前一致）——上游把范围写在父项上、这项不单卖。
        $result = $probe->probeRangeOption([
            'name' => 'IP数量',
            'field' => 'ip_num',
            'qty_minimum' => 1,
            'qty_maximum' => 5,
            'qty_stage' => 1,
            'sub' => [
                ['qty_minimum' => 0, 'qty_maximum' => 0, 'pricing' => []],
            ],
        ], 'monthly', ['ip_num' => 5], 'ip_num');

        $this->assertSame(0.0, $result['amount']);
    }

    #[Test]
    public function open_ended_reading_is_still_capped_by_declared_range(): void
    {
        $probe = new CalculationProbe;

        // 同样的「IP数量 1~5 + 0/0 免费档」，填 6 已越过父项声明的可选范围，
        // 不能被无上限读法放行成免费。
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('配置项「IP数量」超出可选范围');

        $probe->probeRangeOption([
            'name' => 'IP数量',
            'field' => 'ip_num',
            'qty_minimum' => 1,
            'qty_maximum' => 5,
            'qty_stage' => 1,
            'sub' => [
                ['qty_minimum' => 0, 'qty_maximum' => 0, 'pricing' => []],
            ],
        ], 'monthly', ['ip_num' => 6], 'ip_num');
    }

    #[Test]
    public function all_placeholder_tiers_fall_back_to_open_ended_reading(): void
    {
        $probe = new CalculationProbe;

        // 全部档位都是 0/0（上游把范围写在父项声明里，档位只给单价）时，
        // 0/0 不能被读成「只支持 0」，否则整个区间会变成下不了单。
        $item = [
            'name' => '系统盘',
            'field' => 'system_disk_size',
            'qty_minimum' => 40,
            'qty_maximum' => 1000,
            'qty_stage' => 1,
            'sub' => [
                ['qty_minimum' => 0, 'qty_maximum' => 0, 'pricing' => []],
                ['qty_minimum' => 0, 'qty_maximum' => 0, 'pricing' => ['monthly' => '1.00']],
            ],
        ];

        $result = $probe->probeRangeOption($item, 'monthly', ['system_disk_size' => 500], 'system_disk_size');

        $this->assertSame(500.0, $result['amount']);
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
