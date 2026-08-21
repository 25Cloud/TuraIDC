<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\ReferralReward;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserAccount;
use App\Services\Finance\PaymentService;
use App\Services\Referral\ReferralService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReferralRewardLifecycleRegressionTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $referralSettingSnapshot = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->referralSettingSnapshot = Setting::query()
            ->where('group_key', 'referral')
            ->whereIn('item_key', ['enabled', 'reward_rate'])
            ->pluck('item_value', 'item_key')
            ->all();
        Setting::setValues('referral', [
            'enabled' => '1',
            'reward_rate' => '10',
        ]);
    }

    protected function tearDown(): void
    {
        Setting::query()
            ->where('group_key', 'referral')
            ->whereIn('item_key', ['enabled', 'reward_rate'])
            ->delete();

        foreach ($this->referralSettingSnapshot as $key => $value) {
            Setting::setValue('referral', (string) $key, $value);
        }
        Setting::forgetCachedGroup('referral');

        parent::tearDown();
    }

    public function test_reward_creation_and_overview_still_work_when_users_referrer_columns_are_stale(): void
    {
        $this->ensureReferralSupportTables();

        if (! Schema::hasTable('user_referrals')) {
            $this->markTestSkipped('user_referrals table is required for this regression test.');
        }

        $suffix = bin2hex(random_bytes(4));
        $referrer = $this->createReferralUser('referrer-'.$suffix);
        $buyer = $this->createReferralUser('buyer-'.$suffix);
        $service = app(ReferralService::class);

        $referralCode = $service->ensureReferralCode($referrer);
        $service->bindReferrer($buyer, $referralCode, ['ip' => '203.0.113.10']);

        $buyer->refresh();
        $this->assertSame((int) $referrer->id, (int) $buyer->referrer_user_id);
        $this->assertDatabaseHas('user_referrals', [
            'user_id' => (int) $buyer->id,
            'referrer_user_id' => (int) $referrer->id,
        ]);

        User::query()
            ->whereKey((int) $buyer->id)
            ->update([
                'referrer_user_id' => null,
                'referred_at' => null,
            ]);

        $order = Order::query()->create([
            'order_no' => 'REFLIFE'.strtoupper($suffix),
            'user_id' => (int) $buyer->id,
            'product_id' => null,
            'product_spec_snapshot' => 'Referral Lifecycle Spec',
            'product_type_snapshot' => 'server',
            'type' => 'new',
            'amount' => '100.00',
            'discount' => '0.00',
            'paid_amount' => '100.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'status' => OrderStatus::PAID,
            'paid_at' => now(),
            'trace_id' => 'ref-life-order-'.$suffix,
        ]);

        $reward = $service->rewardForPaidOrder($order, 'reward-life-'.$suffix);
        $overview = $service->overview($referrer->fresh());

        $this->assertInstanceOf(ReferralReward::class, $reward);
        $this->assertSame((int) $referrer->id, (int) $reward->referrer_user_id);
        $this->assertSame(1, $overview['direct_referral_count']);
        $this->assertSame(1, $overview['rewarded_orders_count']);
    }

    public function test_refund_order_to_balance_reverses_released_referral_reward_and_sales_aggregate(): void
    {
        $this->ensureReferralSupportTables();

        $suffix = bin2hex(random_bytes(4));
        ['referrer' => $referrer, 'order' => $order, 'reward' => $reward] = $this->createPaidReferralOrderContext($suffix);
        $service = app(ReferralService::class);

        $reward->forceFill([
            'available_at' => now()->subMinute(),
        ])->save();
        $service->releaseMaturedRewards($referrer);

        $result = app(PaymentService::class)->refundOrder($order, [
            'refund_method' => 'balance',
            'remark' => 'referral reward reverse regression',
        ], [
            'operator_id' => 1,
            'operator_name' => 'tester',
            'trace_id' => 'refund-life-'.$suffix,
        ]);

        $referrer->refresh()->load('account');
        $reward->refresh();

        $this->assertFalse((bool) ($result['already_refunded'] ?? false));
        $this->assertSame(ReferralReward::STATUS_REVERSED, (int) $reward->status);
        $this->assertSame('0.00', $referrer->total_sales_amount);
        $this->assertSame('0.00', $referrer->referral_available_amount);
        $this->assertDatabaseHas('orders', [
            'id' => (int) $order->id,
            'status' => OrderStatus::REFUNDED,
        ]);
        $this->assertDatabaseHas('account_transactions', [
            'user_id' => (int) $referrer->id,
            'event_type' => ReferralService::ACCOUNT_LOG_TYPE_REWARD_REVERSED,
            'source_id' => (int) $reward->id,
        ]);
    }

    public function test_refund_is_blocked_when_released_referral_reward_is_no_longer_available(): void
    {
        $this->ensureReferralSupportTables();

        $suffix = bin2hex(random_bytes(4));
        ['referrer' => $referrer, 'order' => $order, 'reward' => $reward] = $this->createPaidReferralOrderContext($suffix);
        $service = app(ReferralService::class);

        $reward->forceFill([
            'available_at' => now()->subMinute(),
        ])->save();
        $service->releaseMaturedRewards($referrer);

        UserAccount::query()->updateOrCreate(
            ['user_id' => (int) $referrer->id],
            [
                'referral_frozen_balance' => '0.00',
                'referral_available_balance' => '0.00',
                'referral_pending_withdrawal_balance' => '0.00',
                'referral_withdrawn_balance' => '10.00',
            ]
        );

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('推广返利资金');

        try {
            app(PaymentService::class)->refundOrder($order, [
                'refund_method' => 'balance',
                'remark' => 'should block referral refund',
            ], [
                'operator_id' => 1,
                'operator_name' => 'tester',
                'trace_id' => 'refund-block-'.$suffix,
            ]);
        } finally {
            $this->assertDatabaseHas('orders', [
                'id' => (int) $order->id,
                'status' => OrderStatus::PAID,
            ]);
        }
    }

    /**
     * @return array{referrer:User,buyer:User,order:Order,invoice:Invoice,payment:Payment,reward:ReferralReward}
     */
    private function createPaidReferralOrderContext(string $suffix): array
    {
        $referrer = $this->createReferralUser('refund-referrer-'.$suffix);
        $buyer = $this->createReferralUser('refund-buyer-'.$suffix);
        $referralService = app(ReferralService::class);

        $referralCode = $referralService->ensureReferralCode($referrer);
        $referralService->bindReferrer($buyer, $referralCode, ['ip' => '203.0.113.20']);

        $order = Order::query()->create([
            'order_no' => 'RFD'.strtoupper($suffix),
            'user_id' => (int) $buyer->id,
            'product_id' => null,
            'product_spec_snapshot' => 'Refund Referral Spec',
            'product_type_snapshot' => 'server',
            'type' => 'new',
            'amount' => '100.00',
            'discount' => '0.00',
            'paid_amount' => '100.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'status' => OrderStatus::PAID,
            'paid_at' => now(),
            'trace_id' => 'refund-order-'.$suffix,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'INVREF'.strtoupper($suffix),
            'user_id' => (int) $buyer->id,
            'order_id' => (int) $order->id,
            'type' => 'normal',
            'amount' => '100.00',
            'paid_amount' => '100.00',
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'due_date' => now()->addDay(),
        ]);

        $paymentNo = Payment::generatePaymentNo();
        Payment::query()->create([
            'payment_no' => $paymentNo,
            'user_id' => (int) $buyer->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => PaymentGatewayCode::ALIPAY,
            'amount' => '100.00',
            'status' => PaymentStatus::SUCCESS,
            'callback_raw' => [
                'source' => 'alipay_test',
                'trade_no' => 'ALIPAY_TEST_'.$suffix,
                'trace_id' => 'refund-payment-'.$suffix,
            ],
            'trade_no' => 'ALIPAY_TEST_'.$suffix,
            'paid_at' => now(),
        ]);
        $payment = Payment::query()->where('payment_no', $paymentNo)->firstOrFail();

        $reward = $referralService->rewardForPaidOrder($order, 'reward-refund-'.$suffix);

        return [
            'referrer' => $referrer->fresh(),
            'buyer' => $buyer->fresh(),
            'order' => $order->fresh(),
            'invoice' => $invoice->fresh(),
            'payment' => $payment->fresh(),
            'reward' => $reward instanceof ReferralReward ? $reward->fresh() : ReferralReward::query()->where('order_id', (int) $order->id)->firstOrFail(),
        ];
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
            'last_login_ip' => '198.51.100.'.random_int(10, 99),
        ]);
    }

    private function ensureReferralSupportTables(): void
    {
        if (! Schema::hasTable('user_accounts')) {
            Schema::create('user_accounts', function (Blueprint $table): void {
                $table->unsignedBigInteger('user_id')->primary();
                $table->decimal('cash_balance', 12, 2)->default(0);
                $table->decimal('credit_limit', 12, 2)->default(0);
                $table->decimal('referral_frozen_balance', 12, 2)->default(0);
                $table->decimal('referral_available_balance', 12, 2)->default(0);
                $table->decimal('referral_pending_withdrawal_balance', 12, 2)->default(0);
                $table->decimal('referral_withdrawn_balance', 12, 2)->default(0);
                $table->unsignedInteger('version')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('account_transactions')) {
            Schema::create('account_transactions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('account_type', 30);
                $table->string('event_type', 30);
                $table->decimal('change_amount', 12, 2)->default(0);
                $table->decimal('balance_after', 12, 2)->default(0);
                $table->string('source_type', 30)->nullable();
                $table->unsignedBigInteger('source_id')->nullable();
                $table->string('origin_type', 30)->nullable();
                $table->unsignedBigInteger('origin_id')->nullable();
                $table->string('remark', 255)->nullable();
                $table->string('operator', 50)->nullable();
                $table->string('trace_id', 64)->nullable();
                $table->timestamps();
            });
        }
    }
}
