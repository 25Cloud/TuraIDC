<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\ReferralWithdrawal;
use App\Models\Role;
use App\Models\User;
use App\Models\UserAccount;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2AdminReferralWithdrawalActionApiTest extends TestCase
{
    public function test_referral_withdrawal_actions_require_login_and_withdraw_permission(): void
    {
        $withdrawal = $this->createWithdrawal();

        $this->postJson('/api/v2/admin/referral-withdrawals/'.$withdrawal->id.'/approvals')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::ORDER_LIST]));

        $this->postJson('/api/v2/admin/referral-withdrawals/'.$withdrawal->id.'/approvals')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);
    }

    public function test_referral_withdrawal_approval_validates_payload_and_returns_compact_result(): void
    {
        $withdrawal = $this->createWithdrawal([
            'amount' => '20.00',
            'method' => ReferralWithdrawal::METHOD_BALANCE,
        ], [
            'cash_balance' => '3.00',
            'referral_pending_withdrawal_balance' => '20.00',
            'referral_withdrawn_balance' => '0.00',
        ]);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::FINANCE_WITHDRAW]));

        $this->postJson('/api/v2/admin/referral-withdrawals/'.$withdrawal->id.'/approvals', ['per_page' => 20])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->postJson('/api/v2/admin/referral-withdrawals/'.$withdrawal->id.'/approvals', [
            'remark' => '审核通过',
        ], [
            'X-Request-Id' => 'v2-referral-approval-'.$withdrawal->id,
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $withdrawal->id)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.type', 'approval')
            ->assertJsonPath('data.detail.withdrawal.status', ReferralWithdrawal::STATUS_APPROVED)
            ->assertJsonPath('data.detail.withdrawal.method', ReferralWithdrawal::METHOD_BALANCE)
            ->assertJsonPath('data.detail.withdrawal.amount', '20.00');

        $this->assertSame($this->actionResultWhitelist(), array_keys($response->json('data')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
        $this->assertSame(ReferralWithdrawal::STATUS_APPROVED, (int) $withdrawal->refresh()->status);
        $this->assertSame('23.00', number_format((float) UserAccount::query()->find($withdrawal->user_id)?->cash_balance, 2, '.', ''));
    }

    public function test_referral_withdrawal_rejection_requires_remark_and_returns_compact_result(): void
    {
        $withdrawal = $this->createWithdrawal([
            'amount' => '20.00',
            'method' => ReferralWithdrawal::METHOD_ALIPAY,
        ], [
            'referral_available_balance' => '0.00',
            'referral_pending_withdrawal_balance' => '20.00',
        ]);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::FINANCE_WITHDRAW]));

        $this->postJson('/api/v2/admin/referral-withdrawals/'.$withdrawal->id.'/rejections')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['remark']]]);

        $response = $this->postJson('/api/v2/admin/referral-withdrawals/'.$withdrawal->id.'/rejections', [
            'remark' => '账户信息不完整',
        ], [
            'X-Request-Id' => 'v2-referral-rejection-'.$withdrawal->id,
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $withdrawal->id)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.type', 'rejection')
            ->assertJsonPath('data.detail.withdrawal.status', ReferralWithdrawal::STATUS_REJECTED)
            ->assertJsonPath('data.detail.withdrawal.method', ReferralWithdrawal::METHOD_ALIPAY)
            ->assertJsonPath('data.detail.withdrawal.amount', '20.00');

        $account = UserAccount::query()->find($withdrawal->user_id);

        $this->assertSame($this->actionResultWhitelist(), array_keys($response->json('data')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
        $this->assertSame(ReferralWithdrawal::STATUS_REJECTED, (int) $withdrawal->refresh()->status);
        $this->assertSame('20.00', number_format((float) $account?->referral_available_balance, 2, '.', ''));
        $this->assertSame('0.00', number_format((float) $account?->referral_pending_withdrawal_balance, 2, '.', ''));
    }

    /**
     * @return list<string>
     */
    private function actionResultWhitelist(): array
    {
        return [
            'id',
            'status',
            'message',
            'detail',
        ];
    }

    /**
     * @param  array<string, mixed>  $withdrawalOverrides
     * @param  array<string, mixed>  $accountOverrides
     */
    private function createWithdrawal(array $withdrawalOverrides = [], array $accountOverrides = []): ReferralWithdrawal
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'v2-referral-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'V2 Referral '.$suffix,
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

        UserAccount::query()->create(array_replace([
            'user_id' => (int) $user->id,
            'cash_balance' => '0.00',
            'credit_limit' => '0.00',
            'referral_frozen_balance' => '0.00',
            'referral_available_balance' => '0.00',
            'referral_pending_withdrawal_balance' => '20.00',
            'referral_withdrawn_balance' => '0.00',
            'version' => 0,
        ], $accountOverrides));

        return ReferralWithdrawal::query()->create(array_replace([
            'user_id' => (int) $user->id,
            'amount' => '20.00',
            'method' => ReferralWithdrawal::METHOD_BALANCE,
            'account_name' => 'Sensitive Account Name',
            'account_no' => 'secret-account-number',
            'status' => ReferralWithdrawal::STATUS_PENDING,
            'remark' => 'pending',
            'operator' => '',
            'trace_id' => 'v2-referral-'.$suffix,
            'processed_at' => null,
        ], $withdrawalOverrides));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-referral-action-'.$suffix,
            'label' => 'V2 Referral Action',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-referral-action-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Referral Action',
            'email' => 'v2-referral-action-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                foreach (['password', 'secret', 'api_key', 'account_no', 'account_name', 'raw_response', 'third_party_response'] as $needle) {
                    $this->assertStringNotContainsString($needle, strtolower($key));
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
