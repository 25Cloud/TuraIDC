<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Constants\BillingCycle;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * 计费周期真源。与 shared/tests/billing-cycle.test.mjs 一一对应，两侧口径必须一致。
 */
class BillingCycleTest extends TestCase
{
    public function test_aliases_are_normalized(): void
    {
        $this->assertSame('annually', BillingCycle::normalize('YEARLY'));
        $this->assertSame('one_time', BillingCycle::normalize(' onetime '));
        $this->assertSame('one_time', BillingCycle::normalize('one-time'));
        // biannually 是早期前端把 semiannually 拼错留下的脏数据，只做读取兼容
        $this->assertSame('semiannually', BillingCycle::normalize('biannually'));
        $this->assertSame('', BillingCycle::normalize(null));
    }

    public function test_labels_cover_every_value_that_reaches_the_database(): void
    {
        $this->assertSame('月付', BillingCycle::label('monthly'));
        $this->assertSame('半年付', BillingCycle::label('semiannually'));
        // 收敛前这三个在部分页面显示英文原文
        $this->assertSame('年付', BillingCycle::label('yearly'));
        $this->assertSame('一次性', BillingCycle::label('onetime'));
        $this->assertSame('免费', BillingCycle::label('free'));
        $this->assertSame('半年付', BillingCycle::label('biannually'));

        $this->assertSame('--', BillingCycle::label('weird_cycle', '--'));
        $this->assertSame('weird_cycle', BillingCycle::label('weird_cycle'));
        $this->assertSame('--', BillingCycle::label('', '--'));
    }

    public function test_months_returns_null_for_unknown_cycles(): void
    {
        $this->assertSame(3, BillingCycle::months('quarterly'));
        $this->assertSame(12, BillingCycle::months('yearly'));
        $this->assertSame(36, BillingCycle::months('triennially'));
        $this->assertSame(0, BillingCycle::months('one_time'));
        $this->assertSame(0, BillingCycle::months('free'));
        // null 而非 0：调用方要能区分「0 个月」与「不认识这个周期」
        $this->assertNull(BillingCycle::months('weird_cycle'));
    }

    public function test_unknown_cycles_sort_after_every_known_cycle(): void
    {
        $this->assertSame(0, BillingCycle::sortRank('monthly'));
        $this->assertGreaterThan(BillingCycle::sortRank('quarterly'), BillingCycle::sortRank('annually'));
        $this->assertSame(count(BillingCycle::ORDER), BillingCycle::sortRank('weird_cycle'));
        $this->assertGreaterThan(BillingCycle::sortRank('free'), BillingCycle::sortRank('weird_cycle'));
    }

    /**
     * 到期推进必须夹住月末，不能溢出。
     *
     * Carbon 的 addMonth()/addYear() 默认允许溢出：2026-01-31 addMonth() 得到 2026-03-03、
     * addMonths(3) 得到 2026-05-01、2024-02-29 addYear() 得到 2025-03-01。
     * 用在到期日上，「月付」会直接跳过 2 月，到期日每期向后漂移，账期与月份对不上。
     * 这几条断言同时钉住「用的是 NoOverflow 变体」和「与前端 addMonthsClamped 同口径」。
     */
    public function test_advance_clamps_to_month_end_instead_of_overflowing(): void
    {
        $jan31 = Carbon::parse('2026-01-31 13:45:30');

        $this->assertSame('2026-02-28', BillingCycle::advance($jan31, 'monthly')->toDateString());
        $this->assertSame('2026-04-30', BillingCycle::advance($jan31, 'quarterly')->toDateString());
        $this->assertSame('2026-07-31', BillingCycle::advance($jan31, 'semiannually')->toDateString());
        $this->assertSame('2027-01-31', BillingCycle::advance($jan31, 'annually')->toDateString());
        $this->assertSame('2027-01-31', BillingCycle::advance($jan31, 'yearly')->toDateString());
        $this->assertSame('2029-01-31', BillingCycle::advance($jan31, 'triennially')->toDateString());

        // 溢出语义的对照：若实现回退成 addMonths()，下面这个值会变成 2026-03-03
        $this->assertNotSame('2026-03-03', BillingCycle::advance($jan31, 'monthly')->toDateString());

        // 闰日跨年同样夹住
        $leapDay = Carbon::parse('2024-02-29 00:00:00');
        $this->assertSame('2025-02-28', BillingCycle::advance($leapDay, 'annually')->toDateString());
        $this->assertSame('2026-02-28', BillingCycle::advance($leapDay, 'biennially')->toDateString());

        // 时间部分保留，入参不被就地修改
        $this->assertSame('13:45:30', BillingCycle::advance($jan31, 'monthly')->format('H:i:s'));
        $this->assertSame('2026-01-31 13:45:30', $jan31->format('Y-m-d H:i:s'));
    }

    public function test_advance_returns_null_for_cycles_without_renewal(): void
    {
        $base = Carbon::parse('2026-01-31');

        $this->assertNull(BillingCycle::advance($base, 'one_time'));
        $this->assertNull(BillingCycle::advance($base, 'onetime'));
        $this->assertNull(BillingCycle::advance($base, 'free'));
        $this->assertNull(BillingCycle::advance($base, 'weird_cycle'));
        $this->assertNull(BillingCycle::advance($base, null));
    }

    /**
     * 白名单刻意窄于标签表：它是「业务允许的取值」，不是「任何已落库的值该怎么显示」。
     */
    public function test_renewable_subset_is_narrower_than_the_label_table(): void
    {
        $this->assertSame(
            ['monthly', 'quarterly', 'semiannually', 'annually'],
            BillingCycle::RENEWABLE
        );
        $this->assertLessThan(count(BillingCycle::LABELS), count(BillingCycle::RENEWABLE));

        $this->assertSame(
            ['monthly' => '月付', 'quarterly' => '季付', 'semiannually' => '半年付', 'annually' => '年付'],
            BillingCycle::RENEWABLE_LABELS
        );
        $this->assertSame(
            ['monthly' => 1, 'quarterly' => 3, 'semiannually' => 6, 'annually' => 12],
            BillingCycle::RENEWABLE_MONTHS
        );

        // 派生常量必须真的取自 LABELS / MONTHS，而不是又抄了一份
        foreach (BillingCycle::RENEWABLE as $cycle) {
            $this->assertSame(BillingCycle::LABELS[$cycle], BillingCycle::RENEWABLE_LABELS[$cycle]);
            $this->assertSame(BillingCycle::MONTHS[$cycle], BillingCycle::RENEWABLE_MONTHS[$cycle]);
        }
    }

    public function test_label_and_months_tables_cover_the_same_cycles(): void
    {
        $this->assertSame(array_keys(BillingCycle::LABELS), BillingCycle::ORDER);
        $this->assertSame(array_keys(BillingCycle::MONTHS), BillingCycle::ORDER);
    }

    /**
     * @return void
     */
    public function test_labels_for_and_months_for_keep_the_requested_order(): void
    {
        $this->assertSame(
            ['annually' => '年付', 'monthly' => '月付'],
            BillingCycle::labelsFor(['annually', 'weird_cycle', 'monthly'])
        );
        $this->assertSame(
            ['annually' => 12, 'monthly' => 1],
            BillingCycle::monthsFor(['annually', 'weird_cycle', 'monthly'])
        );
    }
}
