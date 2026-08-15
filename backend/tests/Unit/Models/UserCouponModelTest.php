<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Constants\CouponStatus;
use App\Constants\UserCouponStatus;
use App\Models\Coupon;
use App\Models\User;
use App\Models\UserCoupon;
use Tests\TestCase;

class UserCouponModelTest extends TestCase
{
    public function test_it_generates_uid_and_default_owned_status_when_created(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createUser($suffix);
        $coupon = $this->createCoupon($suffix);

        $userCoupon = UserCoupon::query()->create([
            'coupon_id' => (int) $coupon->id,
            'user_id' => (int) $user->id,
        ]);

        $this->assertMatchesRegularExpression('/^uc_[0-9a-f]{12}$/', (string) $userCoupon->uid);
        $this->assertSame(UserCouponStatus::OWNED, (int) $userCoupon->status);
    }

    private function createUser(string $suffix): User
    {
        return User::query()->create([
            'email' => 'user-coupon-model-'.$suffix.'@example.com',
            'password' => 'secret123',
            'phone' => '139'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);
    }

    private function createCoupon(string $suffix): Coupon
    {
        return Coupon::query()->create([
            'name' => 'Model Coupon '.$suffix,
            'code' => 'MODEL'.strtoupper($suffix),
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
