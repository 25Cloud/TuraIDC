<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\Automation\InvoiceCleanupAutomationService;
use App\Services\Finance\InvoiceService;
use App\Services\Finance\PaymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 0 元账单收口 + 余额/免费渠道显示。
 *
 * 两件事必须同时成立才算修好：
 * 1. 0 元账单能被收口为已支付并触发履约（而不是抛「无需支付」后被超时清理取消）；
 * 2. 账单/订单页能看出「这笔是用余额付的 / 免费开的」，而不是渠道一片空白。
 */
class ZeroAmountInvoiceSettlementTest extends TestCase
{
    use DatabaseTransactions;

    public function test_zero_amount_invoice_is_settled_by_balance_entry_without_creating_a_payment(): void
    {
        [$user, $order, $invoice] = $this->createOrderInvoice('zero-pay', '0.00');

        $settled = app(PaymentService::class)->payByBalance($invoice, $user, ['trace_id' => 'zero-pay']);

        $this->assertSame(InvoiceStatus::PAID, (int) $settled->status);
        $this->assertSame('0.00', number_format((float) $settled->paid_amount, 2, '.', ''));
        $this->assertNotNull($settled->paid_at);
        $this->assertSame(OrderStatus::PAID, (int) $order->refresh()->status);

        // 0 元没有资金流：不产生支付单、不动余额
        $this->assertSame(0, Payment::query()->where('invoice_id', (int) $invoice->id)->count());
        $this->assertSame('100.00', number_format((float) $user->refresh()->balance, 2, '.', ''));
    }

