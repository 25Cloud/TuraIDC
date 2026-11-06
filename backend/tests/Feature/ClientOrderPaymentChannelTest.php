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
use App\Services\Finance\PaymentService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 客户端订单详情（`GET /api/v2/client/orders/{id}`）的支付渠道口径。
 *
 * 三种情形必须一致：
 * - 余额已付 → `balance` + `derived=true`（余额支付不产生 Payment 行）；
 * - 0 元免费开通 → `free` + `derived=true`（没有任何资金流）；
 * - 第三方网关 → 真实流水，`derived=false`，不出现推导行。
 */
class ClientOrderPaymentChannelTest extends TestCase
{
    use DatabaseTransactions;

    public function test_balance_paid_order_reports_balance_channel_as_derived(): void
    {
        [$user, $order] = $this->createOrderInvoice('client-order-balance', '50.00');

        app(PaymentService::class)->payByBalance($order->invoice, $user, ['trace_id' => 'client-order-balance']);

        $summary = $this->clientOrderSummary($user, (int) $order->id);

        $this->assertIsArray($summary);
        $this->assertSame(PaymentGatewayCode::BALANCE, (string) $summary['gateway']);
        $this->assertSame(PaymentGatewayCode::BALANCE, (string) $summary['gateway_key']);
        $this->assertSame('余额支付', (string) $summary['gateway_label']);
        $this->assertSame('50.00', (string) $summary['amount']);
        $this->assertTrue((bool) $summary['derived']);
        $this->assertSame(0, (int) $summary['id']);
    }

    public function test_zero_amount_order_reports_free_channel_as_derived(): void
    {
        [$user, $order] = $this->createOrderInvoice('client-order-free', '0.00');

        app(PaymentService::class)->payByBalance($order->invoice, $user, ['trace_id' => 'client-order-free']);

        $summary = $this->clientOrderSummary($user, (int) $order->id);

        $this->assertIsArray($summary);
        $this->assertSame(PaymentGatewayCode::FREE, (string) $summary['gateway']);
        $this->assertSame('免费开通', (string) $summary['gateway_label']);
        $this->assertSame('0.00', (string) $summary['amount']);
        $this->assertTrue((bool) $summary['derived']);
    }

    public function test_third_party_gateway_order_keeps_the_real_payment_row(): void
    {
        [$user, $order, $invoice] = $this->createOrderInvoice('client-order-gateway', '50.00');

        Payment::query()->create([
            'payment_no' => 'PAYCLIENT'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => PaymentGatewayCode::ALIPAY,
            'gateway_key' => PaymentGatewayCode::ALIPAY,
            'trade_no' => 'TRADE-CLIENT-ORDER',
            'amount' => '50.00',
            'status' => PaymentStatus::SUCCESS,
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

        $summary = $this->clientOrderSummary($user, (int) $order->id);

        $this->assertIsArray($summary);
        $this->assertSame(PaymentGatewayCode::ALIPAY, (string) $summary['gateway']);
        $this->assertSame('TRADE-CLIENT-ORDER', (string) $summary['trade_no']);
        $this->assertFalse((bool) $summary['derived']);
        $this->assertGreaterThan(0, (int) $summary['id']);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function clientOrderSummary(User $user, int $orderId): ?array
    {
        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v2/client/orders/'.$orderId)
            ->assertOk()
            ->assertJsonPath('code', 0);

        $summary = $response->json('data.payment_summary');

        return is_array($summary) ? $summary : null;
    }

    /**
     * @return array{0: User, 1: Order, 2: Invoice}
     */
    private function createOrderInvoice(string $prefix, string $amount): array
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

        return [$user, $order, $invoice];
    }
}
