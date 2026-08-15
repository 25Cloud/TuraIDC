<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Automation\Heartbeat\Rules\CronRule;
use App\Services\Automation\Heartbeat\ScheduleRule;
use App\Services\Automation\Heartbeat\TickSlot;
use App\Support\AutomationScheduleExpression;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class HeartbeatScheduleRuleTest extends TestCase
{
    public function test_tick_slot_floors_to_fifteen_minute_boundary(): void
    {
        $slot = TickSlot::floorToFifteenMinutes(CarbonImmutable::parse('2026-07-05 12:14:59', 'Asia/Shanghai'));

        $this->assertSame('2026-07-05 12:00:00', $slot->format('Y-m-d H:i:s'));
        $this->assertSame(49, TickSlot::dailyIndex($slot));
    }

    public function test_every_ticks_rule_matches_by_global_tick_number(): void
    {
        $rule = ScheduleRule::everyTicks(2);
        $first = TickSlot::context(null, CarbonImmutable::parse('2026-07-05 12:00:00', 'Asia/Shanghai'));
        $second = TickSlot::context(null, CarbonImmutable::parse('2026-07-05 12:15:00', 'Asia/Shanghai'));

        $this->assertNotSame($rule->isDue($first), $rule->isDue($second));
        $this->assertTrue($rule->isDue($first) || $rule->isDue($second));
    }

    public function test_daily_tick_rule_matches_daily_index(): void
    {
        $tick = TickSlot::context(null, CarbonImmutable::parse('2026-07-05 12:00:00', 'Asia/Shanghai'));

        $this->assertTrue(ScheduleRule::dailyTick(49)->isDue($tick));
        $this->assertFalse(ScheduleRule::dailyTick(50)->isDue($tick));
    }

    public function test_cron_rule_matches_only_due_heartbeat_slot(): void
    {
        $rule = new CronRule('0 12 * * *');

        $this->assertTrue($rule->isDue(TickSlot::context(null, CarbonImmutable::parse('2026-07-05 12:00:00', 'Asia/Shanghai'))));
        $this->assertFalse($rule->isDue(TickSlot::context(null, CarbonImmutable::parse('2026-07-05 12:15:00', 'Asia/Shanghai'))));
    }

    public function test_unsupported_sub_fifteen_minute_modes_fall_back_to_the_declared_default(): void
    {
        $rule = ScheduleRule::automation(
            'every_ten_minutes',
            '00:00:00',
            AutomationScheduleExpression::MODE_HOURLY,
            '00:00:00',
        );

        $this->assertSame('0 * * * *', $rule->describe());
        $this->assertFalse($rule->isDue(TickSlot::context(null, CarbonImmutable::parse('2026-07-05 12:15:00', 'Asia/Shanghai'))));
    }
}
