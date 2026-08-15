<?php

declare(strict_types=1);

namespace Tests\Feature\Order;

use App\Constants\CouponStatus;
use App\Constants\InvoiceStatus;
use App\Constants\UserCouponStatus;
use App\Jobs\SyncInvoiceCouponUsageJob;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\User;
use App\Models\UserCoupon;
use App\Services\Finance\CouponService;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CheckoutWithCouponTest extends TestCase
{
    public function test_invoice_coupon_sync_after_response_dispatches_coupon_queue_job(): void
    {
        Queue::fake();
        [, , $invoice] = $this->createInvoiceCouponFixture();

        app(CouponService::class)->syncInvoiceCouponUsageAfterResponse($invoice);

        Queue::assertPushedOn('coupon', SyncInvoiceCouponUsageJob::class);
    }

    public function test_sync_invoice_coupon_usage_job_updates_coupon_state(): void
    {
        [$coupon, $userCoupon, $invoice] = $this->createInvoiceCouponFixture();

        (new SyncInvoiceCouponUsageJob((int) $invoice->id))->handle(app(CouponService::class));

        $this->assertSame(1, (int) $coupon->fresh()->used_count);
        $this->assertSame(UserCouponStatus::USED, (int) $userCoupon->fresh()->status);
    }

    /**
     * @return array{0: Coupon, 1: UserCoupon, 2: Invoice}
     */
    private function createInvoiceCouponFixture(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'checkout-coupon-'.$suffix.'@example.com',
            'password' => 'secret123',
            'phone' => '134'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $coupon = Coupon::query()->create([
            'name' => 'Checkout Coupon '.$suffix,
            'code' => 'CHK'.strtoupper($suffix),
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
            'reserved_until' => now()->addMinutes(10),
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'CHKINV'.strtoupper($suffix),
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

        return [$coupon, $userCoupon, $invoice];
    }
}
