<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\Finance\PaymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 开通队列重试耗尽后的资金安全标记：已支付未履约的购买账单必须被标记
 * requires_refund，避免用户资金悬挂在 PROCESSING 订单上无人认领。
 */
class ProvisionFulfillmentRefundFlagTest extends TestCase
{
    use DatabaseTransactions;

    public function test_marks_paid_unfulfilled_new_order_invoice_as_requires_refund(): void
    {
        $user = $this->makeUser();
        [$order, $invoice] = $this->makePaidUnfulfilledStack($user);

        app(PaymentService::class)->markOrderFulfillmentRequiresRefund((int) $order->id);

        $snapshot = (array) ($invoice->fresh()->config_snapshot ?? []);
        $this->assertTrue((bool) ($snapshot['requires_refund'] ?? false));
    }

    public function test_ignores_completed_orders_and_unpaid_invoices(): void
    {
        $user = $this->makeUser();

        // 已完成订单：不标记
        [$completedOrder, $completedInvoice] = $this->makePaidUnfulfilledStack($user);
        $completedOrder->forceFill(['status' => OrderStatus::COMPLETED])->save();
        app(PaymentService::class)->markOrderFulfillmentRequiresRefund((int) $completedOrder->id);
        $this->assertFalse((bool) (($completedInvoice->fresh()->config_snapshot ?? [])['requires_refund'] ?? false));

        // 未支付账单：不标记（没有资金悬挂）
        [$unpaidOrder, $unpaidInvoice] = $this->makePaidUnfulfilledStack($user, InvoiceStatus::UNPAID);
        app(PaymentService::class)->markOrderFulfillmentRequiresRefund((int) $unpaidOrder->id);
        $this->assertFalse((bool) (($unpaidInvoice->fresh()->config_snapshot ?? [])['requires_refund'] ?? false));

        // 续费订单：有自己的恢复与退款链路，不在此标记
        [$renewOrder, $renewInvoice] = $this->makePaidUnfulfilledStack($user, InvoiceStatus::PAID, 'renew');
        app(PaymentService::class)->markOrderFulfillmentRequiresRefund((int) $renewOrder->id);
        $this->assertFalse((bool) (($renewInvoice->fresh()->config_snapshot ?? [])['requires_refund'] ?? false));
    }

    public function test_is_idempotent_for_repeated_calls(): void
    {
        $user = $this->makeUser();
        [$order, $invoice] = $this->makePaidUnfulfilledStack($user);

        $service = app(PaymentService::class);
        $service->markOrderFulfillmentRequiresRefund((int) $order->id);
        $service->markOrderFulfillmentRequiresRefund((int) $order->id);

        $snapshot = (array) ($invoice->fresh()->config_snapshot ?? []);
        $this->assertTrue((bool) ($snapshot['requires_refund'] ?? false));
        // 原有快照内容不被覆盖，标记也不因重复调用而翻倍
        $this->assertSame('basic', (string) ($snapshot['plan'] ?? ''));
    }

    private function makeUser(): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => "refund-flag-{$suffix}@example.test",
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);
    }

    /**
     * @return array{0: Order, 1: Invoice}
     */
    private function makePaidUnfulfilledStack(User $user, int $invoiceStatus = InvoiceStatus::PAID, string $orderType = 'new'): array
    {
        $suffix = bin2hex(random_bytes(4));

        $invoice = Invoice::query()->create([
            'invoice_no' => 'RF'.$suffix,
            'user_id' => (int) $user->id,
            'type' => $orderType,
            'amount' => '88.00',
            'paid_amount' => $invoiceStatus === InvoiceStatus::PAID ? '88.00' : '0.00',
            'status' => $invoiceStatus,
            'paid_at' => $invoiceStatus === InvoiceStatus::PAID ? now() : null,
            'config_snapshot' => ['plan' => 'basic'],
        ]);

        $order = Order::query()->create([
            'order_no' => 'RFNO'.$suffix,
            'user_id' => (int) $user->id,
            'type' => $orderType,
            'status' => OrderStatus::PROCESSING,
            'amount' => '88.00',
        ]);
        $invoice->forceFill(['order_id' => (int) $order->id])->save();
        $order->refresh();

        return [$order, $invoice];
    }
}
