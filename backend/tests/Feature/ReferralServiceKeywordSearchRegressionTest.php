<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountTransaction;
use App\Models\FirstProductGroup;
use App\Models\Order;
use App\Models\Product;
use App\Models\ReferralReward;
use App\Models\ReferralWithdrawal;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use App\Models\User;
use App\Services\Referral\ReferralService;
use Tests\TestCase;

class ReferralServiceKeywordSearchRegressionTest extends TestCase
{
    public function test_admin_reward_logs_keyword_search_matches_order_number_without_nested_relation_filters(): void
    {
        $suffix = bin2hex(random_bytes(4));
        [$referrer, $referred, $product, $order] = $this->createReferralRewardContext($suffix);

        $reward = ReferralReward::query()->create([
            'referrer_user_id' => (int) $referrer->id,
            'referred_user_id' => (int) $referred->id,
            'order_id' => (int) $order->id,
            'product_id' => (int) $product->id,
            'order_amount' => '99.00',
            'reward_rate' => '10.00',
            'reward_amount' => '9.90',
            'status' => ReferralReward::STATUS_FROZEN,
            'operator' => 'system',
            'remark' => 'reward-search-regression',
            'trace_id' => 'reward-search-'.$suffix,
            'rewarded_at' => now(),
        ]);

        $paginator = app(ReferralService::class)->adminRewardLogs([
            'keyword' => (string) $order->order_no,
        ], 20);

        $this->assertSame(1, $paginator->total());
        $this->assertSame((int) $reward->id, (int) optional($paginator->items()[0] ?? null)->id);
    }

    public function test_admin_account_logs_and_withdrawals_keyword_search_match_user_email(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createReferralUser('referral-user-'.$suffix);

        $accountLog = AccountTransaction::query()->create([
            'user_id' => (int) $user->id,
            'account_type' => 'referral_available',
            'event_type' => 'reward_released',
            'change_amount' => '12.00',
            'balance_after' => '12.00',
            'source_type' => 'reward',
            'source_id' => 1001,
            'origin_type' => 'referral_event',
            'origin_id' => 1001,
            'remark' => 'account-search-regression',
            'operator' => 'system',
            'trace_id' => 'account-search-'.$suffix,
        ]);

        $withdrawal = ReferralWithdrawal::query()->create([
            'user_id' => (int) $user->id,
            'amount' => '8.00',
            'method' => ReferralWithdrawal::METHOD_ALIPAY,
            'account_name' => 'Search User',
            'account_no' => '13800138000',
            'status' => ReferralWithdrawal::STATUS_PENDING,
            'remark' => 'withdraw-search-regression',
            'operator' => 'client',
            'trace_id' => 'withdraw-search-'.$suffix,
        ]);

        $service = app(ReferralService::class);

        $accountPaginator = $service->adminAccountLogs([
            'keyword' => (string) $user->email,
        ], 20);
        $withdrawalPaginator = $service->adminWithdrawalList([
            'keyword' => (string) $user->email,
        ], 20);

        $this->assertSame(1, $accountPaginator->total());
        $this->assertSame((int) $accountLog->id, (int) optional($accountPaginator->items()[0] ?? null)->id);
        $this->assertSame(1, $withdrawalPaginator->total());
        $this->assertSame((int) $withdrawal->id, (int) optional($withdrawalPaginator->items()[0] ?? null)->id);
    }

    /**
     * @return array{0:User,1:User,2:Product,3:Order}
     */
    private function createReferralRewardContext(string $suffix): array
    {
        $referrer = $this->createReferralUser('referrer-'.$suffix);
        $referred = $this->createReferralUser('referred-'.$suffix);

        $groupIds = $this->createProductGroupIds('referral-group-'.$suffix, 'Referral Group '.$suffix);

        $product = Product::query()->create([
            'product_group_id' => $groupIds['third'],
            'name' => 'Referral Product '.$suffix,
            'product_type' => 'server',
            'description' => '',
            'pricing' => ['monthly' => '99.00'],
            'purchase_requires' => [],
            'config_options' => [],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 0,
            'provision_module' => null,
            'auto_setup' => 0,
        ]);

        $order = Order::query()->create([
            'order_no' => 'RW'.strtoupper($suffix),
            'user_id' => (int) $referred->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => '未配置规格 #'.(int) $product->id,
            'product_type_snapshot' => (string) $product->product_type,
            'type' => 'new',
            'amount' => '99.00',
            'discount' => '0.00',
            'paid_amount' => '99.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'status' => 1,
            'paid_at' => now(),
            'trace_id' => 'order-search-'.$suffix,
        ]);

        return [$referrer, $referred, $product, $order];
    }

    private function createReferralUser(string $prefix): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => $prefix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Referral User '.$suffix,
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
    }

    /**
     * @return array{first:int,second:int,third:int}
     */
    private function createProductGroupIds(string $slug, string $name): array
    {
        $first = FirstProductGroup::query()->firstOrCreate(
            ['code' => 'server'],
            [
                'name' => 'Server',
                'slug' => 'referral-first-server',
                'sort_order' => 0,
                'is_visible' => 1,
                'is_system' => 0,
                'legacy_product_type' => 'server',
            ]
        );

        if ((int) $first->is_visible !== 1) {
            $first->update(['is_visible' => 1]);
        }

        $second = SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $first->id,
            'name' => $name,
            'slug' => $slug,
            'description' => '',
            'sort_order' => 0,
            'is_visible' => 1,
        ]);
        $third = ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $second->id,
            'name' => $name.' Leaf',
            'slug' => $slug.'-leaf',
            'description' => '',
            'sort_order' => 0,
            'is_visible' => 1,
        ]);

        return [
            'first' => (int) $first->id,
            'second' => (int) $second->id,
            'third' => (int) $third->id,
        ];
    }
}
