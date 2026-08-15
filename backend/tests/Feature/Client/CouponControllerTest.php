<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use App\Constants\CouponStatus;
use App\Constants\UserCouponStatus;
use App\Models\Coupon;
use App\Models\User;
use App\Models\UserCoupon;
use Tests\TestCase;

class CouponControllerTest extends TestCase
{
    public function test_owned_coupon_response_includes_uid_and_usage_timestamps(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'coupon-controller-'.$suffix.'@example.com',
            'password' => 'secret123',
            'phone' => '133'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $usedCoupon = $this->createCoupon('used-'.$suffix);
        $revokedCoupon = $this->createCoupon('revoked-'.$suffix);
        $usedAt = now()->subHour();
        $revokedAt = now()->subMinute();

        $usedUserCoupon = UserCoupon::query()->create([
            'coupon_id' => (int) $usedCoupon->id,
            'user_id' => (int) $user->id,
            'receive_type' => 'claim',
            'status' => UserCouponStatus::USED,
            'claimed_at' => now()->subDay(),
            'used_at' => $usedAt,
            'last_used_at' => $usedAt,
        ]);
        $revokedUserCoupon = UserCoupon::query()->create([
            'coupon_id' => (int) $revokedCoupon->id,
            'user_id' => (int) $user->id,
            'receive_type' => 'claim',
            'status' => UserCouponStatus::REVOKED,
            'claimed_at' => now()->subDay(),
            'revoked_at' => $revokedAt,
        ]);

        $token = $user->createToken('coupon-controller-test')->plainTextToken;
        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v2/client/coupons?status=all&page=1&page_size=10');

        $response->assertOk()->assertJsonPath('code', 0);
        $items = collect($response->json('data.list'));
        $usedItem = $items->firstWhere('id', (int) $usedUserCoupon->id);
        $revokedItem = $items->firstWhere('id', (int) $revokedUserCoupon->id);

        $this->assertSame((string) $usedUserCoupon->uid, $usedItem['uid'] ?? null);
        $this->assertNotEmpty($usedItem['used_at'] ?? null);
        $this->assertSame((string) $revokedUserCoupon->uid, $revokedItem['uid'] ?? null);
        $this->assertNotEmpty($revokedItem['revoked_at'] ?? null);
    }

    private function createCoupon(string $suffix): Coupon
    {
        return Coupon::query()->create([
            'name' => 'Controller Coupon '.$suffix,
            'code' => 'CTRL'.strtoupper(str_replace('-', '', $suffix)),
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
    }
}
