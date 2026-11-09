<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\System\SettingService;
use Tests\TestCase;

/**
 * 流量包加价：流量包不走上游代理折扣，上游价就是成本，售价 = 成本 + 加价。
 */
class TrafficPackageMarkupTest extends TestCase
{
    public function test_none_markup_keeps_cost_as_price(): void
    {
        $this->assertSame(10.0, SettingService::applyTrafficPackageMarkup(10, 'none', 30));
    }

    public function test_percent_markup_adds_percentage_of_cost(): void
    {
        $this->assertSame(12.0, SettingService::applyTrafficPackageMarkup(10, 'percent', 20));
        $this->assertSame(11.49, SettingService::applyTrafficPackageMarkup(9.99, 'percent', 15));
    }

    public function test_fixed_markup_adds_flat_amount(): void
    {
        $this->assertSame(15.0, SettingService::applyTrafficPackageMarkup(10, 'fixed', 5));
        $this->assertSame(5.5, SettingService::applyTrafficPackageMarkup(2.5, 'fixed', 3));
    }

    public function test_zero_cost_stays_zero(): void
    {
        $this->assertSame(0.0, SettingService::applyTrafficPackageMarkup(0, 'percent', 50));
        $this->assertSame(0.0, SettingService::applyTrafficPackageMarkup(-8, 'fixed', 5));
    }

    public function test_negative_markup_value_is_clamped_to_zero(): void
    {
        $this->assertSame(10.0, SettingService::applyTrafficPackageMarkup(10, 'percent', -50));
        $this->assertSame(10.0, SettingService::applyTrafficPackageMarkup(10, 'fixed', -5));
    }
}
