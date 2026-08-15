<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\CouponStatus;
use App\Constants\InvoiceStatus;
use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\User;
use App\Models\UserCoupon;
use App\Services\Finance\CouponService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CouponDoubleSpendGuardTest extends TestCase
{
    private function createFixture(string $suffix): array
    {
        $user = User::query()->create([
            'email' => 'coupon-guard-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '138'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Coupon Guard',
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
        ]);

        $coupon = Coupon::query()->create([
            'name' => 'Guard Coupon '.$suffix,
            'code' => 'GUARD'.strtoupper($suffix),
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
            'status' => 1,
            'claimed_at' => now(),
            // reserved_until 已过期：模拟支付成功后异步同步尚未完成的窗口
            'reserved_until' => now()->subMinutes(11),
        ]);

        $product = Product::query()->create([
            'name' => 'Guard Product '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '20.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);

        return compact('user', 'coupon', 'userCoupon', 'product');
    }

    #[Test]
    public function reserve_rejects_coupon_that_already_has_paid_invoice(): void
    {
        $suffix = bin2hex(random_bytes(4));
        ['user' => $user, 'coupon' => $coupon, 'userCoupon' => $userCoupon, 'product' => $product] = $this->createFixture($suffix);

        // 该券已用于一笔已支付账单，但异步同步未完成（券仍 OWNED 且 reserved_until 已过期）
        Invoice::query()->create([
            'invoice_no' => 'INVGUARD'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'coupon_id' => (int) $coupon->id,
            'user_coupon_id' => (int) $userCoupon->id,
            'type' => 'new',
            'amount' => '15.00',
            'paid_amount' => '15.00',
            'billing_cycle' => 'monthly',
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'due_date' => now()->addDay(),
        ]);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('优惠券已使用');

        app(CouponService::class)->reserveOwnedCouponForInvoice(
            (int) $userCoupon->id,
            (int) $user->id,
            $product,
            'monthly',
            20.0,
            'new'
        );
    }

    #[Test]
    public function reserve_allows_coupon_without_paid_invoice(): void
    {
        $suffix = bin2hex(random_bytes(4));
        ['user' => $user, 'userCoupon' => $userCoupon, 'product' => $product] = $this->createFixture($suffix);

        $payload = app(CouponService::class)->reserveOwnedCouponForInvoice(
            (int) $userCoupon->id,
            (int) $user->id,
            $product,
            'monthly',
            20.0,
            'new'
        );

        $this->assertIsArray($payload);
        $this->assertSame('5.00', (string) ($payload['discount_amount'] ?? ''));
        $this->assertNotNull($userCoupon->fresh()->reserved_until);
    }
}
