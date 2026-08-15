<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Finance;

use App\Constants\CouponStatus;
use App\Constants\InvoiceStatus;
use App\Constants\UserCouponStatus;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\User;
use App\Models\UserCoupon;
use App\Services\Finance\CouponService;
use Tests\TestCase;

class CouponSyncTest extends TestCase
{
    public function test_paid_invoice_marks_user_coupon_used(): void
    {
        [$coupon, $userCoupon, $invoice] = $this->createCouponInvoiceFixture([
            'invoice_status' => InvoiceStatus::PAID,
            'user_coupon_status' => UserCouponStatus::OWNED,
            'reserved_until' => now()->addMinutes(10),
        ]);

        app(CouponService::class)->syncInvoiceCouponUsage($invoice);

        $userCoupon->refresh();
        $this->assertSame(1, (int) $coupon->fresh()->used_count);
        $this->assertSame(UserCouponStatus::USED, (int) $userCoupon->status);
        $this->assertNotNull($userCoupon->used_at);
        $this->assertNotNull($userCoupon->last_used_at);
        $this->assertNull($userCoupon->reserved_until);
    }

    public function test_refunded_normal_coupon_returns_to_owned(): void
    {
        [$coupon, $userCoupon, $invoice] = $this->createCouponInvoiceFixture([
            'invoice_status' => InvoiceStatus::REFUNDED,
            'user_coupon_status' => UserCouponStatus::USED,
            'used_at' => now()->subHour(),
            'last_used_at' => now()->subHour(),
        ]);

        app(CouponService::class)->syncInvoiceCouponUsage($invoice);

        $userCoupon->refresh();
        $this->assertSame(0, (int) $coupon->fresh()->used_count);
        $this->assertSame(UserCouponStatus::OWNED, (int) $userCoupon->status);
        $this->assertNull($userCoupon->used_at);
        $this->assertNull($userCoupon->last_used_at);
    }

    public function test_refunded_first_order_coupon_stays_used(): void
    {
        [$coupon, $userCoupon, $invoice] = $this->createCouponInvoiceFixture([
            'first_order_only' => true,
            'invoice_status' => InvoiceStatus::REFUNDED,
            'user_coupon_status' => UserCouponStatus::USED,
            'used_at' => now()->subHour(),
            'last_used_at' => now()->subHour(),
        ]);

        app(CouponService::class)->syncInvoiceCouponUsage($invoice);

        $userCoupon->refresh();
        $this->assertSame(0, (int) $coupon->fresh()->used_count);
        $this->assertSame(UserCouponStatus::USED, (int) $userCoupon->status);
        $this->assertNotNull($userCoupon->used_at);
    }

    public function test_revoked_user_coupon_is_not_rolled_back(): void
    {
        [, $userCoupon, $invoice] = $this->createCouponInvoiceFixture([
            'invoice_status' => InvoiceStatus::REFUNDED,
            'user_coupon_status' => UserCouponStatus::REVOKED,
            'revoked_at' => now()->subMinute(),
            'used_at' => now()->subHour(),
            'last_used_at' => now()->subHour(),
        ]);

        app(CouponService::class)->syncInvoiceCouponUsage($invoice);

        $userCoupon->refresh();
        $this->assertSame(UserCouponStatus::REVOKED, (int) $userCoupon->status);
        $this->assertNotNull($userCoupon->revoked_at);
    }

    /**
     * @return array{0: Coupon, 1: UserCoupon, 2: Invoice}
     */
    private function createCouponInvoiceFixture(array $overrides = []): array
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'coupon-sync-'.$suffix.'@example.com',
            'password' => 'secret123',
            'phone' => '138'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $coupon = Coupon::query()->create([
            'name' => 'Sync Coupon '.$suffix,
            'code' => 'SYNC'.strtoupper($suffix),
            'distribution_type' => 'public',
            'discount_scope' => 'first_month',
            'discount_type' => 'fixed',
            'discount_value' => '5.00',
            'min_amount' => '0.00',
            'first_order_only' => (bool) ($overrides['first_order_only'] ?? false),
            'used_count' => 0,
            'status' => CouponStatus::ACTIVE,
            'starts_at' => now()->subHour(),
            'expires_at' => now()->addDay(),
        ]);

        $userCoupon = UserCoupon::query()->create([
            'coupon_id' => (int) $coupon->id,
            'user_id' => (int) $user->id,
            'receive_type' => 'claim',
            'status' => (int) ($overrides['user_coupon_status'] ?? UserCouponStatus::OWNED),
            'claimed_at' => now()->subHours(2),
            'used_at' => $overrides['used_at'] ?? null,
            'revoked_at' => $overrides['revoked_at'] ?? null,
            'reserved_until' => $overrides['reserved_until'] ?? null,
            'last_used_at' => $overrides['last_used_at'] ?? null,
        ]);

        $paidAt = now()->subMinutes(30);
        $invoice = Invoice::query()->create([
            'invoice_no' => 'SYNCINV'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'type' => 'normal',
            'coupon_id' => (int) $coupon->id,
            'user_coupon_id' => (int) $userCoupon->id,
            'coupon_code' => (string) $coupon->code,
            'amount' => '15.00',
            'discount' => '5.00',
            'paid_amount' => '15.00',
            'status' => (int) ($overrides['invoice_status'] ?? InvoiceStatus::PAID),
            'paid_at' => $paidAt,
            'due_date' => now()->addDay(),
        ]);

        return [$coupon, $userCoupon, $invoice];
    }
}
