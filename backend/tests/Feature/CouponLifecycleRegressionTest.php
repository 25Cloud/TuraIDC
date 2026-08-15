<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\CouponStatus;
use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\UserCoupon;
use App\Services\Finance\CouponService;
use App\Services\Order\OrderService;
use Tests\TestCase;

class CouponLifecycleRegressionTest extends TestCase
{
    public function test_sync_order_coupon_usage_counts_only_paid_like_orders(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'coupon-usage-'.$suffix.'@example.com',
            'password' => 'secret123',
            'phone' => '136'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $coupon = Coupon::query()->create([
            'name' => 'Usage Coupon '.$suffix,
            'code' => 'USAGE'.strtoupper($suffix),
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
        ]);

        $order = Order::query()->create([
            'order_no' => 'CUPSYNC'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'coupon_id' => (int) $coupon->id,
            'user_coupon_id' => (int) $userCoupon->id,
            'type' => 'new',
            'amount' => '20.00',
            'discount' => '5.00',
            'billing_cycle' => 'monthly',
            'status' => OrderStatus::PENDING,
        ]);

        $service = app(CouponService::class);

        $service->syncOrderCouponUsage($order);
        $this->assertSame(0, (int) $coupon->fresh()->used_count);
        $this->assertNull($userCoupon->fresh()->last_used_at);

        $order->forceFill([
            'status' => OrderStatus::PAID,
            'paid_at' => now(),
        ])->save();
        $service->syncOrderCouponUsage($order->fresh());
        $this->assertSame(1, (int) $coupon->fresh()->used_count);
        $this->assertNotNull($userCoupon->fresh()->last_used_at);

        $order->forceFill([
            'status' => OrderStatus::PENDING,
            'paid_at' => null,
        ])->save();
        $service->syncOrderCouponUsage($order->fresh());
        $this->assertSame(0, (int) $coupon->fresh()->used_count);
        $this->assertNull($userCoupon->fresh()->last_used_at);
    }

    public function test_cancel_pending_order_releases_order_coupon(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'coupon-cancel-'.$suffix.'@example.com',
            'password' => 'secret123',
            'phone' => '137'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $coupon = Coupon::query()->create([
            'name' => 'Cancel Coupon '.$suffix,
            'code' => 'CANCEL'.strtoupper($suffix),
            'distribution_type' => 'public',
            'discount_scope' => 'first_month',
            'discount_type' => 'fixed',
            'discount_value' => '5.00',
            'min_amount' => '0.00',
            'used_count' => 1,
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
            'last_used_at' => now(),
        ]);

        $order = Order::query()->create([
            'order_no' => 'CUPCANCEL'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'coupon_id' => (int) $coupon->id,
            'user_coupon_id' => (int) $userCoupon->id,
            'coupon_code' => (string) $coupon->code,
            'type' => 'new',
            'amount' => '20.00',
            'discount' => '5.00',
            'billing_cycle' => 'monthly',
            'status' => OrderStatus::PENDING,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'CUPINV'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'type' => 'normal',
            'coupon_id' => (int) $coupon->id,
            'user_coupon_id' => (int) $userCoupon->id,
            'coupon_code' => (string) $coupon->code,
            'amount' => '15.00',
            'discount' => '5.00',
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::UNPAID,
            'due_date' => now()->addDay(),
        ]);

        app(OrderService::class)->cancel($order, [
            'actor_type' => 'client',
            'actor_user_id' => (int) $user->id,
            'trace_id' => 'coupon-cancel-'.$suffix,
        ]);

        $this->assertSame(OrderStatus::CANCELLED, (int) $order->fresh()->status);
        $this->assertSame(InvoiceStatus::CANCELLED, (int) $invoice->fresh()->status);
        $this->assertSame(0, (int) $coupon->fresh()->used_count);
        $this->assertNull($userCoupon->fresh()->last_used_at);
    }

