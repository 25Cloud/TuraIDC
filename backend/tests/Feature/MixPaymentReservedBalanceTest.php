<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Contracts\Integrations\Payments\PaymentGatewayInterface;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Finance\AdminOrderNotificationService;
use App\Services\Finance\CouponService;
use App\Services\Finance\FinanceDocumentService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentService;
use App\Services\Integrations\Payments\PaymentGatewayManager;
use App\Services\Integrations\Payments\PaymentGatewayRegistry;
use App\Services\Order\PaidOrderBusinessFlowDispatcher;
use App\Services\Provisioning\ProvisionService;
use App\Services\Provisioning\ServiceRenewService;
use App\Services\Referral\ReferralService;
use Tests\TestCase;

/**
 * 组合支付预留余额在账单终态化时的归属。
 *
 * 两种语境的正确行为恰好相反，且都是真金白银，所以正反两面都要钉住：
 *
 * - 账单**已付**：预扣余额已计入 invoice.paid_amount 被账单消费，再退就是重复退款。
 * - 账单**作废**：账单什么也没换到，预扣余额必须回到用户账上。
 *
 * 原实现在 closeOtherPendingPayments 里对两种语境一律只置 FAILED，于是作废场景丢钱。
 */
class MixPaymentReservedBalanceTest extends TestCase
{
    /**
     * 账单作废（窗口过期）时，兄弟支付预扣的余额必须退回。
     *
     * 账单 100：先垫 30 建 A(70)，再垫 20 建 B(50)，两笔 mix 并存（复用条件是 amount
     * 相等，所以确实能并存）。窗口过期后扫 B 的码付 50 —— 回调退 B 的 20、50 元转入
     * 余额、账单 CANCELLED。A 若只被置 FAILED，它垫付的 30 元就凭空消失，且此后
     * CheckoutService::cancel（只收 UNPAID/OVERDUE）与 OrderService::cancel（要求订单
     * 仍 PENDING，而过期路径已取消订单）都进不去，也没有孤儿 PENDING 对账任务。
     */
    public function test_expired_invoice_restores_sibling_mix_reserved_balance(): void
    {
        [$user, $order, $invoice] = $this->createExpiredInvoice('mixexp', '100.00');

        // 两笔预扣共 50 元已计入账单已付
        $invoice->forceFill(['paid_amount' => '50.00'])->save();
        $paymentA = $this->createMixPayment($user, $order, $invoice, '70.00', '30.00');
        $paymentB = $this->createMixPayment($user, $order, $invoice, '50.00', '20.00');

        $balanceBefore = (float) User::query()->findOrFail((int) $user->id)->balance;

        $gateway = $this->makeFakePaymentGateway([
            'verify_notify' => true,
            'matches_merchant' => true,
        ]);

        $this->makePaymentService($gateway)->handleAlipayNotify([
            'app_id' => 'mock-app-id',
            'out_trade_no' => (string) $paymentB->payment_no,
            'trade_status' => 'TRADE_SUCCESS',
            'trade_no' => 'EXPIRED-'.strtoupper(bin2hex(random_bytes(4))),
            'total_amount' => '50.00',
        ]);

        $balanceAfter = (float) User::query()->findOrFail((int) $user->id)->balance;

        // B 的预扣 20 + B 的网关款 50 + A 的预扣 30 = 100 全部回到余额
        $this->assertSame(
            round($balanceBefore + 100.0, 2),
            round($balanceAfter, 2),
            '账单作废后用户付出的每一分钱都应回到余额：A 垫 30 + B 垫 20 + 网关 50'
        );

        $this->assertTrue(
            (bool) data_get((array) $paymentA->refresh()->callback_raw, 'balance_restored'),
            'A 的预扣余额必须被标记为已恢复'
        );
        $this->assertSame(PaymentStatus::FAILED, (int) $paymentA->refresh()->status);
        $this->assertDatabaseHas('invoices', [
            'id' => (int) $invoice->id,
            'status' => InvoiceStatus::CANCELLED,
        ]);
    }

