<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\CouponStatus;
use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\CouponCampaign;
use App\Services\Finance\CouponCampaignService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class CouponCampaignDispatchRegressionTest extends TestCase
{
    public function test_scheduled_dispatch_is_skipped_when_campaign_already_dispatched_today(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $timezone = config('app.timezone');
        // Simulate 08:30 after a manual dispatch at 06:00 on the same day.
        $now = CarbonImmutable::now($timezone)->setTime(8, 30, 0);
        $todayWeekday = (int) $now->dayOfWeek;
        $manualDispatchedAt = $now->setTime(6, 0, 0);

        $campaign = CouponCampaign::query()->create($this->campaignPayload($suffix, [
            'weekdays' => [$todayWeekday],
            'trigger_time' => '08:00:00',
            'last_dispatched_at' => $manualDispatchedAt->format('Y-m-d H:i:s'),
        ]));
        $campaignId = (int) $campaign->id;

        app(CouponCampaignService::class)->dispatchDueCampaigns($now);

        $this->assertSame(
            0,
            Coupon::query()->where('coupon_campaign_id', $campaignId)->count(),
            'Same-day scheduled dispatch should be skipped when already dispatched manually.'
        );
        $this->assertSame(
            $manualDispatchedAt->format('Y-m-d H:i:s'),
            $campaign->fresh()->last_dispatched_at?->format('Y-m-d H:i:s'),
            'Scheduled dispatch skip should not rewrite last_dispatched_at.'
        );
    }

    public function test_scheduled_dispatch_runs_when_campaign_not_dispatched_today(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $timezone = config('app.timezone');
        $now = CarbonImmutable::now($timezone)->setTime(8, 30, 0);
        $todayWeekday = (int) $now->dayOfWeek;
        // The last dispatch happened yesterday, so today's scheduled dispatch should still run.
        $yesterdayDispatchedAt = $now->subDay()->setTime(8, 0, 0);

        $campaign = CouponCampaign::query()->create($this->campaignPayload($suffix, [
            'weekdays' => [$todayWeekday],
            'trigger_time' => '08:00:00',
            'last_dispatched_at' => $yesterdayDispatchedAt->format('Y-m-d H:i:s'),
        ]));
        $campaignId = (int) $campaign->id;

        app(CouponCampaignService::class)->dispatchDueCampaigns($now);

        $this->assertSame(
            1,
            Coupon::query()->where('coupon_campaign_id', $campaignId)->count(),
            'Scheduled dispatch should run when the campaign was not dispatched today.'
        );
    }

    public function test_manual_trigger_is_rejected_when_campaign_already_dispatched_today(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $timezone = config('app.timezone');
        $manualDispatchedAt = CarbonImmutable::now($timezone)->startOfDay()->setTime(6, 0, 0);
        $triggerNow = $manualDispatchedAt->setTime(8, 0, 0);
        CarbonImmutable::setTestNow($triggerNow);

        try {
            $campaign = CouponCampaign::query()->create($this->campaignPayload($suffix, [
                'weekdays' => [(int) $triggerNow->dayOfWeek],
                'trigger_time' => '08:00:00',
                'last_dispatched_at' => $manualDispatchedAt->format('Y-m-d H:i:s'),
            ]));

            try {
                app(CouponCampaignService::class)->triggerCampaign($campaign);
                $this->fail('Expected same-day manual campaign trigger to be rejected.');
            } catch (BusinessException) {
                $this->assertSame(
                    0,
                    Coupon::query()->where('coupon_campaign_id', (int) $campaign->id)->count()
                );
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_update_campaign_with_generated_coupon_is_rejected(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $campaign = CouponCampaign::query()->create($this->campaignPayload($suffix));

        Coupon::query()->create([
            'coupon_campaign_id' => (int) $campaign->id,
            'name' => 'Generated Campaign Coupon '.$suffix,
            'code' => 'GENCAMP'.strtoupper($suffix),
            'distribution_type' => 'public',
            'discount_scope' => 'first_month',
            'discount_type' => 'fixed',
            'discount_value' => '5.00',
            'min_amount' => '0.00',
            'status' => CouponStatus::ACTIVE,
            'starts_at' => now()->subHour(),
            'expires_at' => now()->addDay(),
        ]);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('活动已生成优惠券批次，不允许修改');

        app(CouponCampaignService::class)->updateCampaign(
            $campaign,
            $this->campaignPayload($suffix, ['name' => 'Updated Campaign '.$suffix])
        );
    }

    public function test_delete_campaign_with_generated_coupon_is_rejected(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $campaign = CouponCampaign::query()->create($this->campaignPayload($suffix));

        Coupon::query()->create([
            'coupon_campaign_id' => (int) $campaign->id,
            'name' => 'Generated Campaign Coupon '.$suffix,
            'code' => 'GENDEL'.strtoupper($suffix),
            'distribution_type' => 'public',
            'discount_scope' => 'first_month',
            'discount_type' => 'fixed',
            'discount_value' => '5.00',
            'min_amount' => '0.00',
            'status' => CouponStatus::ACTIVE,
            'starts_at' => now()->subHour(),
            'expires_at' => now()->addDay(),
        ]);

        try {
            app(CouponCampaignService::class)->deleteCampaign($campaign);
            $this->fail('Expected generated campaign deletion to be rejected.');
        } catch (BusinessException $exception) {
            $this->assertSame('活动已生成优惠券批次，不允许删除', $exception->getMessage());
            $this->assertDatabaseHas('coupon_campaigns', [
                'id' => (int) $campaign->id,
            ]);
        }
    }

    private function campaignPayload(string $suffix, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Campaign Dispatch '.$suffix,
            'description' => null,
            'weekdays' => [0, 1, 2, 3, 4, 5, 6],
            'trigger_time' => '08:00:00',
            'issue_quantity' => 10,
            'valid_duration_hours' => 24,
            'discount_scope' => 'first_month',
            'discount_type' => 'fixed',
            'discount_value' => '5.00',
            'min_amount' => '0.00',
            'max_discount_amount' => null,
            'billing_cycles' => [],
            'product_ids' => [],
            'first_order_only' => false,
            'per_user_limit' => null,
            'status' => CouponStatus::ACTIVE,
            'sort_order' => 0,
            'last_dispatched_at' => null,
            'last_coupon_id' => null,
            'remark' => null,
            'operator' => 'campaign-regression',
            'trace_id' => 'campaign-regression-'.$suffix,
        ], $overrides);
    }
}
