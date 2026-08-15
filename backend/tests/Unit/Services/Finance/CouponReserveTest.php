<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Finance;

use App\Constants\CouponStatus;
use App\Constants\InvoiceStatus;
use App\Constants\UserCouponStatus;
use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Models\UserCoupon;
use App\Services\Finance\CouponService;
use Tests\TestCase;

class CouponReserveTest extends TestCase
{
    public function test_reserve_sets_reserved_until_and_blocks_repeat_reserve(): void
    {
        [$user, $product, $userCoupon] = $this->createReserveFixture();
        $service = app(CouponService::class);

        $payload = $service->reserveOwnedCouponForInvoice((int) $userCoupon->id, (int) $user->id, $product, 'monthly', 20.0);

        $this->assertSame((int) $userCoupon->id, (int) $payload['user_coupon_id']);
        $this->assertNotNull($userCoupon->fresh()->reserved_until);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('优惠券不存在或已失效');

        $service->reserveOwnedCouponForInvoice((int) $userCoupon->id, (int) $user->id, $product, 'monthly', 20.0);
    }

    public function test_expired_reservation_can_be_reserved_again(): void
    {
        [$user, $product, $userCoupon] = $this->createReserveFixture([
            'reserved_until' => now()->subMinute(),
        ]);

        $payload = app(CouponService::class)
            ->reserveOwnedCouponForInvoice((int) $userCoupon->id, (int) $user->id, $product, 'monthly', 20.0);

        $this->assertSame((int) $userCoupon->id, (int) $payload['user_coupon_id']);
        $this->assertTrue($userCoupon->fresh()->reserved_until->isFuture());
    }

    public function test_paid_invoice_sync_clears_reserved_until(): void
    {
        [$user, , $userCoupon, $coupon] = $this->createReserveFixture([
            'reserved_until' => now()->addMinutes(10),
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'RESINV'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => (int) $user->id,
            'type' => 'normal',
            'coupon_id' => (int) $coupon->id,
            'user_coupon_id' => (int) $userCoupon->id,
            'coupon_code' => (string) $coupon->code,
            'amount' => '15.00',
            'discount' => '5.00',
            'paid_amount' => '15.00',
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'due_date' => now()->addDay(),
        ]);

        app(CouponService::class)->syncInvoiceCouponUsage($invoice);

        $this->assertNull($userCoupon->fresh()->reserved_until);
    }

    /**
     * @return array{0: User, 1: Product, 2: UserCoupon, 3: Coupon}
     */
    private function createReserveFixture(array $userCouponOverrides = []): array
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'coupon-reserve-'.$suffix.'@example.com',
            'password' => 'secret123',
            'phone' => '137'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $product = Product::query()->create([
            'name' => 'Reserve Product '.$suffix,
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
            'name' => 'Reserve Coupon '.$suffix,
            'code' => 'RSV'.strtoupper($suffix),
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
            'status' => UserCouponStatus::OWNED,
            'claimed_at' => now(),
        ], $userCouponOverrides));

        return [$user, $product, $userCoupon, $coupon];
    }
}
