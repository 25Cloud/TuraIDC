<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ProductConfigOptionPresenter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 区间配置项的呈现范围必须等于子项真实覆盖的包络。
 *
 * 上游常把配置项范围填成模板值（「系统盘 40~512」），而子项只有一个 40G 免费档。
 * 前台照声明范围渲染滑块，用户拖到 41 就因无档位覆盖而拿不到价格，
 * 下单直接报「超出可选范围」。
 */
class ProductConfigOptionRangeBoundsTest extends TestCase
{
    #[Test]
    public function declared_range_is_narrowed_to_sub_option_coverage(): void
    {
        $item = $this->presentOne([
            'name' => '系统盘',
            'field' => 'system_disk_size',
            'option_type' => 19,
            'qty_minimum' => 40,
            'qty_maximum' => 512,
            'qty_stage' => 1,
            'sub' => [
                ['id' => 101, 'option_name' => '40G', 'qty_minimum' => 40, 'qty_maximum' => 40, 'pricing' => []],
            ],
        ]);

        $this->assertSame(40, $item['qty_minimum']);
        $this->assertSame(40, $item['qty_maximum']);
    }

    #[Test]
    public function declared_range_equal_to_coverage_is_untouched(): void
    {
        $item = $this->presentOne([
            'name' => '系统盘',
            'field' => 'systemdisk',
            'option_type' => 19,
            'qty_minimum' => 15,
            'qty_maximum' => 400,
            'qty_stage' => 1,
            'sub' => [
                ['id' => 201, 'option_name' => '15GB', 'qty_minimum' => 15, 'qty_maximum' => 15, 'pricing' => []],
                ['id' => 202, 'option_name' => '额外系统盘', 'qty_minimum' => 16, 'qty_maximum' => 400, 'pricing' => ['monthly' => '0.30']],
            ],
        ]);

        $this->assertSame(15, $item['qty_minimum']);
        $this->assertSame(400, $item['qty_maximum']);
    }

    #[Test]
    public function zero_quantity_placeholder_tier_does_not_shrink_coverage(): void
    {
        // 「0GB 免费」是零数量占位档，不是无上限档位，也不该把范围压成 0。
        $item = $this->presentOne([
            'name' => '数据盘',
            'field' => 'datadisk',
            'option_type' => 15,
            'qty_minimum' => 0,
            'qty_maximum' => 300,
            'qty_stage' => 10,
            'sub' => [
                ['id' => 301, 'option_name' => '0GB', 'qty_minimum' => 0, 'qty_maximum' => 0, 'pricing' => []],
                ['id' => 302, 'option_name' => '额外数据盘', 'qty_minimum' => 1, 'qty_maximum' => 300, 'pricing' => ['monthly' => '3.00']],
            ],
        ]);

        $this->assertSame(0, $item['qty_minimum']);
        $this->assertSame(300, $item['qty_maximum']);
    }

    #[Test]
    public function open_ended_tier_keeps_parent_upper_bound(): void
    {
        $item = $this->presentOne([
            'name' => '流量',
            'field' => 'flow_limit',
            'option_type' => 15,
            'qty_minimum' => 0,
            'qty_maximum' => 500,
            'qty_stage' => 1,
            'sub' => [
                ['id' => 401, 'option_name' => '额外流量', 'qty_minimum' => 3, 'qty_maximum' => 0, 'pricing' => ['monthly' => '3.00']],
            ],
        ]);

        $this->assertSame(500, $item['qty_maximum']);
    }

    #[Test]
    public function missing_parent_upper_bound_falls_back_to_sub_option_max(): void
    {
        $item = $this->presentOne([
            'name' => '数据盘',
            'field' => 'data_disk_size',
            'option_type' => 14,
            'qty_minimum' => 0,
            'qty_maximum' => 0,
            'qty_stage' => 1,
            'sub' => [
                ['id' => 501, 'option_name' => '数据盘', 'qty_minimum' => 0, 'qty_maximum' => 500, 'pricing' => ['monthly' => '1.00']],
            ],
        ]);

        $this->assertSame(0, $item['qty_minimum']);
        $this->assertSame(500, $item['qty_maximum']);
    }

    #[Test]
    public function all_placeholder_tiers_keep_declared_range(): void
    {
        // 档位全是 0/0 时没有第二判据，上游把范围写在父项上：
        // 这类配置项的范围必须原样保留，不能被收缩成 0。
        $item = $this->presentOne([
            'name' => '系统盘',
            'field' => 'system_disk_size',
            'option_type' => 19,
            'qty_minimum' => 40,
            'qty_maximum' => 1000,
            'qty_stage' => 1,
            'sub' => [
                ['id' => 601, 'option_name' => '系统盘', 'qty_minimum' => 0, 'qty_maximum' => 0, 'pricing' => []],
                ['id' => 602, 'option_name' => '系统盘扩容', 'qty_minimum' => 0, 'qty_maximum' => 0, 'pricing' => ['monthly' => '1.00']],
            ],
        ]);

        $this->assertSame(40, $item['qty_minimum']);
        $this->assertSame(1000, $item['qty_maximum']);
    }

    #[Test]
    public function free_only_placeholder_group_keeps_declared_range(): void
    {
        // 整组只有一个不带单价的 0/0 档位：上游把范围写在父项上、这项不单卖，
        // 父项声明必须原样保留（收缩成 0 或 1..0 都会让前台滑块失效）。
        $item = $this->presentOne([
            'name' => 'IP数量',
            'field' => 'ip_num',
            'option_type' => 15,
            'qty_minimum' => 1,
            'qty_maximum' => 5,
            'qty_stage' => 1,
            'sub' => [
                ['id' => 701, 'option_name' => 'IP', 'qty_minimum' => 0, 'qty_maximum' => 0, 'pricing' => []],
            ],
        ]);

        $this->assertSame(1, $item['qty_minimum']);
        $this->assertSame(5, $item['qty_maximum']);
    }

    #[Test]
    public function zero_quantity_placeholder_identifier(): void
    {
        // 组内已有有界档位时，0/0 是零数量占位。
        $this->assertFalse(ProductConfigOptionPresenter::isSubRangeOpenEnded(['qty_minimum' => 0, 'qty_maximum' => 0]));
        // 组内没有有界档位时，0/0 承担整段父项范围。
        $this->assertTrue(ProductConfigOptionPresenter::isSubRangeOpenEnded(['qty_minimum' => 0, 'qty_maximum' => 0], false));
        // 起点大于 0 的 0 上限永远是无上限档位。
        $this->assertTrue(ProductConfigOptionPresenter::isSubRangeOpenEnded(['qty_minimum' => 3, 'qty_maximum' => 0]));
        $this->assertFalse(ProductConfigOptionPresenter::isSubRangeOpenEnded(['qty_minimum' => 1, 'qty_maximum' => 300]));

        $this->assertTrue(ProductConfigOptionPresenter::hasBoundedRangeTier([
            ['qty_minimum' => 1, 'qty_maximum' => 300, 'pricing' => []],
        ]));
        $this->assertFalse(ProductConfigOptionPresenter::hasBoundedRangeTier([
            ['qty_minimum' => 0, 'qty_maximum' => 0, 'pricing' => ['monthly' => '1.00']],
        ]));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function presentOne(array $item): array
    {
        $items = ProductConfigOptionPresenter::present([$item]);

        return $items[0];
    }
}