    public function test_update_issued_private_coupon_allows_unlocked_fields(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $userA = $this->createUser('a', $suffix);
        $userB = $this->createUser('b', $suffix);

        $service = app(CouponService::class);
        $payload = $this->privateCouponPayload($suffix, [(int) $userA->id, (int) $userB->id]);
        $created = $service->createCoupon($payload, [
            'operator' => 'coupon-regression',
            'trace_id' => 'coupon-create-'.$suffix,
        ]);

        $coupon = Coupon::query()->findOrFail((int) $created['id']);

        $updated = $service->updateCoupon(
            $coupon,
            $this->privateCouponPayload($suffix, [(int) $userA->id], [
                'name' => 'Private Coupon Updated '.$suffix,
                'discount_value' => 8,
            ]),
            [
                'operator' => 'coupon-regression',
                'trace_id' => 'coupon-update-'.$suffix,
            ]
        );

        $coupon->refresh();

        $this->assertSame('Private Coupon Updated '.$suffix, (string) $coupon->name);
        $this->assertSame('8.00', number_format((float) $coupon->discount_value, 2, '.', ''));
        $this->assertTrue((bool) $updated['can_update']);
        $this->assertSame('已发放的优惠券', $updated['lock_reason']);
        $this->assertSame(['distribution_type', 'discount_type', 'discount_scope'], $updated['locked_fields']);
        $this->assertDatabaseHas('user_coupons', [
            'coupon_id' => (int) $coupon->id,
            'user_id' => (int) $userA->id,
        ]);
        $this->assertDatabaseMissing('user_coupons', [
            'coupon_id' => (int) $coupon->id,
            'user_id' => (int) $userB->id,
        ]);
    }

    public function test_update_claimed_public_coupon_rejects_locked_field_changes(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $claimUser = $this->createUser('claim', $suffix);
        $service = app(CouponService::class);

        $coupon = Coupon::query()->create([
            'name' => 'Switch Coupon '.$suffix,
            'code' => 'SWITCH'.strtoupper($suffix),
            'distribution_type' => 'public',
            'discount_scope' => 'first_month',
            'discount_type' => 'fixed',
            'discount_value' => '5.00',
            'min_amount' => '0.00',
            'status' => CouponStatus::ACTIVE,
            'starts_at' => now()->subHour(),
            'expires_at' => now()->addDay(),
        ]);

        $claimedCoupon = UserCoupon::query()->create([
            'coupon_id' => (int) $coupon->id,
            'user_id' => (int) $claimUser->id,
            'receive_type' => 'claim',
            'status' => 1,
            'claimed_at' => now(),
        ]);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('已发放的优惠券，无法修改「distribution_type」');

        $service->updateCoupon(
            $coupon,
            $this->privateCouponPayload($suffix, [(int) $claimUser->id], [
                'name' => 'Switch Coupon '.$suffix,
                'code' => 'SWITCH'.strtoupper($suffix),
            ]),
            [
                'operator' => 'coupon-regression',
                'trace_id' => 'coupon-switch-'.$suffix,
            ]
        );
    }

    public function test_delete_issued_unused_coupon_removes_assignments(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $userA = $this->createUser('limit-a', $suffix);
        $userB = $this->createUser('limit-b', $suffix);
        $service = app(CouponService::class);

        $created = $service->createCoupon(
            $this->privateCouponPayload($suffix, [(int) $userA->id, (int) $userB->id], [
                'name' => 'Delete Unused Private Coupon '.$suffix,
                'code' => 'DELUNUSED'.strtoupper($suffix),
                'total_usage_limit' => 2,
            ]),
            [
                'operator' => 'coupon-regression',
                'trace_id' => 'coupon-delete-unused-create-'.$suffix,
            ]
        );

        $coupon = Coupon::query()->findOrFail((int) $created['id']);
        $this->assertSame(2, UserCoupon::query()->where('coupon_id', (int) $coupon->id)->count());

        $service->deleteCoupon($coupon, [
            'operator' => 'coupon-regression',
            'trace_id' => 'coupon-delete-unused-'.$suffix,
        ]);

        $this->assertDatabaseMissing('user_coupons', [
            'coupon_id' => (int) $coupon->id,
        ]);
        $this->assertDatabaseMissing('coupons', [
            'id' => (int) $coupon->id,
        ]);
    }

