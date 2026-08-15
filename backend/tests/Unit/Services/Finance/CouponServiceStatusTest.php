<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Finance;

use App\Constants\CouponStatus;
use App\Constants\UserCouponStatus;
use App\Models\Coupon;
use App\Models\User;
use App\Models\UserCoupon;
use App\Services\Finance\CouponService;
use Tests\TestCase;

class CouponServiceStatusTest extends TestCase
{
    public function test_used_user_coupon_returns_used_up_status(): void
    {
        [$user] = $this->createOwnedCoupon(UserCouponStatus::USED, [
            'used_at' => now()->subMinute(),
            'last_used_at' => now()->subMinute(),
        ]);

        $items = app(CouponService::class)->paginateForUser($user, [], 1, 15)['list'];

        $this->assertSame('used_up', $items[0]['status']);
        $this->assertSame('已使用', $items[0]['status_label']);
    }

    public function test_revoked_user_coupon_returns_expired_status(): void
    {
        [$user] = $this->createOwnedCoupon(UserCouponStatus::REVOKED, [
            'revoked_at' => now()->subMinute(),
        ]);

        $items = app(CouponService::class)->paginateForUser($user, [], 1, 15)['list'];

        $this->assertSame('expired', $items[0]['status']);
        $this->assertSame('已作废', $items[0]['status_label']);
    }

    private function createOwnedCoupon(int $status, array $userCouponOverrides = []): array
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'coupon-status-'.$suffix.'@example.com',
            'password' => 'secret123',
            'phone' => '135'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $coupon = Coupon::query()->create([
            'name' => 'Status Coupon '.$suffix,
            'code' => 'STAT'.strtoupper($suffix),
            'distribution_type' => 'public',
            'discount_scope' => 'first_month',
            'discount_type' => 'fixed',
            'discount_value' => '5.00',
            'min_amount' => '0.00',
            'used_count' => 0,
            'status' => CouponStatus::ACTIVE,
            'starts_at' => now()->subHour(),
            'expires_at' => now()->addDay(),
        ]);

        $userCoupon = UserCoupon::query()->create(array_merge([
            'coupon_id' => (int) $coupon->id,
            'user_id' => (int) $user->id,
            'receive_type' => 'claim',
            'status' => $status,
            'claimed_at' => now(),
        ], $userCouponOverrides));

        return [$user, $coupon, $userCoupon];
    }
}
