<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountTransaction;
use App\Models\AdminUser;
use App\Models\MemberLevel;
use App\Models\Order;
use App\Models\ReferralReward;
use App\Models\ReferralWithdrawal;
use App\Models\Role;
use App\Models\User;
use App\Models\UserAccount;
use App\Models\UserReferral;
use App\Models\VerificationHistory;
use App\Services\Auth\AdminVerificationQueryService;
use App\Services\Finance\ClientFinanceQueryService;
use App\Services\Referral\AdminReferralOverviewService;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReadControllerQueryServiceBoundaryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_client_finance_query_service_returns_expected_lists_and_summaries(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createClientUser("finance-{$suffix}");
        $rechargeOriginId = random_int(100000, 199999);
        $consumeOriginId = random_int(200000, 299999);

        UserAccount::query()->updateOrCreate(
            ['user_id' => (int) $user->id],
            [
                'cash_balance' => '0.00',
                'credit_limit' => '0.00',
                'referral_frozen_balance' => '0.00',
                'referral_available_balance' => '0.00',
                'referral_pending_withdrawal_balance' => '0.00',
                'referral_withdrawn_balance' => '0.00',
                'version' => 0,
            ]
        );

        AccountTransaction::query()->create([
            'user_id' => (int) $user->id,
            'account_type' => 'cash',
            'event_type' => 'recharge',
            'change_amount' => '100.00',
            'balance_after' => '100.00',
            'source_type' => 'recharge',
            'source_id' => $rechargeOriginId,
            'origin_type' => 'recharge',
            'origin_id' => $rechargeOriginId,
            'remark' => 'recharge',
            'operator' => 'client',
            'trace_id' => 'recharge-'.$suffix,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        AccountTransaction::query()->create([
            'user_id' => (int) $user->id,
            'account_type' => 'cash',
            'event_type' => 'consume',
            'change_amount' => '-11.20',
            'balance_after' => '88.80',
            'source_type' => 'invoice',
            'source_id' => $consumeOriginId,
            'origin_type' => 'invoice',
            'origin_id' => $consumeOriginId,
            'remark' => 'consume',
            'operator' => 'system',
            'trace_id' => 'consume-'.$suffix,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = app(ClientFinanceQueryService::class);

        $filters = [
            'tab' => 'recharge',
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->toDateString(),
        ];

        $balancePage = $service->paginateBalanceLogs($user, $filters, 15);
        $balanceSummary = $service->balanceLogSummary($user, $filters);

        $this->assertSame(1, $balancePage['total']);
        $this->assertSame('recharge', $balancePage['list'][0]['event_type']);
        $this->assertSame('0.00', $balanceSummary['cash_balance']);
        $this->assertArrayNotHasKey('balance', $balanceSummary);
        $this->assertSame('100.00', $balanceSummary['total_in']);
        $this->assertSame('0.00', $balanceSummary['total_out']);
    }

    public function test_admin_referral_overview_service_returns_summary_and_top_referrers(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $baseline = app(AdminReferralOverviewService::class)->overview();

        $level = MemberLevel::query()->create([
            'name' => '闁规亽鍔忓畷妯肩驳婢跺矂鐛?'.$suffix,
            'code' => 'ref-'.$suffix,
            'sales_amount_min' => '0.00',
            'sales_amount_max' => null,
            'reward_rate' => '12.00',
            'status' => 1,
            'sort_order' => 1,
        ]);
        $seedReferrer = $this->createClientUser("seed-referrer-{$suffix}");

        $topUser = $this->createClientUser("top-referrer-{$suffix}", [
            'member_level_id' => (int) $level->id,
            'total_sales_amount' => '999999999.00',
            'referrer_user_id' => (int) $seedReferrer->id,
            'nickname' => 'Top Referrer',
        ]);
        UserAccount::query()->updateOrCreate(
            ['user_id' => (int) $topUser->id],
            [
                'cash_balance' => '0.00',
                'credit_limit' => '0.00',
                'referral_frozen_balance' => '11.00',
                'referral_available_balance' => '22.00',
                'referral_pending_withdrawal_balance' => '3.00',
                'referral_withdrawn_balance' => '44.00',
                'version' => 0,
            ]
        );
        if (Schema::hasTable('user_referrals')) {
            UserReferral::query()->updateOrCreate(
                ['user_id' => (int) $topUser->id],
                [
                    'referral_code' => 'TOP'.$suffix,
                    'referrer_user_id' => (int) $seedReferrer->id,
                    'referred_at' => now(),
                    'member_level_id' => (int) $level->id,
                    'total_sales_amount' => '999999999.00',
                ]
            );
        }

        $rewardOrder = Order::query()->create([
            'order_no' => 'REF'.strtoupper($suffix).'001',
            'user_id' => (int) $topUser->id,
            'type' => 'new',
            'amount' => '888.00',
            'discount' => '0.00',
            'paid_amount' => '888.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'status' => 1,
        ]);
        $releasedRewardOrder = Order::query()->create([
            'order_no' => 'REF'.strtoupper($suffix).'002',
            'user_id' => (int) $topUser->id,
            'type' => 'new',
            'amount' => '120.00',
            'discount' => '0.00',
            'paid_amount' => '120.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'status' => 1,
        ]);

        ReferralReward::query()->create([
            'referrer_user_id' => (int) $topUser->id,
            'referred_user_id' => (int) $topUser->id,
            'order_id' => (int) $rewardOrder->id,
            'product_id' => null,
            'order_amount' => '888.00',
            'reward_rate' => '12.00',
            'reward_amount' => '66.00',
            'status' => ReferralReward::STATUS_FROZEN,
            'available_at' => now()->addDay(),
            'operator' => 'system',
            'remark' => 'frozen reward',
            'trace_id' => 'trace-'.$suffix,
            'rewarded_at' => now(),
        ]);
        ReferralReward::query()->create([
            'referrer_user_id' => (int) $topUser->id,
            'referred_user_id' => (int) $topUser->id,
            'order_id' => (int) $releasedRewardOrder->id,
            'product_id' => null,
            'order_amount' => '120.00',
            'reward_rate' => '12.00',
            'reward_amount' => '14.00',
            'status' => ReferralReward::STATUS_REWARDED,
            'available_at' => now()->subDay(),
            'released_at' => now(),
            'operator' => 'system',
            'remark' => 'released reward',
            'trace_id' => 'trace-release-'.$suffix,
            'rewarded_at' => now(),
        ]);

        ReferralWithdrawal::query()->create([
            'user_id' => (int) $topUser->id,
            'amount' => '20.00',
            'method' => ReferralWithdrawal::METHOD_ALIPAY,
            'account_name' => 'tester',
            'account_no' => 'zhifubao-'.$suffix,
            'status' => ReferralWithdrawal::STATUS_PENDING,
            'remark' => 'pending withdrawal',
            'operator' => 'admin',
            'trace_id' => 'withdraw-pending-'.$suffix,
        ]);
        ReferralWithdrawal::query()->create([
            'user_id' => (int) $topUser->id,
            'amount' => '30.00',
            'method' => ReferralWithdrawal::METHOD_ALIPAY,
            'account_name' => 'tester',
            'account_no' => 'zhifubao-ok-'.$suffix,
            'status' => ReferralWithdrawal::STATUS_APPROVED,
            'remark' => 'approved withdrawal',
            'operator' => 'admin',
            'trace_id' => 'withdraw-approved-'.$suffix,
            'processed_at' => now(),
        ]);

        $payload = app(AdminReferralOverviewService::class)->overview();

        $this->assertSame($baseline['summary']['rewards_total'] + 2, $payload['summary']['rewards_total']);
        $this->assertSame($baseline['summary']['total_sales_amount'] + 1008.0, $payload['summary']['total_sales_amount']);
        $this->assertSame($baseline['summary']['total_reward_amount'] + 80.0, $payload['summary']['total_reward_amount']);
        $this->assertSame($baseline['summary']['withdrawing_amount'] + 20.0, $payload['summary']['withdrawing_amount']);
        $this->assertSame($baseline['summary']['withdrawn_amount'] + 30.0, $payload['summary']['withdrawn_amount']);
        $this->assertGreaterThanOrEqual($baseline['summary']['direct_referral_users'], $payload['summary']['direct_referral_users']);

        $topReferrerIds = array_column($payload['top_referrers'], 'id');
        $matchedTopReferrer = collect($payload['top_referrers'])->firstWhere('id', (int) $topUser->id);

        $this->assertContains((int) $topUser->id, $topReferrerIds);
        $this->assertSame('Top Referrer', $matchedTopReferrer['display_name'] ?? null);
        $this->assertSame(44.0, $matchedTopReferrer['referral_withdrawn_amount'] ?? null);
    }

    public function test_admin_referral_overview_service_only_counts_valid_direct_referral_bindings(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $baseline = app(AdminReferralOverviewService::class)->overview();

        $referrer = $this->createClientUser("valid-referrer-{$suffix}");

        $referredUser = $this->createClientUser("valid-referred-{$suffix}", [
            'referrer_user_id' => (int) $referrer->id,
        ]);

        if (Schema::hasTable('user_referrals')) {
            UserReferral::query()->create([
                'user_id' => (int) $referredUser->id,
                'referral_code' => 'VALID'.$suffix,
                'referrer_user_id' => (int) $referrer->id,
                'referred_at' => now(),
                'member_level_id' => null,
                'total_sales_amount' => '0.00',
            ]);
        }

        $invalidReferredUser = $this->createClientUser("invalid-referred-{$suffix}");
        $invalidReferrerId = max(
            (int) $invalidReferredUser->id,
            (int) User::withTrashed()->max('id')
        ) + 1;

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        try {
            User::query()
                ->whereKey((int) $invalidReferredUser->id)
                ->update([
                    'referrer_user_id' => $invalidReferrerId,
                ]);
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $payload = app(AdminReferralOverviewService::class)->overview();

        $this->assertSame(
            $baseline['summary']['direct_referral_users'] + 1,
            $payload['summary']['direct_referral_users']
        );
    }

    public function test_admin_verification_query_service_returns_list_summary_detail_and_history(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $verifiedUser = $this->createClientUser("verified-{$suffix}", [
            'real_name' => 'Verified User',
            'id_card' => '110101199001011234',
            'verification_status' => 2,
            'verification_message' => 'approved',
            'verification_certify_id' => 'cert-'.$suffix,
            'verified_at' => now(),
        ]);
        $pendingUser = $this->createClientUser("pending-{$suffix}", [
            'real_name' => 'Pending User',
            'id_card' => '110101199202023456',
            'verification_status' => 4,
            'verification_message' => 'pending',
        ]);

        if (Schema::hasTable('verification_histories')) {
            VerificationHistory::query()->create([
                'user_id' => (int) $verifiedUser->id,
                'real_name' => 'Verified User',
                'id_card' => '110101199001011234',
                'verification_status' => 2,
                'verification_message' => 'approved',
                'verification_certify_id' => 'cert-'.$suffix,
                'verification_biz_code' => 'FACE',
                'verification_type' => 'personal',
                'submitted_at' => now()->subMinute(),
                'completed_at' => now(),
            ]);
        }

        $service = app(AdminVerificationQueryService::class);
        $paginator = $service->paginate(['keyword' => $suffix], 20);
        $summary = $service->summary();
        $detail = $service->detail($verifiedUser);
        $history = $service->history($verifiedUser);

        $this->assertSame(2, $paginator->total());
        $this->assertSame((int) $pendingUser->id, (int) $paginator->items()[0]->id);
        $this->assertGreaterThanOrEqual(2, $summary['stats']['total']);
        $this->assertSame((int) $verifiedUser->id, $detail['id']);
        $this->assertSame('Verified User', $detail['display_name']);
        $this->assertStringContainsString('******', $detail['id_card_masked']);
        $this->assertNotEmpty($history['list']);
        $this->assertSame('Verified User', $history['list'][0]['real_name']);
    }

    public function test_client_finance_controller_uses_query_service(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createClientUser("finance-controller-{$suffix}");

        $service = $this->createMock(ClientFinanceQueryService::class);
        $service->expects($this->once())
            ->method('paginateBalanceLogs')
            ->with(
                $this->callback(fn (User $candidate) => (int) $candidate->id === (int) $user->id),
                ['event_type' => 'consume'],
                20
            )
            ->willReturn([
                'list' => [],
                'total' => 0,
                'page' => 1,
                'page_size' => 20,
            ]);
        $service->expects($this->once())
            ->method('balanceLogSummary')
            ->with(
                $this->callback(fn (User $candidate) => (int) $candidate->id === (int) $user->id),
                ['event_type' => 'consume']
            )
            ->willReturn([
                'cash_balance' => 0.0,
                'total_in' => 0.0,
                'total_out' => 0.0,
            ]);

        $this->app->instance(ClientFinanceQueryService::class, $service);
        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/balance-logs?event_type=consume&page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 0);

        $this->getJson('/api/v2/client/balance-logs/summary?event_type=consume')
            ->assertOk()
            ->assertJsonPath('data.total_out', 0);
    }

    public function test_admin_referral_controller_uses_overview_service(): void
    {
        $admin = $this->createAdminUser(['referral.list']);
        $service = $this->createMock(AdminReferralOverviewService::class);
        $service->expects($this->once())
            ->method('overview')
            ->willReturn([
                'summary' => [
                    'rewards_total' => 1,
                    'total_sales_amount' => 100.0,
                    'total_reward_amount' => 10.0,
                    'frozen_amount' => 10.0,
                    'released_amount' => 0.0,
                    'withdrawals_total' => 0,
                    'withdrawing_amount' => 0.0,
                    'withdrawn_amount' => 0.0,
                    'rejected_amount' => 0.0,
                    'direct_referral_users' => 1,
                ],
                'top_referrers' => [[
                    'id' => 1,
                    'email' => 'delegate@example.com',
                    'nickname' => 'Delegate',
                    'display_name' => 'Delegate',
                    'member_level' => null,
                    'total_sales_amount' => 100.0,
                    'referral_frozen_amount' => 10.0,
                    'referral_available_amount' => 0.0,
                    'referral_withdrawing_amount' => 0.0,
                    'referral_withdrawn_amount' => 0.0,
                ]],
            ]);

        $this->app->instance(AdminReferralOverviewService::class, $service);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v2/admin/referral/overview')
            ->assertOk()
            ->assertJsonPath('data.summary.rewards_total', 1)
            ->assertJsonPath('data.top_referrers.0.display_name', 'Delegate');
    }

    public function test_admin_verification_controller_uses_query_service(): void
    {
        $admin = $this->createAdminUser([
            AdminPermissions::VERIFICATION_LIST,
            AdminPermissions::PRIVACY_VIEW_RAW,
        ]);
        $user = $this->createClientUser('verification-controller-'.bin2hex(random_bytes(4)), [
            'real_name' => 'Verification User',
            'id_card' => '110101199305056789',
            'verification_status' => 2,
            'verification_message' => 'approved',
            'verification_certify_id' => 'cert-controller',
            'verified_at' => now(),
        ]);

        $paginator = new LengthAwarePaginator([$user], 1, 20, 1);
        $service = $this->createMock(AdminVerificationQueryService::class);
        $service->expects($this->once())
            ->method('paginate')
            ->with(
                $this->callback(function (array $filters): bool {
                    return ($filters['keyword'] ?? null) === 'Verification User';
                }),
                20
            )
            ->willReturn($paginator);
        $service->expects($this->once())
            ->method('summary')
            ->willReturn([
                'stats' => [
                    'total' => 1,
                    'verified' => 1,
                    'pending' => 0,
                    'failed' => 0,
                ],
                'config' => [
                    'verification_api_masked' => 'configured',
                    'verification_biz_code' => 'FACE',
                    'configured' => true,
                ],
            ]);
        $service->expects($this->once())
            ->method('detail')
            ->with($this->callback(fn (User $candidate) => (int) $candidate->id === (int) $user->id))
            ->willReturn([
                'id' => (int) $user->id,
                'display_name' => 'Verification User',
                'email' => (string) $user->email,
                'phone' => (string) $user->phone,
                'real_name' => 'Verification User',
                'id_card_masked' => '110101******6789',
                'verification_status' => 2,
                'verification_message' => 'approved',
                'verification_certify_id' => 'cert-controller',
                'verification_biz_code' => 'FACE',
                'verification_method_label' => 'Face Verification',
                'verification_type_label' => 'Personal',
                'document_type_label' => 'ID Card',
                'identity_region_label' => 'CN',
                'created_at' => now()->format('Y-m-d H:i:s'),
                'updated_at' => now()->format('Y-m-d H:i:s'),
                'verified_at' => now()->format('Y-m-d H:i:s'),
            ]);
        $service->expects($this->once())
            ->method('history')
            ->with($this->callback(fn (User $candidate) => (int) $candidate->id === (int) $user->id))
            ->willReturn([
                'user_name' => 'Verification User',
                'list' => [[
                    'id' => 1,
                    'real_name' => 'Verification User',
                    'id_card_masked' => '110101******6789',
                    'verification_status' => 2,
                    'verification_message' => 'approved',
                    'verification_certify_id' => 'cert-controller',
                    'verification_method_label' => 'Face Verification',
                    'verification_type_label' => 'Personal',
                    'submitted_at' => now()->format('Y-m-d H:i:s'),
                    'completed_at' => now()->format('Y-m-d H:i:s'),
                ]],
            ]);

        $this->app->instance(AdminVerificationQueryService::class, $service);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v2/admin/verifications?keyword=Verification%20User&page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.real_name', 'Verification User');

        $this->getJson('/api/v2/admin/verifications/summary')
            ->assertOk()
            ->assertJsonPath('data.stats.verified', 1);

        $this->getJson('/api/v2/admin/verifications/'.$user->id)
            ->assertOk()
            ->assertJsonPath('data.id', (int) $user->id);

        $this->getJson('/api/v2/admin/verifications/'.$user->id.'/history')
            ->assertOk()
            ->assertJsonPath('data.user_name', 'Verification User');
    }

    private function createClientUser(string $prefix, array $overrides = []): User
    {
        return User::query()->create(array_merge([
            'email' => "{$prefix}@example.com",
            'password' => 'secret123',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => '',
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
        ], $overrides));
    }

    private function createAdminUser(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'admin-role-'.$suffix,
            'label' => 'Test Role',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'admin-'.$suffix,
            'password' => 'secret123',
            'role_id' => (int) $role->id,
            'nickname' => 'Test Admin',
            'email' => "admin-{$suffix}@example.com",
            'status' => 1,
        ]);
    }
}
