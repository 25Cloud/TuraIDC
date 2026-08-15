<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AutomationScheduleExpression;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AutomationScheduleExpressionTest extends TestCase
{
    #[Test]
    public function it_resolves_hourly_expression_from_heartbeat_aligned_minute(): void
    {
        $expression = AutomationScheduleExpression::resolve(
            AutomationScheduleExpression::MODE_HOURLY,
            '00:15:00'
        );

        $this->assertSame('15 * * * *', $expression);
        $this->assertSame('每小时第 15 分钟', AutomationScheduleExpression::describe(
            AutomationScheduleExpression::MODE_HOURLY,
            '00:15:00'
        ));
    }

    #[Test]
    public function it_resolves_daily_expression_from_configured_time(): void
    {
        $expression = AutomationScheduleExpression::resolve(
            AutomationScheduleExpression::MODE_DAILY,
            '02:15:00'
        );

        $this->assertSame('15 2 * * *', $expression);
        $this->assertSame('每天 02:15', AutomationScheduleExpression::describe(
            AutomationScheduleExpression::MODE_DAILY,
            '02:15:00'
        ));
    }

    #[Test]
    public function it_falls_back_to_default_mode_and_time_when_value_is_invalid(): void
    {
        $expression = AutomationScheduleExpression::resolve(
            'invalid-mode',
            'bad-time',
            AutomationScheduleExpression::MODE_HOURLY,
            '00:30:00'
        );

        $this->assertSame('30 * * * *', $expression);
    }

    #[Test]
    public function it_rejects_schedule_times_outside_the_fifteen_minute_heartbeat_grid(): void
    {
        $this->assertFalse(AutomationScheduleExpression::isHeartbeatAlignedTime('00:05:00'));
        $this->assertFalse(AutomationScheduleExpression::isHeartbeatAlignedTime('00:15:30'));
        $this->assertTrue(AutomationScheduleExpression::isHeartbeatAlignedTime('23:45:00'));
        $this->assertSame('00:00:00', AutomationScheduleExpression::normalizeTime('00:05:00'));
        $this->assertNotContains('every_five_minutes', AutomationScheduleExpression::modes());
    }
}
