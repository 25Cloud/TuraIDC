<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Contracts\Integrations\Payments\PaymentGatewayInterface;
use App\Exceptions\BusinessException;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\AdminOrderNotificationService;
use App\Services\Finance\CheckoutSecurityService;
use App\Services\Finance\ClientInvoicePaymentWorkflowService;
use App\Services\Finance\CouponService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentService;
use App\Services\Order\PaidOrderBusinessFlowDispatcher;
use App\Services\Provisioning\ProvisionService;
use App\Services\Provisioning\ServiceRenewService;
use App\Services\Referral\ReferralService;
use App\Services\User\AccountService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\TestCase;

/**
 * 资金安全负向测试：quote token 篡改、越权支付、状态机非法流转、金额不符回调、退款超限、重复提现。
 */
class FinanceNegativeGuardTest extends TestCase
{
    public function test_quote_token_rejects_tampered_config(): void
    {
        $security = new CheckoutSecurityService;
        $tokenData = $security->issueQuoteToken(1, 'monthly', ['cpu' => '2'], [
            'subtotal_amount' => '20.00',
            'total_amount' => '20.00',
            'base_amount' => '20.00',
        ]);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('订单配置与报价不一致，请重新获取报价');
        $security->assertQuoteToken(
            (string) ($tokenData['quote_token'] ?? ''),
            1,
            'monthly',
            1,
            ['cpu' => '4'], // 篡改配置
            '20.00',
            '20.00',
            0,
        );
    }

    public function test_quote_token_rejects_tampered_amount(): void
    {
        $security = new CheckoutSecurityService;
        $tokenData = $security->issueQuoteToken(1, 'monthly', [], [
            'subtotal_amount' => '20.00',
            'total_amount' => '20.00',
            'base_amount' => '20.00',
        ]);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('报价已变更，请刷新页面后重试');
        $security->assertQuoteToken(
            (string) ($tokenData['quote_token'] ?? ''),
            1,
            'monthly',
            1,
            [],
            '20.00',
            '19.99', // 篡改金额
            0,
        );
    }

    public function test_paid_invoice_cannot_be_paid_again(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('neg-paid');
        $invoice->forceFill([
            'status' => InvoiceStatus::PAID,
            'paid_amount' => '50.00',
            'paid_at' => now(),
        ])->save();
        $user->forceFill(['balance' => '100.00'])->save();

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('账单状态异常，无法支付');
        app(PaymentService::class)->payByBalance($invoice, $user, ['trace_id' => 'neg-paid']);
    }

    public function test_gateway_notify_rejects_amount_mismatch(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('neg-notify');
        $payment = Payment::query()->create([
            'payment_no' => 'PAYNEG'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => PaymentGatewayCode::ALIPAY,
            'gateway_key' => PaymentGatewayCode::ALIPAY,
            'amount' => '50.00',
            'status' => PaymentStatus::PENDING,
        ]);

        $alipayGateway = $this->makeFakePaymentGateway(['verify_notify' => true, 'matches_merchant' => true]);
        $service = $this->makePaymentService($alipayGateway);

        $success = $service->handleGatewayNotify(PaymentGatewayCode::ALIPAY, [
            'out_trade_no' => (string) $payment->payment_no,
            'trade_no' => 'TRADE-NEG-AMOUNT',
            'trade_status' => 'TRADE_SUCCESS',
            'total_amount' => '999.00', // 金额篡改
            'app_id' => 'mock-app-id',
        ]);

        $this->assertFalse($success);
        $this->assertSame(PaymentStatus::PENDING, (int) $payment->refresh()->status);
        $this->assertSame(InvoiceStatus::UNPAID, (int) $invoice->refresh()->status);
    }

    public function test_refund_exceeding_refundable_amount_rejected(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('neg-refund');
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
        Payment::query()->create([
            'payment_no' => 'PAYREFUND'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => PaymentGatewayCode::ALIPAY,
            'gateway_key' => PaymentGatewayCode::ALIPAY,
            'trade_no' => 'TRADE-REFUND',
            'amount' => '50.00',
            'status' => PaymentStatus::SUCCESS,
            'paid_at' => now(),
        ]);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('退款金额超过原单可退金额');
        app(PaymentService::class)->refundInvoiceToBalance($user, $invoice, [
            'amount' => 999.00, // 超限
        ], ['trace_id' => 'neg-refund']);
    }

    public function test_duplicate_withdrawal_application_rejected(): void
    {
        $user = $this->createUser('neg-withdraw', verified: true);
        app(AccountService::class)->updateAccount($user, ['referral_available_balance' => '100.00']);

        $service = app(ReferralService::class);
        $service->createWithdrawal($user, [
            'amount' => 50.00,
            'method' => 'balance',
        ], 'trace-withdraw-apply-'.bin2hex(random_bytes(4)));

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('已有待处理提现申请，请等待审核完成');
        $service->createWithdrawal($user->fresh(), [
            'amount' => 50.00,
            'method' => 'balance',
        ], 'trace-withdraw-apply-'.bin2hex(random_bytes(4)));
    }

    public function test_user_cannot_pay_another_users_invoice(): void
    {
        $owner = $this->createUser('neg-owner', verified: true);
        $attacker = $this->createUser('neg-attacker');
        $attacker->forceFill(['balance' => '100.00'])->save();

        $invoice = Invoice::query()->create([
            'invoice_no' => 'INVNEGOWN'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => (int) $owner->id,
            'type' => 'normal',
            'amount' => '50.00',
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::UNPAID,
            'due_date' => now()->addDay(),
        ]);

        $this->expectException(ModelNotFoundException::class);
        app(ClientInvoicePaymentWorkflowService::class)->payByBalance(
            $attacker,
            (int) $invoice->id,
            'invalid-session-token',
            ['trace_id' => 'neg-ownership'],
            [],
        );
    }

    /**
     * @return array{0: User, 1: Order, 2: Invoice}
     */
    private function createUserOrderInvoice(string $prefix, string $amount = '50.00'): array
    {
        $user = $this->createUser($prefix, verified: true);
        $user->forceFill(['balance' => '100.00'])->save();

        $order = Order::query()->create([
            'order_no' => strtoupper($prefix).'ORD'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => (int) $user->id,
            'type' => 'new',
            'amount' => $amount,
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'status' => OrderStatus::PENDING,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => strtoupper($prefix).'INV'.strtoupper(bin2hex(random_bytes(4))),
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

    private function makePaymentService(PaymentGatewayInterface $gateway): PaymentService
    {
        return new PaymentService(
            $this->createMock(ProvisionService::class),
            $this->makePaymentGatewayManagerForTest($gateway),
            $this->createMock(ServiceRenewService::class),
            $this->createMock(ReferralService::class),
            $this->createMock(PaidOrderBusinessFlowDispatcher::class),
            $this->createMock(AdminOrderNotificationService::class),
            $this->createMock(CouponService::class),
            new InvoiceService,
        );
    }

    private function createUser(string $prefix, bool $verified = false): User
    {
        return User::query()->create([
            'email' => $prefix.'-'.bin2hex(random_bytes(4)).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'is_verified' => $verified ? 1 : 0,
            'verification_status' => $verified ? 2 : 0,
        ]);
    }
}