    public function test_delete_used_coupon_is_rejected(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createUser('used-delete', $suffix);
        $service = app(CouponService::class);

        $created = $service->createCoupon(
            $this->privateCouponPayload($suffix, [(int) $user->id], [
                'name' => 'Used Private Coupon '.$suffix,
                'code' => 'USEDDEL'.strtoupper($suffix),
            ]),
            [
                'operator' => 'coupon-regression',
                'trace_id' => 'coupon-used-delete-create-'.$suffix,
            ]
        );

        $coupon = Coupon::query()->findOrFail((int) $created['id']);
        $userCoupon = UserCoupon::query()
            ->where('coupon_id', (int) $coupon->id)
            ->where('user_id', (int) $user->id)
            ->firstOrFail();

        Order::query()->create([
            'order_no' => 'CUPUSEDDEL'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'coupon_id' => (int) $coupon->id,
            'user_coupon_id' => (int) $userCoupon->id,
            'type' => 'new',
            'amount' => '20.00',
            'discount' => '5.00',
            'billing_cycle' => 'monthly',
            'status' => OrderStatus::PAID,
            'paid_at' => now(),
        ]);
        $userCoupon->forceFill(['last_used_at' => now()])->save();

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('该优惠券已有人使用，不能删除');

        $service->deleteCoupon($coupon, [
            'operator' => 'coupon-regression',
            'trace_id' => 'coupon-used-delete-'.$suffix,
        ]);
    }

    public function test_create_and_update_coupon_persist_manual_code(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $service = app(CouponService::class);
        $initialCode = 'MANUAL'.strtoupper($suffix);
        $updatedCode = 'MANUALUPD'.strtoupper($suffix);

        $created = $service->createCoupon(
            $this->privateCouponPayload($suffix, [], [
                'name' => 'Manual Code Coupon '.$suffix,
                'code' => $initialCode,
                'distribution_type' => 'public',
                'user_ids' => [],
            ]),
            [
                'operator' => 'coupon-regression',
                'trace_id' => 'coupon-code-create-'.$suffix,
            ]
        );

        $coupon = Coupon::query()->findOrFail((int) $created['id']);
        $this->assertSame($initialCode, (string) $coupon->code);

        $service->updateCoupon(
            $coupon,
            $this->privateCouponPayload($suffix, [], [
                'name' => 'Manual Code Coupon Updated '.$suffix,
                'code' => $updatedCode,
                'distribution_type' => 'public',
                'user_ids' => [],
            ]),
            [
                'operator' => 'coupon-regression',
                'trace_id' => 'coupon-code-update-'.$suffix,
            ]
        );

        $this->assertSame($updatedCode, (string) $coupon->fresh()->code);
    }

    private function createUser(string $tag, string $suffix): User
    {
        return User::query()->create([
            'email' => "coupon-{$tag}-{$suffix}@example.com",
            'password' => 'secret123',
            'phone' => '135'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);
    }

    private function createProduct(string $suffix): Product
    {
        return Product::query()->create([
            'name' => 'Coupon Product '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '20.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);
    }

    private function privateCouponPayload(string $suffix, array $userIds, array $overrides = []): array
    {
        return array_merge([
            'name' => 'Private Coupon '.$suffix,
            'code' => 'PRIVATE'.strtoupper($suffix),
            'distribution_type' => 'private',
            'discount_scope' => 'first_month',
            'discount_type' => 'fixed',
            'discount_value' => 5,
            'min_amount' => 0,
            'max_discount_amount' => null,
            'billing_cycles' => [],
            'product_ids' => [],
            'first_order_only' => false,
            'user_ids' => $userIds,
            'total_usage_limit' => null,
            'per_user_limit' => null,
            'status' => CouponStatus::ACTIVE,
            'sort_order' => 0,
            'starts_at' => now()->subHour()->format('Y-m-d H:i:s'),
            'expires_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'description' => null,
            'remark' => null,
        ], $overrides);
    }
}