    /**
     * 账单正常付清时，兄弟支付的预扣余额**不得**退回——否则是重复退款。
     *
     * 这一条是防止「修复方向搞反」的护栏。账单 100：A 垫 30（paid_amount=30，A 的应付
     * 70），B 垫 20（paid_amount=50，B 的应付 50）。扫 B 的码付 50 后账单付清，用户
     * 共付 30 + 20 + 50 = 100 换到 100 元的服务，收支平衡。此时若再把 A 的 30 元退回
     * 余额，用户就白拿 30 元，且可反复触发。
     */
    public function test_paid_invoice_does_not_refund_sibling_mix_reserved_balance(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('mixpaid', '100.00');

        $invoice->forceFill(['paid_amount' => '50.00'])->save();
        $order->forceFill(['paid_amount' => '50.00'])->save();
        $paymentA = $this->createMixPayment($user, $order, $invoice, '70.00', '30.00');
        $paymentB = $this->createMixPayment($user, $order, $invoice, '50.00', '20.00');

        $balanceBefore = (float) User::query()->findOrFail((int) $user->id)->balance;

        $gateway = $this->makeFakePaymentGateway([
            'verify_notify' => true,
            'matches_merchant' => true,
        ]);

        $this->makePaymentService($gateway)->handleAlipayNotify([
            'app_id' => 'mock-app-id',
            'out_trade_no' => (string) $paymentB->payment_no,
            'trade_status' => 'TRADE_SUCCESS',
            'trade_no' => 'PAID-'.strtoupper(bin2hex(random_bytes(4))),
            'total_amount' => '50.00',
        ]);

        $balanceAfter = (float) User::query()->findOrFail((int) $user->id)->balance;

        $this->assertSame(
            round($balanceBefore, 2),
            round($balanceAfter, 2),
            '账单已付清，预扣余额已被账单消费，绝不能再退——那是可反复触发的重复退款'
        );
        $this->assertFalse(
            (bool) data_get((array) $paymentA->refresh()->callback_raw, 'balance_restored'),
            'A 不应被标记为已恢复余额'
        );
        $this->assertSame(PaymentStatus::FAILED, (int) $paymentA->refresh()->status, 'A 仍应被正常关闭');
        $this->assertDatabaseHas('invoices', [
            'id' => (int) $invoice->id,
            'status' => InvoiceStatus::PAID,
        ]);
    }

    /**
     * 非组合支付的兄弟单在作废场景下仍走普通关闭，不受影响。
     */
    public function test_expired_invoice_closes_plain_sibling_payment_without_balance_change(): void
    {
        [$user, $order, $invoice] = $this->createExpiredInvoice('mixplain', '100.00');

        $invoice->forceFill(['paid_amount' => '20.00'])->save();
        // 纯网关单（无 mix_payment 标记），没有预扣余额可退
        $plain = Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => PaymentGatewayCode::ALIPAY,
            'amount' => '100.00',
            'status' => PaymentStatus::PENDING,
        ]);
        $captured = $this->createMixPayment($user, $order, $invoice, '80.00', '20.00');

        $balanceBefore = (float) User::query()->findOrFail((int) $user->id)->balance;

        $gateway = $this->makeFakePaymentGateway([
            'verify_notify' => true,
            'matches_merchant' => true,
        ]);

        $this->makePaymentService($gateway)->handleAlipayNotify([
            'app_id' => 'mock-app-id',
            'out_trade_no' => (string) $captured->payment_no,
            'trade_status' => 'TRADE_SUCCESS',
            'trade_no' => 'PLAIN-'.strtoupper(bin2hex(random_bytes(4))),
            'total_amount' => '80.00',
        ]);

        $balanceAfter = (float) User::query()->findOrFail((int) $user->id)->balance;

        // 只有被捕获那笔的预扣 20 + 网关款 80 回到余额，纯网关兄弟单不产生额外变动
        $this->assertSame(round($balanceBefore + 100.0, 2), round($balanceAfter, 2));
        $this->assertSame(PaymentStatus::FAILED, (int) $plain->refresh()->status);
        $this->assertFalse((bool) data_get((array) $plain->refresh()->callback_raw, 'balance_restored'));
    }

    // ── 构造辅助 ──

    /** @return array{0: User, 1: Order, 2: Invoice} */
    private function createUserOrderInvoice(string $prefix, string $amount, string $balance = '500.00'): array
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => strtolower($prefix).'-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => $prefix,
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

    /**
     * 支付窗口按 invoice.created_at + paymentSessionTtlSeconds 计算，
     * 把创建时间推到很久以前即为过期。
     *
     * @return array{0: User, 1: Order, 2: Invoice}
     */
    private function createExpiredInvoice(string $prefix, string $amount): array
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice($prefix, $amount);

        $invoice->forceFill(['created_at' => now()->subDays(30)])->save();

        return [$user, $order, $invoice->refresh()];
    }

    private function createMixPayment(
        User $user,
        Order $order,
        Invoice $invoice,
        string $amount,
        string $balanceAmount,
    ): Payment {
        return Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => PaymentGatewayCode::ALIPAY,
            'amount' => $amount,
            'status' => PaymentStatus::PENDING,
            'callback_raw' => [
                'source' => 'alipay_precreate_mix',
                'mix_payment' => true,
                'balance_amount' => $balanceAmount,
            ],
        ]);
    }

    private function makePaymentService(?PaymentGatewayInterface $gateway = null): PaymentService
    {
        return new PaymentService(
            $this->createMock(ProvisionService::class),
            new PaymentGatewayManager(new PaymentGatewayRegistry([
                $gateway ?? $this->makeFakePaymentGateway(),
            ])),
            $this->createMock(ServiceRenewService::class),
            $this->createMock(ReferralService::class),
            $this->createMock(PaidOrderBusinessFlowDispatcher::class),
            $this->createMock(AdminOrderNotificationService::class),
            $this->createMock(CouponService::class),
            new InvoiceService,
            null,
            null,
            // 账单付清后会走 recordSuccessfulInvoicePayment → 财务凭证落库，那不是本用例的
            // 关注点；不 mock 会让整个回调事务回滚，状态断言全部失真。
            $this->createMock(FinanceDocumentService::class),
        );
    }
}
