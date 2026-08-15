<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Models\AccountTransaction;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\AdminOrderNotificationService;
use App\Services\Finance\CheckoutSecurityService;
use App\Services\Finance\CouponService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentService;
use App\Services\Integrations\Payments\Data\PaymentPrecreateRequest;
use App\Services\Order\PaidOrderBusinessFlowDispatcher;
use App\Services\Provisioning\ProvisionService;
use App\Services\Provisioning\ServiceRenewService;
use App\Services\Referral\ReferralService;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class RechargeStatusBalanceRegressionTest extends TestCase
{
    public function test_query_recharge_status_updates_users_balance_and_balance_log(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'recharge-balance-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Recharge Balance',
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
        $user->forceFill(['balance' => '10.00'])->save();

        $payment = Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $user->id,
            'invoice_id' => null,
            'gateway' => 'alipay',
            'amount' => '5.00',
            'status' => PaymentStatus::PENDING,
        ]);

        $tradeNo = 'TRADE-'.strtoupper(bin2hex(random_bytes(4)));

        $alipayGateway = $this->makeFakePaymentGateway([
            'query' => function (string $outTradeNo) use ($payment, $tradeNo): array {
                $this->assertSame((string) $payment->payment_no, $outTradeNo);

                return [
                    'trade_status' => 'TRADE_SUCCESS',
                    'trade_no' => $tradeNo,
                    'out_trade_no' => (string) $payment->payment_no,
                    'total_amount' => '5.00',
                    'raw' => [
                        'trade_status' => 'TRADE_SUCCESS',
                        'trade_no' => $tradeNo,
                        'out_trade_no' => (string) $payment->payment_no,
                        'total_amount' => '5.00',
                    ],
                ];
            },
        ]);

        $service = new PaymentService(
            $this->createMock(ProvisionService::class),
            $this->makePaymentGatewayManagerForTest($alipayGateway),
            $this->createMock(ServiceRenewService::class),
            $this->createMock(ReferralService::class),
            $this->createMock(PaidOrderBusinessFlowDispatcher::class),
            $this->createMock(AdminOrderNotificationService::class),
            $this->createMock(CouponService::class),
            new InvoiceService,
        );

        $result = $service->queryRechargeStatus($payment);

        $this->assertTrue($result['paid']);
        $this->assertSame($tradeNo, $result['trade_no']);
        $this->assertDatabaseHas('payments', [
            'id' => (int) $payment->id,
            'status' => PaymentStatus::SUCCESS,
            'trade_no' => $tradeNo,
        ]);
        $payment->refresh();
        $this->assertNotNull($payment->invoice_id);
        $this->assertDatabaseHas('invoices', [
            'id' => (int) $payment->invoice_id,
            'user_id' => (int) $user->id,
            'type' => 'recharge',
            'amount' => '5.00',
            'paid_amount' => '5.00',
        ]);
        $this->assertDatabaseHas('user_accounts', [
            'user_id' => (int) $user->id,
            'cash_balance' => '15.00',
        ]);
        $this->assertDatabaseHas('account_transactions', [
            'user_id' => (int) $user->id,
            'event_type' => 'recharge',
            'change_amount' => '5.00',
            'balance_after' => '15.00',
            'source_id' => (int) $payment->id,
        ]);
        $this->assertDatabaseHas('recharge_records', [
            'user_id' => (int) $user->id,
            'invoice_id' => (int) $payment->invoice_id,
            'payment_id' => (int) $payment->id,
            'scene' => 'user_recharge',
            'amount' => '5.00',
            'currency' => 'CNY',
        ]);

        $resultAgain = $service->queryRechargeStatus($payment->fresh());

        $this->assertTrue($resultAgain['paid']);
        $this->assertSame(1, $alipayGateway->countCalls('query'));
        $this->assertSame(1, AccountTransaction::query()
            ->where('user_id', (int) $user->id)
            ->where('event_type', 'recharge')
            ->where('source_id', (int) $payment->id)
            ->count());
        $this->assertSame(1, Invoice::query()
            ->where('user_id', (int) $user->id)
            ->where('type', 'recharge')
            ->where('amount', '5.00')
            ->count());
        $this->assertSame('15.00', User::query()->findOrFail($user->id)->balance);
    }

    public function test_query_recharge_status_supports_non_alipay_third_party_gateway(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'recharge-yipay-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Recharge YiPay',
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
        $user->forceFill(['balance' => '3.00'])->save();

        $payment = Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $user->id,
            'invoice_id' => null,
            'gateway' => PaymentGatewayCode::YIPAY,
            'amount' => '7.00',
            'status' => PaymentStatus::PENDING,
        ]);

        $tradeNo = 'YIPAY-'.strtoupper(bin2hex(random_bytes(4)));
        $gateway = $this->makeFakePaymentGateway([
            'key' => PaymentGatewayCode::YIPAY,
            'query' => function (string $outTradeNo) use ($payment, $tradeNo): array {
                $this->assertSame((string) $payment->payment_no, $outTradeNo);

                return [
                    'trade_status' => 'TRADE_SUCCESS',
                    'trade_no' => $tradeNo,
                    'out_trade_no' => (string) $payment->payment_no,
                    'total_amount' => '7.00',
                    'raw' => [
                        'trade_status' => 'TRADE_SUCCESS',
                        'trade_no' => $tradeNo,
                        'out_trade_no' => (string) $payment->payment_no,
                        'money' => '7.00',
                    ],
                ];
            },
        ]);

        $service = new PaymentService(
            $this->createMock(ProvisionService::class),
            $this->makePaymentGatewayManagerForTest($gateway),
            $this->createMock(ServiceRenewService::class),
            $this->createMock(ReferralService::class),
            $this->createMock(PaidOrderBusinessFlowDispatcher::class),
            $this->createMock(AdminOrderNotificationService::class),
            $this->createMock(CouponService::class),
            new InvoiceService,
        );

        $result = $service->queryRechargeStatus($payment);

        $this->assertTrue($result['paid']);
        $this->assertSame($tradeNo, $result['trade_no']);
        $this->assertDatabaseHas('payments', [
            'id' => (int) $payment->id,
            'gateway_key' => PaymentGatewayCode::YIPAY,
            'status' => PaymentStatus::SUCCESS,
            'trade_no' => $tradeNo,
        ]);

        $payment->refresh();
        $this->assertNotNull($payment->invoice_id);
        $this->assertDatabaseHas('invoices', [
            'id' => (int) $payment->invoice_id,
            'user_id' => (int) $user->id,
            'type' => 'recharge',
            'amount' => '7.00',
            'paid_amount' => '7.00',
        ]);
        $this->assertDatabaseHas('user_accounts', [
            'user_id' => (int) $user->id,
            'cash_balance' => '10.00',
        ]);
    }

    public function test_recharge_by_gateway_does_not_reuse_pending_payment_across_yipay_payment_types(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'recharge-yipay-type-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Recharge YiPay Type',
            'real_name' => '测试用户',
            'id_card' => '110101199001010010',
            'is_verified' => 1,
            'verification_status' => 2,
            'verified_at' => now(),
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
        ]);

        $precreateRequests = [];
        $gateway = $this->makeFakePaymentGateway([
            'key' => PaymentGatewayCode::YIPAY,
            'precreate' => function (PaymentPrecreateRequest $request) use (&$precreateRequests): array {
                $paymentType = (string) ($request->context['payment_type'] ?? '');
                $precreateRequests[] = [
                    'out_trade_no' => $request->outTradeNo,
                    'payment_type' => $paymentType,
                    'timeout_express' => $request->timeoutExpress,
                ];

                return [
                    'qr_code' => 'https://pay.example.test/'.$paymentType.'/'.$request->outTradeNo,
                    'out_trade_no' => $request->outTradeNo,
                ];
            },
        ]);

        $service = new PaymentService(
            $this->createMock(ProvisionService::class),
            $this->makePaymentGatewayManagerForTest($gateway),
            $this->createMock(ServiceRenewService::class),
            $this->createMock(ReferralService::class),
            $this->createMock(PaidOrderBusinessFlowDispatcher::class),
            $this->createMock(AdminOrderNotificationService::class),
            $this->createMock(CouponService::class),
            new InvoiceService,
        );

        $wxpay = $service->rechargeByGateway($user, 20.00, PaymentGatewayCode::YIPAY, ['payment_type' => 'wxpay']);
        $alipay = $service->rechargeByGateway($user, 20.00, PaymentGatewayCode::YIPAY, ['payment_type' => 'alipay']);

        $this->assertNotSame($wxpay['payment_no'], $alipay['payment_no']);
        $this->assertSame([
            ['out_trade_no' => $wxpay['payment_no'], 'payment_type' => 'wxpay', 'timeout_express' => '5m'],
            ['out_trade_no' => $alipay['payment_no'], 'payment_type' => 'alipay', 'timeout_express' => '5m'],
        ], $precreateRequests);

        $payments = Payment::query()
            ->where('user_id', (int) $user->id)
            ->whereGatewayKey(PaymentGatewayCode::YIPAY)
            ->where('status', PaymentStatus::PENDING)
            ->where('amount', '20.00')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $payments);
        $this->assertSame(['wxpay', 'alipay'], $payments
            ->map(fn (Payment $payment): string => (string) data_get((array) $payment->callback_raw, 'payment_type'))
            ->all());
    }

    public function test_recharge_poll_token_expires_at_payment_created_deadline(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'recharge-poll-expiry-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Recharge Poll Expiry',
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
        $createdAt = now()->subMinutes(2)->startOfSecond();

        $payment = Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $user->id,
            'invoice_id' => null,
            'gateway' => PaymentGatewayCode::YIPAY,
            'amount' => '20.00',
            'status' => PaymentStatus::PENDING,
        ]);
        $payment->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        $payload = app(CheckoutSecurityService::class)->issueRechargePollToken($payment->refresh(), (int) $user->id);

        $this->assertSame($createdAt->copy()->addMinutes(5)->toIso8601String(), $payload['poll_expires_at']);
    }

    public function test_query_recharge_status_cancels_expired_unpaid_recharge(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'recharge-expired-unpaid-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Recharge Expired Unpaid',
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
        $user->forceFill(['balance' => '10.00'])->save();

        $payment = Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $user->id,
            'invoice_id' => null,
            'gateway' => PaymentGatewayCode::YIPAY,
            'amount' => '5.00',
            'status' => PaymentStatus::PENDING,
        ]);
        $createdAt = now()->subMinutes(6)->startOfSecond();
        $payment->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        $gateway = $this->makeFakePaymentGateway([
            'key' => PaymentGatewayCode::YIPAY,
            'query' => function (string $outTradeNo) use ($payment): array {
                $this->assertSame((string) $payment->payment_no, $outTradeNo);

                return [
                    'trade_status' => 'WAIT_BUYER_PAY',
                    'trade_no' => '',
                    'out_trade_no' => (string) $payment->payment_no,
                    'raw' => [
                        'trade_status' => 'WAIT_BUYER_PAY',
                        'out_trade_no' => (string) $payment->payment_no,
                    ],
                ];
            },
        ]);

        $service = new PaymentService(
            $this->createMock(ProvisionService::class),
            $this->makePaymentGatewayManagerForTest($gateway),
            $this->createMock(ServiceRenewService::class),
            $this->createMock(ReferralService::class),
            $this->createMock(PaidOrderBusinessFlowDispatcher::class),
            $this->createMock(AdminOrderNotificationService::class),
            $this->createMock(CouponService::class),
            new InvoiceService,
        );

        $result = $service->queryRechargeStatus($payment->refresh());

        $this->assertFalse($result['paid']);
        $this->assertSame(PaymentStatus::CANCELLED, $result['status']);
        $this->assertSame('已取消', $result['status_label']);
        $this->assertDatabaseHas('payments', [
            'id' => (int) $payment->id,
            'status' => PaymentStatus::CANCELLED,
            'invoice_id' => null,
        ]);
        $callbackRaw = (array) $payment->refresh()->callback_raw;
        $this->assertTrue((bool) data_get($callbackRaw, 'payment_window_expired'));
        $this->assertSame(0, Invoice::query()
            ->where('user_id', (int) $user->id)
            ->where('type', 'recharge')
            ->count());
        $this->assertSame(0, AccountTransaction::query()
            ->where('user_id', (int) $user->id)
            ->where('event_type', 'recharge')
            ->where('source_id', (int) $payment->id)
            ->count());
    }

    public function test_recharge_completion_rolls_back_when_recharge_invoice_creation_fails(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'recharge-rollback-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Recharge Rollback',
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
        $user->forceFill(['balance' => '10.00'])->save();

        $payment = Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $user->id,
            'invoice_id' => null,
            'gateway' => 'alipay',
            'amount' => '5.00',
            'status' => PaymentStatus::PENDING,
        ]);

        $tradeNo = 'TRADE-ROLLBACK-'.strtoupper(bin2hex(random_bytes(4)));
        $alipayGateway = $this->makeFakePaymentGateway([
            'query' => function (string $outTradeNo) use ($payment, $tradeNo): array {
                $this->assertSame((string) $payment->payment_no, $outTradeNo);

                return [
                    'trade_status' => 'TRADE_SUCCESS',
                    'trade_no' => $tradeNo,
                    'out_trade_no' => (string) $payment->payment_no,
                    'total_amount' => '5.00',
                    'raw' => [
                        'trade_status' => 'TRADE_SUCCESS',
                        'trade_no' => $tradeNo,
                        'out_trade_no' => (string) $payment->payment_no,
                        'total_amount' => '5.00',
                    ],
                ];
            },
        ]);

        $invoiceService = new class extends InvoiceService
        {
            public function createForRecharge(User $user, float $amount, ?Payment $payment = null, ?string $remark = null, ?string $traceId = null): Invoice
            {
                throw new \RuntimeException('invoice create failed');
            }
        };

        $service = new PaymentService(
            $this->createMock(ProvisionService::class),
            $this->makePaymentGatewayManagerForTest($alipayGateway),
            $this->createMock(ServiceRenewService::class),
            $this->createMock(ReferralService::class),
            $this->createMock(PaidOrderBusinessFlowDispatcher::class),
            $this->createMock(AdminOrderNotificationService::class),
            $this->createMock(CouponService::class),
            $invoiceService,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('invoice create failed');

        try {
            $service->queryRechargeStatus($payment);
        } finally {
            $this->assertSame(1, $alipayGateway->countCalls('query'));
            $this->assertDatabaseHas('payments', [
                'id' => (int) $payment->id,
                'status' => PaymentStatus::PENDING,
                'invoice_id' => null,
            ]);
            $this->assertSame('10.00', User::query()->findOrFail($user->id)->balance);
            $this->assertSame(0, AccountTransaction::query()
                ->where('user_id', (int) $user->id)
                ->where('event_type', 'recharge')
                ->where('source_id', (int) $payment->id)
                ->count());
        }
    }

    public function test_recharge_status_endpoint_finds_paid_recharge_after_invoice_projection_is_created(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'recharge-http-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Recharge Http',
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
        $user->forceFill(['balance' => '15.00'])->save();
        $tradeNo = 'TRADE-HTTP-RECHARGE-'.strtoupper(bin2hex(random_bytes(4)));

        $payment = Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $user->id,
            'invoice_id' => null,
            'gateway' => 'alipay',
            'trade_no' => $tradeNo,
            'amount' => '5.00',
            'status' => PaymentStatus::SUCCESS,
            'paid_at' => now(),
        ]);

        $this->assertNull($payment->invoice_id);

        $security = app(CheckoutSecurityService::class)->issueRechargePollToken($payment, (int) $user->id);
        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/recharge/'.$payment->payment_no.'/status?poll_token='.$security['poll_token'])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.paid', true)
            ->assertJsonPath('data.trade_no', $tradeNo);

        $payment->refresh();
        $this->assertNotNull($payment->invoice_id);
        $this->assertInstanceOf(Invoice::class, Invoice::query()->find((int) $payment->invoice_id));
    }

    public function test_query_recharge_status_rejects_mismatched_query_amount(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'recharge-amount-mismatch-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Recharge Amount Mismatch',
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
        $user->forceFill(['balance' => '10.00'])->save();

        $payment = Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $user->id,
            'invoice_id' => null,
            'gateway' => 'alipay',
            'amount' => '5.00',
            'status' => PaymentStatus::PENDING,
        ]);

        $tradeNo = 'TRADE-MISMATCH-'.strtoupper(bin2hex(random_bytes(4)));
        $alipayGateway = $this->makeFakePaymentGateway([
            'query' => function (string $outTradeNo) use ($payment, $tradeNo): array {
                return [
                    'trade_status' => 'TRADE_SUCCESS',
                    'trade_no' => $tradeNo,
                    'out_trade_no' => (string) $payment->payment_no,
                    // 网关返回金额与支付单不一致：轮询路径必须拒绝入账
                    'total_amount' => '50.00',
                    'raw' => [
                        'trade_status' => 'TRADE_SUCCESS',
                        'trade_no' => $tradeNo,
                        'out_trade_no' => (string) $payment->payment_no,
                        'total_amount' => '50.00',
                    ],
                ];
            },
        ]);

        $service = new PaymentService(
            $this->createMock(ProvisionService::class),
            $this->makePaymentGatewayManagerForTest($alipayGateway),
            $this->createMock(ServiceRenewService::class),
            $this->createMock(ReferralService::class),
            $this->createMock(PaidOrderBusinessFlowDispatcher::class),
            $this->createMock(AdminOrderNotificationService::class),
            $this->createMock(CouponService::class),
            new InvoiceService,
        );

        $result = $service->queryRechargeStatus($payment);

        $this->assertFalse($result['paid']);
        $this->assertDatabaseHas('payments', [
            'id' => (int) $payment->id,
            'status' => PaymentStatus::PENDING,
            'invoice_id' => null,
        ]);
        $this->assertSame('10.00', User::query()->findOrFail($user->id)->balance);
        $this->assertSame(0, AccountTransaction::query()
            ->where('user_id', (int) $user->id)
            ->where('event_type', 'recharge')
            ->where('source_id', (int) $payment->id)
            ->count());
    }
}
