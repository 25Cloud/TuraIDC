<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\CouponStatus;
use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Models\Coupon;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Models\UserCoupon;
use App\Services\Finance\CouponService;
use Tests\TestCase;

class ClientCouponPaginationTest extends TestCase
{
    public function test_owned_coupon_pagination_and_summary_use_database_filters(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => "coupon-owner-{$suffix}@example.com",
            'password' => 'secret123',
            'phone' => '136'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $availableCoupon = $this->createCoupon("owned-available-{$suffix}", [
            'expires_at' => now()->addDay(),
        ]);
        $expiredCoupon = $this->createCoupon("owned-expired-{$suffix}", [
            'expires_at' => now()->subDay(),
        ]);
        $usedUpCoupon = $this->createCoupon("owned-used-up-{$suffix}", [
            'per_user_limit' => 1,
            'expires_at' => now()->addDay(),
        ]);

        UserCoupon::query()->create([
            'coupon_id' => (int) $availableCoupon->id,
            'user_id' => (int) $user->id,
            'receive_type' => 'claim',
            'status' => 1,
            'claimed_at' => now(),
        ]);
        UserCoupon::query()->create([
            'coupon_id' => (int) $expiredCoupon->id,
            'user_id' => (int) $user->id,
            'receive_type' => 'claim',
            'status' => 1,
            'claimed_at' => now(),
        ]);
        $usedUpUserCoupon = UserCoupon::query()->create([
            'coupon_id' => (int) $usedUpCoupon->id,
            'user_id' => (int) $user->id,
            'receive_type' => 'claim',
            'status' => 1,
            'claimed_at' => now(),
        ]);

        $order = Order::query()->create([
            'order_no' => 'CPN'.strtoupper($suffix).'001',
            'user_id' => (int) $user->id,
            'coupon_id' => (int) $usedUpCoupon->id,
            'user_coupon_id' => (int) $usedUpUserCoupon->id,
            'type' => 'new',
            'amount' => '99.00',
            'discount' => '10.00',
            'status' => OrderStatus::PAID,
        ]);
        Invoice::query()->create([
            'invoice_no' => 'INV'.strtoupper($suffix).'001',
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'coupon_id' => (int) $usedUpCoupon->id,
            'status' => InvoiceStatus::PAID,
            'amount' => '99.00',
            'paid_amount' => '89.00',
            'due_date' => now()->addDay(),
            'paid_at' => now(),
        ]);

        $service = app(CouponService::class);
        $keyword = $suffix;

        $availablePage = $service->paginateForUser($user, ['status' => 'available', 'keyword' => $keyword], 1, 10);
        $usedUpPage = $service->paginateForUser($user, ['status' => 'used_up', 'keyword' => $keyword], 1, 10);
        $expiredPage = $service->paginateForUser($user, ['status' => 'expired', 'keyword' => $keyword], 1, 10);
        $summary = $service->summaryForUser($user, ['keyword' => $keyword]);

        $this->assertSame(1, $availablePage['total']);
        $this->assertSame('available', $availablePage['list'][0]['status'] ?? null);
        $this->assertSame(1, $usedUpPage['total']);
        $this->assertSame('used_up', $usedUpPage['list'][0]['status'] ?? null);
        $this->assertSame(1, $expiredPage['total']);
        $this->assertSame('expired', $expiredPage['list'][0]['status'] ?? null);

        $this->assertSame(3, $summary['total']);
        $this->assertSame(1, $summary['available']);
        $this->assertSame(1, $summary['used_up']);
        $this->assertSame(1, $summary['expired']);
    }

    public function test_public_coupon_pagination_and_summary_exclude_owned_coupons(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => "coupon-public-{$suffix}@example.com",
            'password' => 'secret123',
            'phone' => '135'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);
        $otherUser = User::query()->create([
            'email' => "coupon-public-other-{$suffix}@example.com",
            'password' => 'secret123',
            'phone' => '134'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $availableCoupon = $this->createCoupon("public-available-{$suffix}", [
            'distribution_type' => 'public',
            'expires_at' => now()->addDay(),
        ]);
        $expiredCoupon = $this->createCoupon("public-expired-{$suffix}", [
            'distribution_type' => 'public',
            'expires_at' => now()->subDay(),
        ]);
        $usedUpCoupon = $this->createCoupon("public-used-up-{$suffix}", [
            'distribution_type' => 'public',
            'total_usage_limit' => 1,
            'expires_at' => now()->addDay(),
        ]);
        $ownedCoupon = $this->createCoupon("public-owned-{$suffix}", [
            'distribution_type' => 'public',
            'expires_at' => now()->addDay(),
        ]);

        UserCoupon::query()->create([
            'coupon_id' => (int) $usedUpCoupon->id,
            'user_id' => (int) $otherUser->id,
            'receive_type' => 'claim',
            'status' => 1,
            'claimed_at' => now(),
        ]);
        UserCoupon::query()->create([
            'coupon_id' => (int) $ownedCoupon->id,
            'user_id' => (int) $user->id,
            'receive_type' => 'claim',
            'status' => 1,
            'claimed_at' => now(),
        ]);

        $service = app(CouponService::class);
        $keyword = $suffix;

        $availablePage = $service->paginatePublicForUser($user, ['status' => 'available', 'keyword' => $keyword], 1, 10);
        $usedUpPage = $service->paginatePublicForUser($user, ['status' => 'used_up', 'keyword' => $keyword], 1, 10);
        $expiredPage = $service->paginatePublicForUser($user, ['status' => 'expired', 'keyword' => $keyword], 1, 10);
        $summary = $service->summaryPublicForUser($user, ['keyword' => $keyword]);

        $this->assertSame(1, $availablePage['total']);
        $this->assertSame((int) $availableCoupon->id, (int) ($availablePage['list'][0]['id'] ?? 0));
        $this->assertSame(1, $usedUpPage['total']);
        $this->assertSame((int) $usedUpCoupon->id, (int) ($usedUpPage['list'][0]['id'] ?? 0));
        $this->assertSame(1, $expiredPage['total']);
        $this->assertSame((int) $expiredCoupon->id, (int) ($expiredPage['list'][0]['id'] ?? 0));

        $this->assertSame(3, $summary['total']);
        $this->assertSame(1, $summary['available']);
        $this->assertSame(1, $summary['used_up']);
        $this->assertSame(1, $summary['expired']);
    }

    private function createCoupon(string $name, array $overrides = []): Coupon
    {
        return Coupon::query()->create(array_merge([
            'name' => $name,
            'code' => strtoupper(str_replace('-', '', $name)),
            'distribution_type' => 'public',
            'discount_scope' => 'first_month',
            'discount_type' => 'fixed',
            'discount_value' => '10.00',
            'min_amount' => '0.00',
            'first_order_only' => false,
            'total_usage_limit' => null,
            'per_user_limit' => null,
            'used_count' => 0,
            'status' => CouponStatus::ACTIVE,
            'sort_order' => 0,
            'starts_at' => now()->subHour(),
            'expires_at' => now()->addDay(),
        ], $overrides));
    }
}
