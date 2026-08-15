<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Constants\CouponStatus;
use App\Constants\UserCouponStatus;
use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\User;
use App\Models\UserCoupon;
use App\Services\Finance\CouponService;
use Tests\TestCase;

class CheckoutConcurrencyTest extends TestCase
{
    public function test_reserved_coupon_cannot_be_reused_by_second_checkout(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'checkout-concurrency-'.$suffix.'@example.com',
            'password' => 'secret123',
            'phone' => '136'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);
        $product = Product::query()->create([
            'name' => 'Concurrency Product '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '20.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);
        $coupon = Coupon::query()->create([
            'name' => 'Concurrency Coupon '.$suffix,
            'code' => 'CONC'.strtoupper($suffix),
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
        $userCoupon = UserCoupon::query()->create([
            'coupon_id' => (int) $coupon->id,
            'user_id' => (int) $user->id,
            'receive_type' => 'claim',
            'status' => UserCouponStatus::OWNED,
            'claimed_at' => now(),
        ]);

        $service = app(CouponService::class);
        $service->reserveOwnedCouponForInvoice((int) $userCoupon->id, (int) $user->id, $product, 'monthly', 20.0);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('优惠券不存在或已失效');

        $service->reserveOwnedCouponForInvoice((int) $userCoupon->id, (int) $user->id, $product, 'monthly', 20.0);
    }
}
