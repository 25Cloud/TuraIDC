<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Services\User\UserService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserDetailInvoiceOnlyStatsTest extends TestCase
{
    private function mirrorUserToIdc(User $user, string $suffix): void
    {
        DB::connection()->table('users')->updateOrInsert(
            ['id' => (int) $user->id],
            [
                'email' => $user->email,
                'phone' => $user->phone,
                'password' => Hash::make('Temp@123456'),
                'status' => 1,
                'referral_code' => 'U'.strtoupper(substr(md5($suffix.'-'.$user->id), 0, 8)),
                'referrer_user_id' => null,
                'member_level_id' => null,
                'login_email_alert' => 1,
                'login_notify' => 1,
                'login_location_alert' => 1,
                'password_change_alert' => 1,
                'phone_change_alert' => 1,
                'email_change_alert' => 1,
                'marketing_alert' => 0,
                'is_verified' => 0,
                'verification_status' => 0,
                'verification_message' => '',
                'verification_certify_id' => null,
                'referred_at' => null,
                'verified_at' => null,
                'last_login_ip' => null,
                'last_login_at' => null,
                'deleted_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );
    }

    public function test_detail_uses_invoice_stats_when_legacy_orders_are_missing(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'user-detail-invoice-only-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Invoice Only Stats',
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
        $this->mirrorUserToIdc($user, $suffix);

        Invoice::query()->create([
            'invoice_no' => 'UDINVUNPAID'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'type' => 'normal',
            'amount' => '18.00',
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::UNPAID,
            'billing_cycle' => 'monthly',
            'product_spec_snapshot' => '未支付实例',
            'due_date' => now()->addDay(),
        ]);

        Invoice::query()->create([
            'invoice_no' => 'UDINVPAID'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'type' => 'normal',
            'amount' => '28.00',
            'paid_amount' => '28.00',
            'status' => InvoiceStatus::PAID,
            'billing_cycle' => 'monthly',
            'product_spec_snapshot' => '已支付实例',
            'due_date' => now()->addDay(),
            'paid_at' => now()->subMinute(),
        ]);

        $detail = app(UserService::class)->detail($user->fresh());

        $this->assertSame(2, (int) ($detail['stats']['order_total'] ?? 0));
        $this->assertSame(1, (int) ($detail['stats']['order_pending'] ?? 0));
        $this->assertSame(1, (int) ($detail['stats']['invoice_unpaid'] ?? 0));
        $this->assertSame(1, (int) ($detail['stats']['invoice_paid'] ?? 0));
    }
}