    public function test_zero_amount_settlement_is_idempotent(): void
    {
        [$user, , $invoice] = $this->createOrderInvoice('zero-idem', '0.00');

        $service = app(PaymentService::class);
        $first = $service->settleZeroAmountInvoice($invoice, $user, ['trace_id' => 'zero-idem-1']);
        $firstPaidAt = $first->paid_at?->toDateTimeString();
        $this->assertNotNull($firstPaidAt);

        Carbon::setTestNow(now()->addMinutes(5));

        try {
            // 再收口一次：直接返回已付账单，不重写 paid_at（等于没有二次履约/二次通知）
            $second = $service->settleZeroAmountInvoice($invoice->fresh(), $user, ['trace_id' => 'zero-idem-2']);

            $this->assertSame(InvoiceStatus::PAID, (int) $second->status);
            $this->assertSame($firstPaidAt, $second->paid_at?->toDateTimeString());
            $this->assertSame(0, Payment::query()->where('invoice_id', (int) $invoice->id)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_batch_settlement_only_touches_zero_amount_unpaid_invoices(): void
    {
        [$user, , $zeroInvoice] = $this->createOrderInvoice('zero-batch', '0.00');
        [, , $paidInvoice] = $this->createOrderInvoice('zero-batch-paid', '30.00');

        $settled = app(PaymentService::class)->settleZeroAmountInvoices();

        $this->assertGreaterThanOrEqual(1, $settled);
        $this->assertSame(InvoiceStatus::PAID, (int) $zeroInvoice->refresh()->status);
        // 正金额账单不在批处理范围内，仍是未支付
        $this->assertSame(InvoiceStatus::UNPAID, (int) $paidInvoice->refresh()->status);
        $this->assertSame('100.00', number_format((float) $user->refresh()->balance, 2, '.', ''));
    }

    public function test_cleanup_automation_settles_zero_amount_invoices_instead_of_cancelling_them(): void
    {
        [$user, $order, $invoice] = $this->createOrderInvoice('zero-cleanup', '0.00', old: true);

        $summary = app(InvoiceCleanupAutomationService::class)->handle();

        $this->assertGreaterThanOrEqual(1, (int) ($summary['zero_amount_invoices_settled'] ?? 0));

        $freshInvoice = $invoice->refresh();
        $this->assertSame(InvoiceStatus::PAID, (int) $freshInvoice->status);
        $this->assertNotSame(InvoiceStatus::CANCELLED, (int) $freshInvoice->status);
        $this->assertSame(OrderStatus::PAID, (int) $order->refresh()->status);
    }

    public function test_balance_paid_invoice_reports_balance_as_the_payment_channel(): void
    {
        [$user, , $invoice] = $this->createOrderInvoice('balance-channel', '50.00');

        app(PaymentService::class)->payByBalance($invoice, $user, ['trace_id' => 'balance-channel']);

        $summary = app(InvoiceService::class)->clientDetail($invoice->fresh())['payment_summary'] ?? null;

        $this->assertIsArray($summary);
        $this->assertSame(PaymentGatewayCode::BALANCE, (string) $summary['gateway']);
        $this->assertSame(PaymentGatewayCode::BALANCE, (string) $summary['gateway_key']);
        $this->assertSame('余额支付', (string) $summary['gateway_label']);
        $this->assertTrue((bool) $summary['derived']);
        $this->assertSame('50.00', (string) $summary['amount']);
    }

    public function test_zero_amount_invoice_reports_free_as_the_payment_channel(): void
    {
        [$user, , $invoice] = $this->createOrderInvoice('free-channel', '0.00');

        app(PaymentService::class)->payByBalance($invoice, $user, ['trace_id' => 'free-channel']);

        $summary = app(InvoiceService::class)->clientDetail($invoice->fresh())['payment_summary'] ?? null;

        $this->assertIsArray($summary);
        $this->assertSame(PaymentGatewayCode::FREE, (string) $summary['gateway']);
        $this->assertSame('免费开通', (string) $summary['gateway_label']);
        $this->assertSame('0.00', (string) $summary['amount']);
        $this->assertTrue((bool) $summary['derived']);
    }

    public function test_third_party_gateway_payment_keeps_the_real_channel(): void
    {
        [$user, $order, $invoice] = $this->createOrderInvoice('gateway-channel', '50.00');

        Payment::query()->create([
            'payment_no' => 'PAYGATEWAY'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => PaymentGatewayCode::ALIPAY,
            'gateway_key' => PaymentGatewayCode::ALIPAY,
            'trade_no' => 'TRADE-GATEWAY',
            'amount' => '50.00',
            'status' => PaymentStatus::SUCCESS,
            'paid_at' => now(),
        ]);

        $invoice->forceFill([
            'status' => InvoiceStatus::PAID,
            'paid_amount' => '50.00',
            'paid_at' => now(),
        ])->save();

        $summary = app(InvoiceService::class)->clientDetail($invoice->fresh())['payment_summary'] ?? null;

        $this->assertIsArray($summary);
        $this->assertSame(PaymentGatewayCode::ALIPAY, (string) $summary['gateway']);
        $this->assertFalse((bool) $summary['derived']);
    }

    /**
     * @return array{0: User, 1: Order, 2: Invoice}
     */
    private function createOrderInvoice(string $prefix, string $amount, bool $old = false): array
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));

        $user = User::query()->create([
            'email' => $prefix.'-'.strtolower($suffix).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'is_verified' => 1,
            'verification_status' => 2,
        ]);
        $user->forceFill(['balance' => '100.00'])->save();

        $order = Order::query()->create([
            'order_no' => strtoupper($prefix).'ORD'.$suffix,
            'user_id' => (int) $user->id,
            'type' => 'new',
            'amount' => $amount,
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'status' => OrderStatus::PENDING,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => strtoupper($prefix).'INV'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'type' => 'normal',
            'amount' => $amount,
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::UNPAID,
            'due_date' => now()->addDay(),
        ]);

        if ($old) {
            // 清理任务的判定基于 created_at 是否超过支付会话 TTL
            DB::table('invoices')->where('id', (int) $invoice->id)->update([
                'created_at' => now()->subHours(2),
            ]);
            $invoice->refresh();
        }

        return [$user, $order, $invoice];
    }
}
