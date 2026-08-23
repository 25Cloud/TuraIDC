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
use ReflectionMethod;
use Tests\TestCase;

/**
 * 支付回调必须绑定「回调网关」与「支付记录渠道」。
 *
 * 缺这道校验时，回调只按 payment_no 查单，任一网关的密钥就等于「确认任意渠道支付」的
 * 权限：拿易支付的 MD5 商户密钥向易支付回调端点提交一笔 alipay 渠道的 PENDING 单号，
 * 两道验签都用易支付密钥、因此都通过，账单随即被标记已付并触发开通。
 */
class PaymentCallbackGatewayBindingTest extends TestCase
{
    /**
     * 跨网关伪造回调必须被拒绝：alipay 渠道的支付单不接受 yipay 的回调。
     */
    public function test_gateway_notify_rejects_payment_from_another_gateway(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('crossgw', '66.00');
        $payment = $this->createPendingPayment($user, $order, $invoice, PaymentGatewayCode::ALIPAY, '66.00');

        // 攻击者持有易支付密钥，因此易支付侧验签必然通过
        $yipay = $this->makeFakePaymentGateway([
            'key' => PaymentGatewayCode::YIPAY,
            'verify_notify' => true,
            'matches_merchant' => true,
        ]);

        $accepted = $this->makePaymentService($yipay)->handleGatewayNotify(PaymentGatewayCode::YIPAY, [
            'out_trade_no' => (string) $payment->payment_no,
            'trade_status' => 'TRADE_SUCCESS',
            'trade_no' => 'FORGED-'.strtoupper(bin2hex(random_bytes(4))),
            'money' => '66.00',
        ]);

        $this->assertFalse($accepted, '跨网关回调必须被拒绝');
        $this->assertDatabaseHas('payments', [
            'id' => (int) $payment->id,
            'status' => PaymentStatus::PENDING,
        ]);
        $this->assertDatabaseHas('invoices', [
            'id' => (int) $invoice->id,
            'status' => InvoiceStatus::UNPAID,
            'paid_amount' => '0.00',
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => (int) $order->id,
            'status' => OrderStatus::PENDING,
        ]);
    }

    /**
     * 省掉商户号字段也不能绕过绑定。
     *
     * 商户号那道校验是 `$merchantId !== '' && ! matchesMerchantId(...)`，攻击者控制
     * 整个 params，不传 pid/app_id/mch_id 即可让它整段跳过——所以绑定校验不能依赖它。
     */
    public function test_gateway_notify_rejects_cross_gateway_even_without_merchant_id(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('nomerch', '42.00');
        $payment = $this->createPendingPayment($user, $order, $invoice, PaymentGatewayCode::ALIPAY, '42.00');

        $yipay = $this->makeFakePaymentGateway([
            'key' => PaymentGatewayCode::YIPAY,
            'verify_notify' => true,
            // 商户号一律不匹配，证明拒绝不是由这道校验产生的
            'matches_merchant' => false,
        ]);

        $accepted = $this->makePaymentService($yipay)->handleGatewayNotify(PaymentGatewayCode::YIPAY, [
            'out_trade_no' => (string) $payment->payment_no,
            'trade_status' => 'TRADE_SUCCESS',
            'trade_no' => 'FORGED-'.strtoupper(bin2hex(random_bytes(4))),
            'money' => '42.00',
            // 刻意不带 pid / app_id / mch_id
        ]);

        $this->assertFalse($accepted);
        $this->assertDatabaseHas('payments', [
            'id' => (int) $payment->id,
            'status' => PaymentStatus::PENDING,
        ]);
    }

    /**
     * 支付宝专属回调入口同样要绑定：不接受非 alipay 渠道的支付单。
     */
    public function test_alipay_notify_rejects_non_alipay_payment(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('alipaybind', '30.00');
        $payment = $this->createPendingPayment($user, $order, $invoice, PaymentGatewayCode::YIPAY, '30.00');

        $alipay = $this->makeFakePaymentGateway([
            'verify_notify' => true,
            'matches_merchant' => true,
        ]);

        $accepted = $this->makePaymentService($alipay)->handleAlipayNotify([
            'app_id' => 'mock-app-id',
            'out_trade_no' => (string) $payment->payment_no,
            'trade_status' => 'TRADE_SUCCESS',
            'trade_no' => 'FORGED-'.strtoupper(bin2hex(random_bytes(4))),
            'total_amount' => '30.00',
        ]);

        $this->assertFalse($accepted, '支付宝回调不得确认易支付渠道的支付单');
        $this->assertDatabaseHas('payments', [
            'id' => (int) $payment->id,
            'status' => PaymentStatus::PENDING,
        ]);
    }

