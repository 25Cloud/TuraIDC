<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MemberLevel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientReferralRegressionTest extends TestCase
{
    public function test_referral_endpoints_work_without_user_accounts_and_account_transactions_tables(): void
    {
        MemberLevel::query()->firstOrCreate(
            ['code' => 'ref-test-default'],
            [
                'name' => 'v1',
                'sales_amount_min' => '0.00',
                'sales_amount_max' => '300.00',
                'reward_rate' => '5.00',
                'status' => 1,
                'sort_order' => 1,
            ]
        );

        $user = User::query()->create([
            'email' => 'referral-regression-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Referral Regression',
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

        DB::table('account_transactions')->insert([
            'user_id' => (int) $user->id,
            'account_type' => 'referral_frozen',
            'event_type' => 'reward_frozen',
            'change_amount' => '10.00',
            'balance_after' => '10.00',
            'source_type' => 'reward',
            'source_id' => 1,
            'origin_type' => 'referral_event',
            'origin_id' => 1,
            'remark' => 'referral regression',
            'operator' => 'system',
            'trace_id' => 'referral-regression',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/referral/overview')
            ->assertOk()
            ->assertJsonPath('data.referral_code', fn ($value) => is_string($value) && $value !== '')
            ->assertJsonPath('data.referral_frozen_amount', '0.00');

        $this->getJson('/api/v2/client/referral/account-logs?per_page=30')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.event_type', 'reward_frozen')
            ->assertJsonPath('data.list.0.frozen_amount', '10.00');
    }

    public function test_direct_referrals_endpoint_lists_invited_users(): void
    {
        $referrer = User::query()->create([
            'email' => 'referrer-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Referrer',
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

        $invited = User::query()->create([
            'email' => 'invited-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Invited User',
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '100.00',
            'referrer_user_id' => $referrer->id,
            'verified_at' => null,
        ]);

        $order = \App\Models\Order::query()->create([
            'order_no' => 'ORDDIRECT'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => (int) $invited->id,
            'type' => 'new',
            'amount' => '100.00',
            'discount' => '0.00',
            'paid_amount' => '100.00',
            'billing_cycle' => 'monthly',
            'status' => \App\Constants\OrderStatus::PAID,
            'paid_at' => now(),
        ]);

        \App\Models\ReferralReward::query()->create([
            'referrer_user_id' => $referrer->id,
            'referred_user_id' => $invited->id,
            'order_id' => $order->id,
            'order_amount' => '100.00',
            'reward_rate' => '5.00',
            'reward_amount' => '5.00',
            'status' => \App\Models\ReferralReward::STATUS_FROZEN,
            'rewarded_at' => now(),
        ]);

        Sanctum::actingAs($referrer);

        $this->getJson('/api/v2/client/referral/direct-referrals?page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.id', $invited->id)
            ->assertJsonPath('data.list.0.email', $invited->email)
            ->assertJsonPath('data.list.0.display_name', 'Invited User')
            ->assertJsonPath('data.list.0.customer_consumption', '100.00')
            ->assertJsonPath('data.list.0.my_earnings', '5.00');

        $this->getJson('/api/v2/client/referral/rewards?page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.order_type', '新购')
            ->assertJsonPath('data.list.0.order_amount', '100.00');
    }
}
