<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\OrderStatus;
use App\Models\AdminUser;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralReward;
use App\Models\ReferralWithdrawal;
use App\Models\Role;
use App\Models\User;
use App\Models\UserAccount;
use App\Models\UserReferral;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2AdminReferralReadApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_referral_overview_and_rewards_use_v2_projection(): void
    {
        ['referrer' => $referrer, 'reward' => $reward] = $this->createReferralFixture();

        $this->getJson('/api/v2/admin/referral/overview')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([]));

        $this->getJson('/api/v2/admin/referral/overview')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::REFERRAL_LIST]));

        $this->getJson('/api/v2/admin/referral/overview?per_page=20&pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page', 'pageSize']]]);

        $overviewResponse = $this->getJson('/api/v2/admin/referral/overview')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.top_referrers.0.id', $referrer->id);

        $this->assertGreaterThanOrEqual(1, (int) $overviewResponse->json('data.summary.rewards_total'));
        $this->assertSame(['summary', 'top_referrers'], array_keys($overviewResponse->json('data')));
        $this->assertSame($this->overviewSummaryWhitelist(), array_keys($overviewResponse->json('data.summary')));
        $this->assertSame($this->topReferrerWhitelist(), array_keys($overviewResponse->json('data.top_referrers.0')));
        $this->assertNoSensitiveKeys($overviewResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $overviewResponse->getContent()));

        $this->getJson('/api/v2/admin/referral/rewards?per_page=20&pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page', 'pageSize']]]);

        $rewardsResponse = $this->getJson('/api/v2/admin/referral/rewards?'.http_build_query([
            'status' => ReferralReward::STATUS_REWARDED,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', $reward->id)
            ->assertJsonPath('data.list.0.referrer.id', $referrer->id)
            ->assertJsonMissingPath('data.list.0.trace_id')
            ->assertJsonMissingPath('data.list.0.raw_response');

        $this->assertSame(['list', 'total', 'page', 'page_size'], array_keys($rewardsResponse->json('data')));
        $this->assertSame($this->rewardWhitelist(), array_keys($rewardsResponse->json('data.list.0')));
        $this->assertSame($this->userWhitelist(), array_keys($rewardsResponse->json('data.list.0.referrer')));
        $this->assertNoSensitiveKeys($rewardsResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $rewardsResponse->getContent()));
    }

    public function test_referral_withdrawals_use_dedicated_list_permission_and_masked_projection(): void
    {
        ['withdrawal' => $withdrawal] = $this->createReferralFixture();

        $this->getJson('/api/v2/admin/referral-withdrawals')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::REFERRAL_LIST]));

        $this->getJson('/api/v2/admin/referral-withdrawals')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::REFERRAL_WITHDRAWAL_LIST]));

        $this->getJson('/api/v2/admin/referral-withdrawals?per_page=20&pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page', 'pageSize']]]);

        $response = $this->getJson('/api/v2/admin/referral-withdrawals?'.http_build_query([
            'status' => ReferralWithdrawal::STATUS_PENDING,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', $withdrawal->id)
            ->assertJsonPath('data.list.0.status', ReferralWithdrawal::STATUS_PENDING)
            ->assertJsonMissingPath('data.list.0.trace_id')
            ->assertJsonMissingPath('data.list.0.raw_response');

        $content = (string) $response->getContent();

        $this->assertSame(['list', 'total', 'page', 'page_size'], array_keys($response->json('data')));
        $this->assertSame($this->withdrawalWhitelist(), array_keys($response->json('data.list.0')));
        $this->assertSame($this->userWhitelist(), array_keys($response->json('data.list.0.user')));
        $this->assertStringNotContainsString('secret-account-number', $content);
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen($content));
    }

    /**
     * @return array{referrer: User, referred: User, reward: ReferralReward, withdrawal: ReferralWithdrawal}
     */
    private function createReferralFixture(): array
    {
        $this->resetReferralFixtureState();

        $suffix = bin2hex(random_bytes(4));
        $referrer = $this->createUser('referrer-'.$suffix, [
            'total_sales_amount' => '999999.00',
        ]);
        $referred = $this->createUser('referred-'.$suffix);
        $product = Product::query()->create([
            'custom_display_name' => 'V2 Referral Product '.$suffix,
            'product_type' => 'vps',
            'service_type_code' => 'vps',
            'pricing' => ['monthly' => '100.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => ['secret' => 'must-not-leak'],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 1,
            'auto_setup' => 0,
        ]);
        $order = Order::query()->create([
            'order_no' => 'V2REF'.$suffix,
            'user_id' => (int) $referred->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => 'V2 Referral Product '.$suffix,
            'product_type_snapshot' => 'vps',
            'type' => 'new',
            'amount' => '100.00',
            'discount' => '0.00',
            'paid_amount' => '100.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'config_snapshot' => [
                'password' => 'must-not-leak',
                'api_key' => 'must-not-leak',
            ],
            'status' => OrderStatus::PAID,
            'paid_at' => now(),
            'trace_id' => 'trace-order-secret-'.$suffix,
        ]);

        UserAccount::query()->create([
            'user_id' => (int) $referrer->id,
            'cash_balance' => '0.00',
            'credit_limit' => '0.00',
            'referral_frozen_balance' => '1.00',
            'referral_available_balance' => '5.00',
            'referral_pending_withdrawal_balance' => '2.00',
            'referral_withdrawn_balance' => '3.00',
            'version' => 0,
        ]);

        $reward = ReferralReward::query()->create([
            'referrer_user_id' => (int) $referrer->id,
            'referred_user_id' => (int) $referred->id,
            'order_id' => (int) $order->id,
            'invoice_id' => null,
            'product_id' => (int) $product->id,
            'order_amount' => '100.00',
            'reward_rate' => '5.00',
            'reward_amount' => '5.00',
            'available_at' => now()->addDay(),
            'released_at' => now(),
            'status' => ReferralReward::STATUS_REWARDED,
            'operator' => 'system',
            'remark' => 'released',
            'trace_id' => 'trace-secret-'.$suffix,
            'rewarded_at' => now()->addYears(5),
        ]);

        $withdrawal = ReferralWithdrawal::query()->create([
            'user_id' => (int) $referrer->id,
            'amount' => '2.00',
            'method' => ReferralWithdrawal::METHOD_ALIPAY,
            'account_name' => 'Sensitive Account Name',
            'account_no' => 'secret-account-number',
            'status' => ReferralWithdrawal::STATUS_PENDING,
            'remark' => 'pending',
            'operator' => '',
            'trace_id' => 'withdrawal-secret-'.$suffix,
            'processed_at' => null,
        ]);

        return [
            'referrer' => $referrer,
            'referred' => $referred,
            'reward' => $reward,
            'withdrawal' => $withdrawal,
        ];
    }

    private function resetReferralFixtureState(): void
    {
        ReferralReward::query()->delete();
        ReferralWithdrawal::query()->delete();

        User::query()
            ->where('total_sales_amount', '>', 0)
            ->update(['total_sales_amount' => '0.00']);

        if (Schema::hasTable('user_referrals')) {
            UserReferral::query()
                ->where(function ($query) {
                    $query
                        ->where('total_sales_amount', '>', 0)
                        ->orWhereNotNull('member_level_id');
                })
                ->update([
                    'total_sales_amount' => '0.00',
                    'member_level_id' => null,
                ]);
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createUser(string $suffix, array $overrides = []): User
    {
        return User::query()->create(array_replace([
            'email' => 'v2-referral-read-'.$suffix.'@example.com',
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
        ], $overrides));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-referral-read-'.$suffix,
            'label' => 'V2 Referral Read',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-referral-read-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Referral Read',
            'email' => 'v2-referral-read-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    /**
     * @return list<string>
     */
    private function overviewSummaryWhitelist(): array
    {
        return [
            'rewards_total',
            'total_sales_amount',
            'total_reward_amount',
            'frozen_amount',
            'released_amount',
            'available_amount',
            'withdrawals_total',
            'withdrawing_amount',
            'withdrawn_amount',
            'rejected_amount',
            'direct_referral_users',
        ];
    }

    /**
     * @return list<string>
     */
    private function topReferrerWhitelist(): array
    {
        return [
            'id',
            'email',
            'nickname',
            'display_name',
            'member_level',
            'total_sales_amount',
            'referral_frozen_amount',
            'referral_available_amount',
            'referral_withdrawing_amount',
            'referral_withdrawn_amount',
        ];
    }

    /**
     * @return list<string>
     */
    private function rewardWhitelist(): array
    {
        return [
            'id',
            'status',
            'order_amount',
            'reward_rate',
            'reward_amount',
            'available_at',
            'released_at',
            'rewarded_at',
            'remark',
            'referrer',
            'referred_user',
            'order',
            'product',
        ];
    }

    /**
     * @return list<string>
     */
    private function withdrawalWhitelist(): array
    {
        return [
            'id',
            'amount',
            'method',
            'account_name',
            'account_no',
            'status',
            'status_label',
            'payment_no',
            'remark',
            'operator',
            'paid_at',
            'created_at',
            'processed_at',
            'user',
        ];
    }

    /**
     * @return list<string>
     */
    private function userWhitelist(): array
    {
        return [
            'id',
            'email',
            'nickname',
            'display_name',
        ];
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response'] as $needle) {
                    $this->assertStringNotContainsString($needle, strtolower($key));
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