    /**
     * 比对必须走 normalize，不能裸比字符串。
     *
     * 当前所有网关插件的 key() 返回的都已是归一化值（目录名 ali_pay，key() 返回
     * 'alipay'），而 PaymentGatewayRegistry 按 key() 索引，所以能通过 resolveGateway
     * 的 $gateway 恒等于归一化值——normalize 对今天的取值是幂等的。
     *
     * 但 `PaymentGatewayCode::normalize()` 里明确保留了 alipay_f2f / ali_pay / yi_pay
     * 三个别名映射，`payments.gateway_key` 存的又是归一化值。一旦某个插件的 key() 改回
     * 别名形态（常量 ALIPAY_F2F_PLUGIN 就是为此存在的），裸比字符串会把该网关全部正常
     * 回调判为不匹配而拒付。这条用例钉住别名口径，避免后来者把 normalize 当冗余删掉。
     */
    public function test_gateway_binding_resolves_plugin_slug_aliases(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('alias', '15.00');
        $alipayPayment = $this->createPendingPayment($user, $order, $invoice, PaymentGatewayCode::ALIPAY, '15.00');

        // 库里存的是归一化值
        $this->assertSame(PaymentGatewayCode::ALIPAY, $alipayPayment->gatewayKey());

        foreach (['alipay', 'ali_pay', 'alipay_f2f'] as $alias) {
            $this->assertTrue(
                $this->paymentBelongsToGateway($alipayPayment, $alias),
                "支付宝别名 {$alias} 必须能匹配 gateway_key=alipay 的支付单"
            );
        }

        foreach (['yipay', 'yi_pay', 'demo_pay', 'wechat', ''] as $other) {
            $this->assertFalse(
                $this->paymentBelongsToGateway($alipayPayment, $other),
                "非支付宝网关 {$other} 不得匹配 gateway_key=alipay 的支付单"
            );
        }

        $yipayPayment = $this->createPendingPayment($user, $order, $invoice, PaymentGatewayCode::YIPAY, '15.00');
        foreach (['yipay', 'yi_pay'] as $alias) {
            $this->assertTrue($this->paymentBelongsToGateway($yipayPayment, $alias));
        }
        $this->assertFalse($this->paymentBelongsToGateway($yipayPayment, 'alipay'));
    }

    /** 同网关的正常回调不受影响。 */
    public function test_same_gateway_notify_is_accepted(): void
    {
        [$user, $order, $invoice] = $this->createUserOrderInvoice('samegw', '88.00');
        $payment = $this->createPendingPayment($user, $order, $invoice, PaymentGatewayCode::YIPAY, '88.00');

        $yipay = $this->makeFakePaymentGateway([
            'key' => PaymentGatewayCode::YIPAY,
            'verify_notify' => true,
            'matches_merchant' => true,
        ]);

        $accepted = $this->makePaymentService($yipay)->handleGatewayNotify(PaymentGatewayCode::YIPAY, [
            'out_trade_no' => (string) $payment->payment_no,
            'trade_status' => 'TRADE_SUCCESS',
            'trade_no' => 'OK-'.strtoupper(bin2hex(random_bytes(4))),
            'money' => '88.00',
            'pid' => 'mock-pid',
        ]);

        $this->assertTrue($accepted, '同网关正常回调必须放行');
        $this->assertDatabaseHas('payments', [
            'id' => (int) $payment->id,
            'status' => PaymentStatus::SUCCESS,
        ]);
        $this->assertDatabaseHas('invoices', [
            'id' => (int) $invoice->id,
            'status' => InvoiceStatus::PAID,
        ]);
    }

    // ── 构造辅助 ──

    /** @return array{0: User, 1: Order, 2: Invoice} */
    private function createUserOrderInvoice(string $prefix, string $amount): array
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => strtolower($prefix).'-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => $prefix,
        ]);
        $user->forceFill(['balance' => '0.00'])->save();

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

    private function createPendingPayment(
        User $user,
        Order $order,
        Invoice $invoice,
        string $gateway,
        string $amount,
    ): Payment {
        return Payment::query()->create([
            'payment_no' => Payment::generatePaymentNo(),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => $gateway,
            'amount' => $amount,
            'status' => PaymentStatus::PENDING,
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
            // 正常入账会走财务凭证落库，那不是本用例的关注点；不 mock 会让事务回滚。
            $this->createMock(FinanceDocumentService::class),
        );
    }

    private function paymentBelongsToGateway(Payment $payment, string $gateway): bool
    {
        $method = new ReflectionMethod(PaymentService::class, 'paymentBelongsToGateway');
        $method->setAccessible(true);

        return (bool) $method->invoke($this->makePaymentService(), $payment, $gateway);
    }
}
