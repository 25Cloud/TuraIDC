<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\FinanceLedgerEventType;
use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Contracts\Integrations\Payments\PaymentGatewayInterface;
use App\Exceptions\BusinessException;
use App\Models\AccountTransaction;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Automation\InvoiceCleanupAutomationService;
use App\Services\Finance\AdminOrderNotificationService;
use App\Services\Finance\CheckoutSecurityService;
use App\Services\Finance\CheckoutService;
use App\Services\Finance\CouponService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentService;
use App\Services\Integrations\Payments\Data\PaymentPrecreateRequest;
use App\Services\Order\OrderService;
use App\Services\Order\PaidOrderBusinessFlowDispatcher;
use App\Services\Provisioning\ProvisionService;
use App\Services\Provisioning\ServiceRenewService;
use App\Services\Referral\ReferralService;
use App\Services\System\SettingService;
use App\Services\User\UserService;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class OrderPaymentOrderBindingRegressionTest extends TestCase
{
    /**
     * @return array{0: User, 1: Order, 2: Invoice}
     */
    private function createUserOrderInvoice(string $prefix, string $amount = '50.00', string $balance = '30.00'): array
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => strtolower($prefix).'-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => $prefix,
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
        $user->forceFill(['balance' => $balance])->save();

        $order = Order::query()->create([
            'order_no' => strtoupper($prefix).'ORD'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'type' => 'new',
            'amount' => $amount,
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'status' => OrderStatus::PENDING,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => strtoupper($prefix).'INV'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'type' => 'normal',
            'amount' => $amount,
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::UNPAID,
            'due_date' => now()->addDay(),
        ]);

        return [$user, $order, $invoice];
    }

    public function test_invoice_payment_session_deadline_is_fixed_from_invoice_created_at(): void
    {
        [$user, , $invoice] = $this->createUserOrderInvoice('fixedwindow');
        $createdAt = now()->subMinutes(2)->startOfSecond();
        $invoice->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        $firstPayload = app(CheckoutSecurityService::class)->issueInvoicePaymentSession($invoice->refresh(), (int) $user->id);
        $secondPayload = app(CheckoutSecurityService::class)->issueInvoicePaymentSession($invoice->refresh(), (int) $user->id);

        $this->assertNotSame('', (string) ($firstPayload['session_token'] ?? ''));
        $this->assertNotSame('', (string) ($secondPayload['session_token'] ?? ''));
        $this->assertSame($createdAt->copy()->addMinutes(5)->toIso8601String(), $firstPayload['expires_at'] ?? null);
        $this->assertSame($firstPayload['expires_at'] ?? null, $secondPayload['expires_at'] ?? null);
    }

    public function test_invoice_payment_session_is_not_reissued_after_fixed_deadline(): void
    {
        [$user, , $invoice] = $this->createUserOrderInvoice('expiredwindow');
        $createdAt = now()->subMinutes(6)->startOfSecond();
        $invoice->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        $payload = app(CheckoutSecurityService::class)->issueInvoicePaymentSession($invoice->refresh(), (int) $user->id);

        $this->assertSame('', (string) ($payload['session_token'] ?? ''));
        $this->assertSame($createdAt->copy()->addMinutes(5)->toIso8601String(), $payload['expires_at'] ?? null);
    }

    public function test_cached_invoice_payment_session_cannot_be_used_after_fixed_deadline(): void
    {
        [$user, , $invoice] = $this->createUserOrderInvoice('stalesession');
        $createdAt = now()->subMinute()->startOfSecond();
        $invoice->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        $security = app(CheckoutSecurityService::class);
        $payload = $security->issueInvoicePaymentSession($invoice->refresh(), (int) $user->id);

        CarbonImmutable::setTestNow($createdAt->copy()->addMinutes(6));

        try {
            $this->expectException(BusinessException::class);
            $this->expectExceptionMessage('支付会话已失效，请重新创建账单后支付');
            $security->assertInvoicePaymentSessionToken((string) $payload['session_token'], $invoice->refresh(), (int) $user->id);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_checkout_service_cancels_expired_unpaid_invoice(): void
    {
        [, $order, $invoice] = $this->createUserOrderInvoice('autocancel');
        $createdAt = now()->subMinutes(6)->startOfSecond();
        $invoice->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        $updated = app(CheckoutService::class)->cancelExpiredUnpaidInvoice($invoice, [
            'actor_type' => 'system',
            'actor_name' => 'test',
            'reason' => 'payment_window_expired',
        ]);

        $this->assertSame(InvoiceStatus::CANCELLED, (int) $updated->status);
        $this->assertSame(InvoiceStatus::CANCELLED, (int) $invoice->refresh()->status);
        $this->assertSame(OrderStatus::CANCELLED, (int) $order->refresh()->status);
    }

    public function test_invoice_cleanup_task_cancels_invoices_after_payment_window(): void
    {
        [, $order, $invoice] = $this->createUserOrderInvoice('taskcancel');
        $createdAt = now()->subMinutes(6)->startOfSecond();
        $invoice->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        $settingService = $this->createMock(SettingService::class);
        $settingService->expects($this->once())
            ->method('getAutomationConfig')
            ->willReturn([
                'pending_order_cleanup_enabled' => true,
                'pending_order_cleanup_after_hours' => 1,
                'pending_recharge_cleanup_enabled' => false,
                'pending_recharge_cleanup_after_days' => 0,
            ]);

        $summary = (new InvoiceCleanupAutomationService(
            $settingService,
            app(CheckoutService::class),
            app(PaymentService::class),
            app(OrderService::class),
        ))->handle();

        $this->assertGreaterThanOrEqual(1, $summary['invoices_cancelled']);
        $this->assertSame(InvoiceStatus::CANCELLED, (int) $invoice->refresh()->status);
        $this->assertSame(OrderStatus::CANCELLED, (int) $order->refresh()->status);
    }

    public function test_order_service_cancels_expired_pending_order_after_payment_window(): void
    {
        [, $order, $invoice] = $this->createUserOrderInvoice('orderwindow');
        $createdAt = now()->subMinutes(6)->startOfSecond();
        $order->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();
        $invoice->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        $updated = app(OrderService::class)->cancelExpiredPendingOrder($order, [
            'actor_type' => 'system',
            'actor_name' => 'test',
            'reason' => 'payment_window_expired',
        ]);

        $this->assertSame(OrderStatus::CANCELLED, (int) $updated->status);
        $this->assertSame(OrderStatus::CANCELLED, (int) $order->refresh()->status);
        $this->assertSame(InvoiceStatus::CANCELLED, (int) $invoice->refresh()->status);
    }

    public function test_order_payment_qr_uses_remaining_five_minute_window(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('orderqrttl');
        $createdAt = now()->subMinutes(2)->startOfSecond();
        $order->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();
        $invoice->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        $precreateRequests = [];
        $alipayGateway = $this->makeFakePaymentGateway([
            'precreate' => function (PaymentPrecreateRequest $request) use (&$precreateRequests): array {
                $precreateRequests[] = [
                    'out_trade_no' => $request->outTradeNo,
                    'timeout_express' => $request->timeoutExpress,
                ];

                return [
                    'qr_code' => 'https://qr.alipay.test/order-window',
                    'out_trade_no' => $request->outTradeNo,
                ];
            },
        ]);

        $result = $this->makePaymentService($alipayGateway)->payOrderByAlipay($order->refresh(), $user);

        $this->assertSame([
            ['out_trade_no' => $result['payment_no'], 'timeout_express' => '3m'],
        ], $precreateRequests);
        $this->assertDatabaseHas('payments', [
            'payment_no' => $result['payment_no'],
            'gateway_key' => PaymentGatewayCode::ALIPAY,
            'status' => PaymentStatus::PENDING,
        ]);
    }

    private function makePaymentService(?PaymentGatewayInterface $alipayGateway = null): PaymentService
    {
        return new PaymentService(
            $this->createMock(ProvisionService::class),
            $this->makePaymentGatewayManagerForTest($alipayGateway),
            $this->createMock(ServiceRenewService::class),
            $this->createMock(ReferralService::class),
            $this->createMock(PaidOrderBusinessFlowDispatcher::class),
            $this->createMock(AdminOrderNotificationService::class),
            $this->createMock(CouponService::class),
            new InvoiceService,
        );
    }

    public function test_payment_session_token_can_be_issued_without_invoice(): void
    {
        $user = User::query()->create([
            'email' => 'order-session-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'referrer_user_id' => null,
        ]);

        $order = Order::query()->create([
            'order_no' => 'ORDSESSION'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => (int) $user->id,
            'type' => 'new',
            'amount' => '20.00',
            'discount' => '2.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'status' => OrderStatus::PENDING,
        ]);

        $payload = app(CheckoutSecurityService::class)->issuePaymentSession($order, (int) $user->id);

        $this->assertIsString($payload['session_token'] ?? null);
        $this->assertNotSame('', (string) ($payload['session_token'] ?? ''));
    }

    public function test_pay_order_by_balance_persists_payment_order_id(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'order-balance-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Order Balance',
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
        $user->forceFill(['balance' => '100.00'])->save();

        $order = Order::query()->create([
            'order_no' => 'ORDBAL'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'type' => 'new',
            'amount' => '50.00',
            'discount' => '5.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'status' => OrderStatus::PENDING,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'INV'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'type' => 'normal',
            'amount' => '45.00',
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::UNPAID,
            'due_date' => now()->addDay(),
        ]);

        $service = new PaymentService(
            $this->createMock(ProvisionService::class),
            $this->makePaymentGatewayManagerForTest(),
            $this->createMock(ServiceRenewService::class),
            $this->createMock(ReferralService::class),
            $this->createMock(PaidOrderBusinessFlowDispatcher::class),
            $this->createMock(AdminOrderNotificationService::class),
            $this->createMock(CouponService::class),
            new InvoiceService,
        );

        $invoice = $service->payOrderByBalance($order, $user, ['trace_id' => 'order-balance-regression']);

        $this->assertDatabaseMissing('payments', [
            'order_id' => (int) $order->id,
            'gateway_key' => 'balance',
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => (int) $order->id,
            'status' => OrderStatus::PAID,
            'paid_amount' => '45.00',
        ]);

        $this->assertDatabaseHas('invoices', [
            'id' => (int) $invoice->id,
            'status' => InvoiceStatus::PAID,
            'paid_amount' => '45.00',
        ]);

        $this->assertSame('55.00', User::query()->findOrFail((int) $user->id)->balance);
    }

    public function test_pay_by_balance_and_alipay_records_balance_payment_no(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'mix-pay-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Mix Pay',
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
        $user->forceFill(['balance' => '30.00'])->save();

        $order = Order::query()->create([
            'order_no' => 'ORDMIX'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'type' => 'new',
            'amount' => '50.00',
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'status' => OrderStatus::PENDING,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'INVMIX'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'type' => 'normal',
            'amount' => '50.00',
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::UNPAID,
            'due_date' => now()->addDay(),
        ]);

        $alipayGateway = $this->makeFakePaymentGateway([
            'enabled' => true,
            'precreate' => [
                'qr_code' => 'https://qr.alipay.test/mix-pay',
                'out_trade_no' => 'mock-out-trade-no',
            ],
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

        $result = $service->payByBalanceAndAlipay($invoice, $user, 20.00, ['trace_id' => 'mix-pay-regression']);

        $this->assertSame('20.00', $result['balance_amount']);
        $this->assertSame('30.00', $result['amount']);
        $this->assertArrayNotHasKey('balance_payment_no', $result);
        $this->assertNotSame('', (string) $result['payment_no']);

        $this->assertDatabaseMissing('payments', [
            'invoice_id' => (int) $invoice->id,
            'gateway_key' => 'balance',
        ]);
        $this->assertDatabaseHas('payments', [
            'payment_no' => $result['payment_no'],
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'gateway_key' => 'alipay',
            'status' => PaymentStatus::PENDING,
            'amount' => '30.00',
        ]);
        $this->assertSame('10.00', User::query()->findOrFail((int) $user->id)->balance);
    }

    public function test_mix_payment_balance_tail_payment_sets_order_paid_amount_to_full_invoice_amount(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('mixtail', '50.00', '50.00');

        $alipayGateway = $this->makeFakePaymentGateway([
            'enabled' => true,
            'precreate' => [
                'qr_code' => 'https://qr.alipay.test/mix-tail',
                'out_trade_no' => 'mock-out-trade-no',
            ],
        ]);
        $service = $this->makePaymentService($alipayGateway);

        $service->payByBalanceAndAlipay($invoice, $user, 20.00, ['trace_id' => 'mix-tail-precreate']);
        $service->payByBalance($invoice->fresh(), $user->fresh(), ['trace_id' => 'mix-tail-balance']);

        $this->assertDatabaseHas('invoices', [
            'id' => (int) $invoice->id,
            'status' => InvoiceStatus::PAID,
            'paid_amount' => '50.00',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => (int) $order->id,
            'status' => OrderStatus::PAID,
            'paid_amount' => '50.00',
        ]);
        $this->assertSame('0.00', User::query()->findOrFail((int) $user->id)->balance);
    }

    public function test_mix_pay_precreate_failure_rolls_back_balance_deduction(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('mixfail');

        $alipayGateway = $this->makeFakePaymentGateway([
            'enabled' => true,
            'precreate' => new BusinessException('网关失败'),
        ]);

        try {
            $this->makePaymentService($alipayGateway)->payByBalanceAndAlipay($invoice, $user, 20.00, ['trace_id' => 'mix-fail']);
            $this->fail('Expected precreate failure.');
        } catch (BusinessException $exception) {
            $this->assertSame('网关失败', $exception->getMessage());
        }

        $this->assertSame('30.00', User::query()->findOrFail((int) $user->id)->balance);
        $this->assertDatabaseHas('invoices', [
            'id' => (int) $invoice->id,
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::UNPAID,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => (int) $order->id,
            'paid_amount' => '0.00',
            'status' => OrderStatus::PENDING,
        ]);
        $this->assertDatabaseHas('account_transactions', [
            'user_id' => (int) $user->id,
            'source_id' => (int) $invoice->id,
            'event_type' => FinanceLedgerEventType::INVOICE_PAYMENT,
            'change_amount' => '-20.00',
        ]);
        $this->assertDatabaseHas('account_transactions', [
            'user_id' => (int) $user->id,
            'source_id' => (int) $invoice->id,
            'event_type' => FinanceLedgerEventType::INVOICE_REFUND,
            'change_amount' => '20.00',
        ]);
        $this->assertSame(2, AccountTransaction::query()
            ->where('user_id', (int) $user->id)
            ->where('source_id', (int) $invoice->id)
            ->count());
    }

    public function test_cancel_mix_paid_invoice_refunds_reserved_balance(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('mixcancel');

        $alipayGateway = $this->makeFakePaymentGateway([
            'enabled' => true,
            'precreate' => [
                'qr_code' => 'https://qr.alipay.test/mix-cancel',
                'out_trade_no' => 'mock-out-trade-no',
            ],
        ]);

        $this->makePaymentService($alipayGateway)->payByBalanceAndAlipay($invoice, $user, 20.00, ['trace_id' => 'mix-cancel']);

        app(CheckoutService::class)->cancel($invoice->fresh(), [
            'actor_type' => 'client',
            'actor_user_id' => (int) $user->id,
            'trace_id' => 'mix-cancel',
        ]);

        $this->assertSame('30.00', User::query()->findOrFail((int) $user->id)->balance);
        $this->assertDatabaseHas('invoices', [
            'id' => (int) $invoice->id,
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::CANCELLED,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => (int) $order->id,
            'paid_amount' => '0.00',
        ]);
    }

    public function test_order_service_cancel_restores_reserved_mix_balance(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('ordmixcancel');

        // 组合支付状态：余额已预扣 20（账户剩 10），剩余 30 走支付宝 pending 支付单。
        $user->forceFill(['balance' => '10.00'])->save();
        $invoice->forceFill(['paid_amount' => '20.00'])->save();
        $order->forceFill(['paid_amount' => '20.00'])->save();
        $payment = Payment::query()->create([
            'payment_no' => 'PAYORDMIXCANCEL'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => PaymentGatewayCode::ALIPAY,
            'gateway_key' => PaymentGatewayCode::ALIPAY,
            'amount' => '30.00',
            'status' => PaymentStatus::PENDING,
            'callback_raw' => [
                'mix_payment' => true,
                'balance_amount' => '20.00',
            ],
        ]);

        app(OrderService::class)->cancel($order, [
            'actor_type' => 'client',
            'actor_user_id' => (int) $user->id,
            'trace_id' => 'ord-mix-cancel',
        ]);

        // 预扣的余额退回，支付单关闭，订单/账单回到未支付。
        $this->assertSame('30.00', User::query()->findOrFail((int) $user->id)->balance);
        $this->assertSame(PaymentStatus::FAILED, (int) $payment->refresh()->status);
        $this->assertSame('0.00', (string) $invoice->refresh()->paid_amount);
        $this->assertSame('0.00', (string) $order->refresh()->paid_amount);
        $this->assertSame(OrderStatus::CANCELLED, (int) $order->refresh()->status);
        $this->assertSame(InvoiceStatus::CANCELLED, (int) $invoice->refresh()->status);
        $this->assertDatabaseHas('account_transactions', [
            'user_id' => (int) $user->id,
            'event_type' => FinanceLedgerEventType::INVOICE_REFUND,
            'change_amount' => '20.00',
        ]);
    }

    public function test_mix_pay_success_sets_order_paid_amount_to_full_invoice_amount(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('mixsuccess');

        $alipayGateway = $this->makeFakePaymentGateway([
            'enabled' => true,
            'precreate' => [
                'qr_code' => 'https://qr.alipay.test/mix-success',
                'out_trade_no' => 'mock-out-trade-no',
            ],
        ]);

        $result = $this->makePaymentService($alipayGateway)->payByBalanceAndAlipay($invoice, $user, 20.00, ['trace_id' => 'mix-success']);
        $payment = Payment::query()->where('payment_no', (string) $result['payment_no'])->firstOrFail();

        $tradeNo = 'TRADE-MIX-SUCCESS-'.strtoupper(bin2hex(random_bytes(4)));
        $queryResult = [
            'trade_status' => 'TRADE_SUCCESS',
            'trade_no' => $tradeNo,
            'out_trade_no' => (string) $payment->payment_no,
            'total_amount' => '30.00',
            'raw' => [
                'trade_status' => 'TRADE_SUCCESS',
                'trade_no' => $tradeNo,
                'out_trade_no' => (string) $payment->payment_no,
                'total_amount' => '30.00',
            ],
        ];
        $alipayGateway = $this->makeFakePaymentGateway([
            'query' => $queryResult,
        ]);

        $this->makePaymentService($alipayGateway)->queryAlipayStatus($payment);

        $this->assertDatabaseHas('orders', [
            'id' => (int) $order->id,
            'status' => OrderStatus::PAID,
            'paid_amount' => '50.00',
        ]);
        $this->assertDatabaseHas('invoices', [
            'id' => (int) $invoice->id,
            'status' => InvoiceStatus::PAID,
            'paid_amount' => '50.00',
        ]);
    }

    public function test_mix_paid_invoice_original_refund_is_blocked_before_gateway_call(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('mixrefund', '50.00', '30.00');

        // 组合支付：余额 20 + 支付宝 30 尾款。
        $precreateGateway = $this->makeFakePaymentGateway([
            'enabled' => true,
            'precreate' => [
                'qr_code' => 'https://qr.alipay.test/mix-refund',
                'out_trade_no' => 'mock-out-trade-no',
            ],
        ]);
        $service = $this->makePaymentService($precreateGateway);
        $result = $service->payByBalanceAndAlipay($invoice, $user, 20.00, ['trace_id' => 'mix-refund-precreate']);
        $payment = Payment::query()->where('payment_no', (string) $result['payment_no'])->firstOrFail();

        // 模拟支付宝尾款支付成功，账单全额付清。
        $tradeNo = 'TRADE-MIX-REFUND-'.strtoupper(bin2hex(random_bytes(4)));
        $queryGateway = $this->makeFakePaymentGateway([
            'query' => [
                'trade_status' => 'TRADE_SUCCESS',
                'trade_no' => $tradeNo,
                'out_trade_no' => (string) $payment->payment_no,
                'total_amount' => '30.00',
                'raw' => [
                    'trade_status' => 'TRADE_SUCCESS',
                    'trade_no' => $tradeNo,
                    'out_trade_no' => (string) $payment->payment_no,
                    'total_amount' => '30.00',
                ],
            ],
        ]);
        $this->makePaymentService($queryGateway)->queryAlipayStatus($payment->fresh());

        $this->assertSame(InvoiceStatus::PAID, (int) $invoice->refresh()->status);
        $this->assertSame('50.00', (string) $invoice->refresh()->paid_amount);

        // 混付账单原路退款应被拦截：全额 50 超过支付宝该笔交易实收 30，且不触发任何网关退款请求。
        $refundGateway = $this->makeFakePaymentGateway(['enabled' => true]);
        $refundService = $this->makePaymentService($refundGateway);

        try {
            $refundService->refundOrder($order->fresh(['invoice']), ['refund_method' => 'original'], [
                'operator_type' => 'admin',
                'operator_id' => 1,
                'operator_name' => 'test-admin',
                'trace_id' => 'mix-refund-original',
            ]);
            $this->fail('混付账单原路退款应被拒绝');
        } catch (BusinessException $exception) {
            $this->assertSame('该账单包含余额支付，无法全额原路退款，请使用「退回余额」', $exception->getMessage());
        }

        $this->assertSame(0, $refundGateway->countCalls('refund'));
        $this->assertSame(PaymentStatus::SUCCESS, (int) $payment->refresh()->status);
        $this->assertSame(InvoiceStatus::PAID, (int) $invoice->refresh()->status);
        $this->assertSame(OrderStatus::PAID, (int) $order->refresh()->status);
    }

    public function test_mix_paid_invoice_disables_original_refund_action(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('mixrefact', '50.00', '30.00');

        $precreateGateway = $this->makeFakePaymentGateway([
            'enabled' => true,
            'precreate' => [
                'qr_code' => 'https://qr.alipay.test/mix-refund-action',
                'out_trade_no' => 'mock-out-trade-no',
            ],
        ]);
        $service = $this->makePaymentService($precreateGateway);
        $result = $service->payByBalanceAndAlipay($invoice, $user, 20.00, ['trace_id' => 'mix-refund-action-precreate']);
        $payment = Payment::query()->where('payment_no', (string) $result['payment_no'])->firstOrFail();

        $tradeNo = 'TRADE-MIX-REFACT-'.strtoupper(bin2hex(random_bytes(4)));
        $queryGateway = $this->makeFakePaymentGateway([
            'query' => [
                'trade_status' => 'TRADE_SUCCESS',
                'trade_no' => $tradeNo,
                'out_trade_no' => (string) $payment->payment_no,
                'total_amount' => '30.00',
                'raw' => [
                    'trade_status' => 'TRADE_SUCCESS',
                    'trade_no' => $tradeNo,
                    'out_trade_no' => (string) $payment->payment_no,
                    'total_amount' => '30.00',
                ],
            ],
        ]);
        $this->makePaymentService($queryGateway)->queryAlipayStatus($payment->fresh());

        $this->assertSame(InvoiceStatus::PAID, (int) $invoice->refresh()->status);

        // 账单退款决策层同样禁用原路退款，返回明确原因，避免进入退款服务。
        try {
            app(UserService::class)->refundInvoice($user->fresh(), (int) $invoice->id, [
                'refund_method' => 'original',
                'remark' => '混付账单原路退款',
            ], [
                'operator_type' => 'admin',
                'operator_id' => 1,
                'operator_name' => 'test-admin',
                'trace_id' => 'mix-refund-action',
            ]);
            $this->fail('混付账单应禁用原路退款');
        } catch (BusinessException $exception) {
            $this->assertSame('该账单包含余额支付，无法全额原路退款，请使用「退回余额」', $exception->getMessage());
        }
    }

    public function test_duplicate_paid_credited_payment_cannot_be_refunded_again(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('dupcredit', '100.00', '100.00');

        // 先开一张 100 元支付宝二维码，随后用余额付清账单。
        $precreateGateway = $this->makeFakePaymentGateway([
            'enabled' => true,
            'precreate' => [
                'qr_code' => 'https://qr.alipay.test/dup-credit',
                'out_trade_no' => 'mock-out-trade-no',
            ],
        ]);
        $service = $this->makePaymentService($precreateGateway);
        $result = $service->payByAlipay($invoice, $user, ['trace_id' => 'dup-credit-precreate']);
        $payment = Payment::query()->where('payment_no', (string) $result['payment_no'])->firstOrFail();

        $service->payByBalance($invoice->refresh(), $user->refresh(), ['trace_id' => 'dup-credit-balance']);
        $this->assertSame(InvoiceStatus::PAID, (int) $invoice->refresh()->status);
        $this->assertSame('0.00', (string) User::query()->findOrFail((int) $user->id)->balance);

        // 用户随后扫二维码，支付宝实收 100 → 账单已付，转入余额并标记 credited_to_balance。
        $tradeNo = 'TRADE-DUP-CREDIT-'.strtoupper(bin2hex(random_bytes(4)));
        $queryGateway = $this->makeFakePaymentGateway([
            'query' => [
                'trade_status' => 'TRADE_SUCCESS',
                'trade_no' => $tradeNo,
                'out_trade_no' => (string) $payment->payment_no,
                'total_amount' => '100.00',
                'raw' => [
                    'trade_status' => 'TRADE_SUCCESS',
                    'trade_no' => $tradeNo,
                    'out_trade_no' => (string) $payment->payment_no,
                    'total_amount' => '100.00',
                ],
            ],
        ]);
        $this->makePaymentService($queryGateway)->queryAlipayStatus($payment->fresh());

        $this->assertSame('100.00', (string) User::query()->findOrFail((int) $user->id)->balance);
        $this->assertTrue((bool) data_get((array) ($payment->refresh()->callback_raw ?? []), 'credited_to_balance', false));

        $refundGateway = $this->makeFakePaymentGateway(['enabled' => true]);
        $refundService = $this->makePaymentService($refundGateway);

        // 原路退款：已入余额的支付单不得作为主退款单，直接拒绝且不触发网关退款。
        try {
            $refundService->refundOrder($order->fresh(['invoice']), ['refund_method' => 'original'], [
                'operator_type' => 'admin',
                'operator_id' => 1,
                'operator_name' => 'test-admin',
                'trace_id' => 'dup-credit-refund-original',
            ]);
            $this->fail('重复支付已入余额的支付单不应被原路退款');
        } catch (BusinessException $exception) {
            $this->assertSame('当前支付方式不支持原路退款', $exception->getMessage());
        }
        $this->assertSame(0, $refundGateway->countCalls('refund'));

        // 退回余额：可退金额扣除已入余额部分后为 0，拒绝退款且余额不变。
        try {
            $refundService->refundOrder($order->fresh(['invoice']), ['refund_method' => 'balance'], [
                'operator_type' => 'admin',
                'operator_id' => 1,
                'operator_name' => 'test-admin',
                'trace_id' => 'dup-credit-refund-balance',
            ]);
            $this->fail('重复支付已入余额的账单不应再次退回余额');
        } catch (BusinessException $exception) {
            $this->assertSame('该账单款项已通过重复支付转入余额，无需再退款', $exception->getMessage());
        }

        $this->assertSame('100.00', (string) User::query()->findOrFail((int) $user->id)->balance);
        $this->assertSame(PaymentStatus::SUCCESS, (int) $payment->refresh()->status);
        $this->assertSame(InvoiceStatus::PAID, (int) $invoice->refresh()->status);
    }

    public function test_pure_alipay_invoice_original_refund_still_works(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('purealipay', '50.00', '0.00');

        $precreateGateway = $this->makeFakePaymentGateway([
            'enabled' => true,
            'precreate' => [
                'qr_code' => 'https://qr.alipay.test/pure-refund',
                'out_trade_no' => 'mock-out-trade-no',
            ],
        ]);
        $service = $this->makePaymentService($precreateGateway);
        $result = $service->payByAlipay($invoice, $user, ['trace_id' => 'pure-refund-precreate']);
        $payment = Payment::query()->where('payment_no', (string) $result['payment_no'])->firstOrFail();

        // 模拟支付宝支付成功，账单全额付清（无余额参与）。
        $tradeNo = 'TRADE-PURE-REFUND-'.strtoupper(bin2hex(random_bytes(4)));
        $queryGateway = $this->makeFakePaymentGateway([
            'query' => [
                'trade_status' => 'TRADE_SUCCESS',
                'trade_no' => $tradeNo,
                'out_trade_no' => (string) $payment->payment_no,
                'total_amount' => '50.00',
                'raw' => [
                    'trade_status' => 'TRADE_SUCCESS',
                    'trade_no' => $tradeNo,
                    'out_trade_no' => (string) $payment->payment_no,
                    'total_amount' => '50.00',
                ],
            ],
        ]);
        $this->makePaymentService($queryGateway)->queryAlipayStatus($payment->fresh());

        $this->assertSame(InvoiceStatus::PAID, (int) $invoice->refresh()->status);

        // 纯支付宝账单原路退款应正常走通，不被混付/已入余额守卫误伤。
        $refundGateway = $this->makeFakePaymentGateway(['enabled' => true]);
        $refundService = $this->makePaymentService($refundGateway);

        $result = $refundService->refundOrder($order->fresh(['invoice']), ['refund_method' => 'original'], [
            'operator_type' => 'admin',
            'operator_id' => 1,
            'operator_name' => 'test-admin',
            'trace_id' => 'pure-refund-original',
        ]);

        $this->assertFalse((bool) ($result['already_refunded'] ?? false));
        $this->assertSame(1, $refundGateway->countCalls('refund'));
        $this->assertSame(PaymentStatus::REFUNDED, (int) $payment->refresh()->status);
        $this->assertSame(InvoiceStatus::REFUNDED, (int) $invoice->refresh()->status);
        $this->assertSame(OrderStatus::REFUNDED, (int) $order->refresh()->status);
    }

    public function test_stale_full_alipay_qr_after_mix_payment_restores_reserved_balance(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('staleqr', '100.00', '30.00');

        $stalePayment = Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => 'alipay',
            'amount' => '100.00',
            'status' => PaymentStatus::PENDING,
            'trace_id' => 'stale-full-qr',
            'callback_raw' => ['source' => 'alipay_precreate'],
        ]);
        $tradeNo = 'TRADE-STALE-FULL-QR-'.strtoupper(bin2hex(random_bytes(4)));

        $alipayGateway = $this->makeFakePaymentGateway([
            'enabled' => true,
            'precreate' => [
                'qr_code' => 'https://qr.alipay.test/stale-mix',
                'out_trade_no' => 'mock-out-trade-no',
            ],
            'query' => [
                'trade_status' => 'TRADE_SUCCESS',
                'trade_no' => $tradeNo,
                'out_trade_no' => (string) $stalePayment->payment_no,
                'total_amount' => '100.00',
                'raw' => [
                    'trade_status' => 'TRADE_SUCCESS',
                    'trade_no' => $tradeNo,
                    'out_trade_no' => (string) $stalePayment->payment_no,
                    'total_amount' => '100.00',
                ],
            ],
        ]);

        $service = $this->makePaymentService($alipayGateway);
        $mixResult = $service->payByBalanceAndAlipay($invoice, $user, 30.00, ['trace_id' => 'stale-mix']);
        $mixPayment = Payment::query()->where('payment_no', (string) $mixResult['payment_no'])->firstOrFail();

        $this->assertSame('0.00', User::query()->findOrFail((int) $user->id)->balance);
        $this->assertSame('30.00', (string) $invoice->refresh()->paid_amount);

        $service->queryAlipayStatus($stalePayment);

        $this->assertSame('30.00', User::query()->findOrFail((int) $user->id)->balance);
        $this->assertDatabaseHas('invoices', [
            'id' => (int) $invoice->id,
            'status' => InvoiceStatus::PAID,
            'paid_amount' => '100.00',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => (int) $order->id,
            'status' => OrderStatus::PAID,
            'paid_amount' => '100.00',
        ]);
        $this->assertDatabaseHas('payments', [
            'id' => (int) $stalePayment->id,
            'status' => PaymentStatus::SUCCESS,
            'trade_no' => $tradeNo,
        ]);
        $this->assertDatabaseHas('payments', [
            'id' => (int) $mixPayment->id,
            'status' => PaymentStatus::FAILED,
        ]);
        $this->assertTrue((bool) data_get((array) $mixPayment->refresh()->callback_raw, 'balance_restored'));
    }

    public function test_cancelled_invoice_successful_alipay_payment_is_credited_to_balance(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('cancelpay', '80.00', '10.00');
        $invoice->forceFill(['status' => InvoiceStatus::CANCELLED])->save();

        $payment = Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => 'alipay',
            'amount' => '80.00',
            'status' => PaymentStatus::PENDING,
        ]);
        $tradeNo = 'TRADE-CANCELLED-CREDIT-'.strtoupper(bin2hex(random_bytes(4)));

        $alipayGateway = $this->makeFakePaymentGateway([
            'verify_notify' => true,
            'matches_merchant' => true,
        ]);

        $this->makePaymentService($alipayGateway)->handleAlipayNotify([
            'app_id' => 'mock-app-id',
            'trade_status' => 'TRADE_SUCCESS',
            'trade_no' => $tradeNo,
            'out_trade_no' => (string) $payment->payment_no,
            'total_amount' => '80.00',
        ]);

        $this->assertSame('90.00', User::query()->findOrFail((int) $user->id)->balance);
        $this->assertDatabaseHas('invoices', [
            'id' => (int) $invoice->id,
            'status' => InvoiceStatus::CANCELLED,
            'paid_amount' => '0.00',
        ]);
        $this->assertDatabaseHas('payments', [
            'id' => (int) $payment->id,
            'status' => PaymentStatus::SUCCESS,
            'trade_no' => $tradeNo,
        ]);
        $this->assertDatabaseHas('account_transactions', [
            'user_id' => (int) $user->id,
            'event_type' => FinanceLedgerEventType::RECHARGE,
            'change_amount' => '80.00',
            'source_type' => 'payment',
            'source_id' => (int) $payment->id,
        ]);

        $callbackRaw = (array) $payment->refresh()->callback_raw;
        $this->assertTrue((bool) data_get($callbackRaw, 'cancelled_invoice'));
        $this->assertTrue((bool) data_get($callbackRaw, 'credited_to_balance'));
    }

    public function test_expired_mix_payment_success_restores_balance_and_credits_alipay_amount(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('expiredmix', '100.00', '0.00');
        $invoice->forceFill([
            'paid_amount' => '30.00',
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ])->save();

        $payment = Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => 'alipay',
            'amount' => '70.00',
            'status' => PaymentStatus::PENDING,
            'callback_raw' => [
                'source' => 'alipay_precreate_mix',
                'mix_payment' => true,
                'balance_amount' => '30.00',
            ],
        ]);
        $tradeNo = 'TRADE-EXPIRED-MIX-CREDIT-'.strtoupper(bin2hex(random_bytes(4)));

        $alipayGateway = $this->makeFakePaymentGateway([
            'query' => [
                'trade_status' => 'TRADE_SUCCESS',
                'trade_no' => $tradeNo,
                'out_trade_no' => (string) $payment->payment_no,
                'total_amount' => '70.00',
                'raw' => [
                    'trade_status' => 'TRADE_SUCCESS',
                    'trade_no' => $tradeNo,
                    'out_trade_no' => (string) $payment->payment_no,
                    'total_amount' => '70.00',
                ],
            ],
        ]);

        $this->makePaymentService($alipayGateway)->queryAlipayStatus($payment);

        $this->assertSame('100.00', User::query()->findOrFail((int) $user->id)->balance);
        $this->assertDatabaseHas('invoices', [
            'id' => (int) $invoice->id,
            'status' => InvoiceStatus::CANCELLED,
            'paid_amount' => '0.00',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => (int) $order->id,
            'status' => OrderStatus::CANCELLED,
            'paid_amount' => '0.00',
        ]);
        $this->assertDatabaseHas('payments', [
            'id' => (int) $payment->id,
            'status' => PaymentStatus::SUCCESS,
            'trade_no' => $tradeNo,
        ]);

        $callbackRaw = (array) $payment->refresh()->callback_raw;
        $this->assertTrue((bool) data_get($callbackRaw, 'payment_window_expired'));
        $this->assertTrue((bool) data_get($callbackRaw, 'balance_restored'));
        $this->assertTrue((bool) data_get($callbackRaw, 'credited_to_balance'));
    }

    public function test_mix_pay_refund_to_balance_refunds_full_invoice_amount(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('mixrefund');
        $tradeNo = 'TRADE-MIX-REFUND-'.strtoupper(bin2hex(random_bytes(4)));

        Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => 'alipay',
            'trade_no' => $tradeNo,
            'amount' => '30.00',
            'status' => PaymentStatus::SUCCESS,
            'callback_raw' => [
                'mix_payment' => true,
                'balance_amount' => '20.00',
            ],
            'paid_at' => now(),
        ]);

        $invoice->forceFill([
            'status' => InvoiceStatus::PAID,
            'paid_amount' => '50.00',
            'paid_at' => now(),
        ])->save();
        $order->forceFill([
            'status' => OrderStatus::PAID,
            'paid_amount' => '50.00',
            'paid_at' => now(),
        ])->save();
        $user->forceFill(['balance' => '10.00'])->save();

        $result = $this->makePaymentService()->refundInvoiceToBalance($user, $invoice, [
            'remark' => '组合支付退款',
        ]);

        $this->assertSame('50.00', (string) ($result['refund']['refund_amount'] ?? ''));
        $this->assertSame('60.00', User::query()->findOrFail((int) $user->id)->balance);
        $this->assertDatabaseHas('orders', [
            'id' => (int) $order->id,
            'status' => OrderStatus::REFUNDED,
        ]);
        $this->assertDatabaseHas('invoices', [
            'id' => (int) $invoice->id,
            'status' => InvoiceStatus::REFUNDED,
        ]);
    }

    public function test_admin_manual_invoice_entry_creates_manual_payment_audit_record(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('manualpay');
        $tradeNo = 'MANUAL-ENTRY-'.strtoupper(bin2hex(random_bytes(4)));

        app(OrderService::class)->updateManualPaymentStatus($order, [
            'action' => 'mark_paid',
            'amount' => '50.00',
            'payment_gateway' => 'manual',
            'trade_no' => $tradeNo,
            'remark' => '后台确认入账',
        ], [
            'operator_id' => 1,
            'operator_name' => 'tester',
            'trace_id' => 'manual-entry-regression',
        ]);

        $this->assertDatabaseHas('payments', [
            'invoice_id' => (int) $invoice->id,
            'gateway_key' => 'manual',
            'status' => PaymentStatus::SUCCESS,
            'trade_no' => $tradeNo,
            'amount' => '50.00',
        ]);
        $this->assertDatabaseHas('invoices', [
            'id' => (int) $invoice->id,
            'status' => InvoiceStatus::PAID,
            'paid_amount' => '50.00',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => (int) $order->id,
            'status' => OrderStatus::PAID,
            'paid_amount' => '50.00',
        ]);
    }

    public function test_admin_manual_invoice_entry_mark_unpaid_reverts_via_manual_payment_record(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('manualrevert');
        $tradeNo = 'MANUAL-REVERT-'.strtoupper(bin2hex(random_bytes(4)));

        app(OrderService::class)->updateManualPaymentStatus($order, [
            'action' => 'mark_paid',
            'amount' => '50.00',
            'payment_gateway' => 'manual',
            'trade_no' => $tradeNo,
        ], [
            'operator_id' => 1,
            'operator_name' => 'tester',
            'trace_id' => 'manual-revert-mark-paid',
        ]);

        $this->assertDatabaseHas('payments', [
            'invoice_id' => (int) $invoice->id,
            'gateway_key' => 'manual',
            'status' => PaymentStatus::SUCCESS,
        ]);

        app(OrderService::class)->updateManualPaymentStatus($order->fresh(), [
            'action' => 'mark_unpaid',
        ], [
            'operator_id' => 1,
            'operator_name' => 'tester',
            'trace_id' => 'manual-revert-mark-unpaid',
        ]);

        $this->assertDatabaseHas('payments', [
            'invoice_id' => (int) $invoice->id,
            'gateway_key' => 'manual',
            'status' => PaymentStatus::FAILED,
        ]);
        $this->assertDatabaseHas('invoices', [
            'id' => (int) $invoice->id,
            'status' => InvoiceStatus::UNPAID,
            'paid_amount' => '0.00',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => (int) $order->id,
            'status' => OrderStatus::PENDING,
            'paid_amount' => '0.00',
        ]);
    }

    public function test_admin_mark_unpaid_rejects_balance_paid_order_without_manual_payment(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('manualbalance');

        // 余额支付不产生 Payment 记录，不能通过 mark_unpaid 回退（需走退款）。
        $invoice->forceFill([
            'status' => InvoiceStatus::PAID,
            'paid_amount' => '50.00',
            'paid_at' => now(),
        ])->save();
        $order->forceFill([
            'status' => OrderStatus::PAID,
            'paid_amount' => '50.00',
            'paid_at' => now(),
        ])->save();

        try {
            app(OrderService::class)->updateManualPaymentStatus($order, [
                'action' => 'mark_unpaid',
            ], [
                'operator_id' => 1,
                'operator_name' => 'tester',
                'trace_id' => 'manual-balance-revert',
            ]);
            $this->fail('余额支付订单不应支持 mark_unpaid 回退');
        } catch (BusinessException $exception) {
            $this->assertSame('仅支持回退后台手动设为已支付的订单', $exception->getMessage());
        }

        $this->assertDatabaseHas('orders', [
            'id' => (int) $order->id,
            'status' => OrderStatus::PAID,
        ]);
    }

    public function test_admin_manual_tail_entry_sets_paid_amount_to_full_invoice_amount(): void
    {
        [, $order, $invoice] = $this->createUserOrderInvoice('manualtail');
        $invoice->forceFill(['paid_amount' => '20.00'])->save();
        $order->forceFill(['paid_amount' => '20.00'])->save();

        app(OrderService::class)->updateManualPaymentStatus($order, [
            'action' => 'mark_paid',
            'amount' => '30.00',
            'payment_gateway' => 'manual',
            'remark' => '后台确认尾款入账',
        ], [
            'operator_id' => 1,
            'operator_name' => 'tester',
            'trace_id' => 'manual-tail-regression',
        ]);

        $this->assertDatabaseHas('invoices', [
            'id' => (int) $invoice->id,
            'status' => InvoiceStatus::PAID,
            'paid_amount' => '50.00',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => (int) $order->id,
            'status' => OrderStatus::PAID,
            'paid_amount' => '50.00',
        ]);
    }

    public function test_admin_manual_invoice_entry_does_not_create_payment_for_offline_gateway(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('offlinepay');

        app(OrderService::class)->updateManualPaymentStatus($order, [
            'action' => 'mark_paid',
            'amount' => '50.00',
            'payment_gateway' => 'offline',
            'trade_no' => 'OFFLINE-ENTRY-'.strtoupper(bin2hex(random_bytes(4))),
            'remark' => '线下收款确认',
        ], [
            'operator_id' => 1,
            'operator_name' => 'tester',
            'trace_id' => 'offline-entry-regression',
        ]);

        $this->assertDatabaseMissing('payments', [
            'invoice_id' => (int) $invoice->id,
            'gateway_key' => 'offline',
        ]);
        $this->assertDatabaseHas('invoices', [
            'id' => (int) $invoice->id,
            'status' => InvoiceStatus::PAID,
            'paid_amount' => '50.00',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => (int) $order->id,
            'status' => OrderStatus::PAID,
            'paid_amount' => '50.00',
        ]);
    }

    public function test_admin_manual_invoice_entry_does_not_create_real_gateway_payment(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('manualalipay');

        app(OrderService::class)->updateManualPaymentStatus($order, [
            'action' => 'mark_paid',
            'amount' => '50.00',
            'payment_gateway' => 'alipay',
            'trade_no' => 'ALI-MANUAL-ENTRY-'.strtoupper(bin2hex(random_bytes(4))),
            'remark' => '后台确认支付宝入账',
        ], [
            'operator_id' => 1,
            'operator_name' => 'tester',
            'trace_id' => 'manual-alipay-entry-regression',
        ]);

        $this->assertDatabaseMissing('payments', [
            'invoice_id' => (int) $invoice->id,
            'gateway_key' => 'alipay',
            'status' => PaymentStatus::SUCCESS,
        ]);
        $this->assertDatabaseHas('invoices', [
            'id' => (int) $invoice->id,
            'status' => InvoiceStatus::PAID,
            'paid_amount' => '50.00',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => (int) $order->id,
            'status' => OrderStatus::PAID,
            'paid_amount' => '50.00',
        ]);
    }
}
