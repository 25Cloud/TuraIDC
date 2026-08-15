<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\PaymentStatus;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientRecordResponsibilityBoundaryTest extends TestCase
{
    public function test_client_payment_records_include_third_party_recharge_and_purchase_payments(): void
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $user = $this->createClientUser('record-boundary-'.$suffix);

        $order = Order::query()->create([
            'order_no' => 'ORDRB'.$suffix,
            'user_id' => (int) $user->id,
            'type' => 'new',
            'amount' => '99.00',
            'discount' => '0.00',
            'paid_amount' => '99.00',
            'billing_cycle' => 'monthly',
            'status' => OrderStatus::PAID,
            'paid_at' => now(),
        ]);

        $purchaseInvoice = Invoice::query()->create([
            'invoice_no' => 'INVRBP'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'type' => 'new',
            'amount' => '99.00',
            'paid_amount' => '99.00',
            'status' => InvoiceStatus::PAID,
            'due_date' => now()->addDay(),
            'paid_at' => now(),
        ]);

        $purchasePayment = Payment::query()->create([
            'payment_no' => 'PAYBUY'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $purchaseInvoice->id,
            'gateway' => 'alipay',
            'gateway_key' => 'alipay',
            'trade_no' => 'TRADEBUY'.$suffix,
            'amount' => '99.00',
            'status' => PaymentStatus::SUCCESS,
            'paid_at' => now(),
        ]);

        $rechargeInvoice = Invoice::query()->create([
            'invoice_no' => 'INVRBR'.$suffix,
            'user_id' => (int) $user->id,
            'type' => 'recharge',
            'amount' => '200.00',
            'paid_amount' => '200.00',
            'status' => InvoiceStatus::PAID,
            'due_date' => now()->addDay(),
            'paid_at' => now(),
        ]);

        $alipayRecharge = Payment::query()->create([
            'payment_no' => 'PAYREC'.$suffix,
            'user_id' => (int) $user->id,
            'invoice_id' => (int) $rechargeInvoice->id,
            'gateway' => 'alipay',
            'gateway_key' => 'alipay',
            'trade_no' => 'TRADEREC'.$suffix,
            'amount' => '200.00',
            'status' => PaymentStatus::SUCCESS,
            'paid_at' => now(),
        ]);

        $wechatPendingRecharge = Payment::query()->create([
            'payment_no' => 'PAYWXR'.$suffix,
            'user_id' => (int) $user->id,
            'invoice_id' => null,
            'gateway_key' => 'wechat',
            'trade_no' => null,
            'amount' => '300.00',
            'status' => PaymentStatus::PENDING,
        ]);

        $manualPaymentNo = 'PAYMAN'.$suffix;
        DB::table('payments')->insert([
            'payment_no' => $manualPaymentNo,
            'user_id' => (int) $user->id,
            'invoice_id' => (int) $rechargeInvoice->id,
            'gateway_key' => 'manual',
            'amount' => '50.00',
            'status' => PaymentStatus::SUCCESS,
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $manualPayment = (object) [
            'payment_no' => $manualPaymentNo,
        ];

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v2/client/payments?page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.list.0.gateway', 'wechat')
            ->assertJsonPath('data.list.0.gateway_key', 'wechat')
            ->assertJsonPath('data.list.1.payment_no', (string) $alipayRecharge->payment_no)
            ->assertJsonPath('data.list.1.gateway_key', 'alipay')
            ->assertJsonPath('data.list.1.gateway_label', '支付宝')
            ->assertJsonPath('data.list.1.invoice_type', 'recharge')
            ->assertJsonPath('data.list.2.payment_no', (string) $purchasePayment->payment_no)
            ->assertJsonPath('data.list.2.invoice_type', 'new')
            ->assertJsonPath('data.list.2.invoice_no', (string) $purchaseInvoice->invoice_no)
            ->assertJsonPath('data.list.1.trade_no', (string) $alipayRecharge->trade_no)
            ->assertJsonPath('data.list.2.trade_no', (string) $purchasePayment->trade_no);

        $paymentNos = collect($response->json('data.list'))->pluck('payment_no')->all();
        $this->assertContains((string) $purchasePayment->payment_no, $paymentNos);
        $this->assertContains((string) $alipayRecharge->payment_no, $paymentNos);
        $this->assertContains((string) $wechatPendingRecharge->payment_no, $paymentNos);
        $this->assertNotContains((string) $manualPayment->payment_no, $paymentNos);

        $this->getJson('/api/v2/client/payments/summary')
            ->assertOk()
            ->assertJsonPath('data.total', 3)
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.success', 2);

        $this->getJson('/api/v2/client/payments?keyword='.$purchasePayment->trade_no.'&page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.payment_no', (string) $purchasePayment->payment_no);

        $this->getJson('/api/v2/client/payments/'.$purchasePayment->id)
            ->assertOk()
            ->assertJsonPath('data.gateway_key', 'alipay')
            ->assertJsonPath('data.gateway_label', '支付宝');
    }

    public function test_client_order_and_payment_v2_endpoints_reject_legacy_and_non_list_pagination_parameters(): void
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $user = $this->createClientUser('record-validation-'.$suffix);

        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/payments?per_page=20')
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/client/payments?pageSize=20')
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $this->getJson('/api/v2/client/payments/summary?page=1')
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['page']]]);

        $this->getJson('/api/v2/client/payments/1?pageSize=20')
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $this->getJson('/api/v2/client/orders?per_page=20')
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/client/orders?pageSize=20')
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $this->getJson('/api/v2/client/orders/summary?page_size=20')
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['page_size']]]);

        $this->getJson('/api/v2/client/orders/1?page=1')
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['page']]]);
    }

    public function test_client_order_list_and_summary_can_run_expired_order_cleanup(): void
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $user = $this->createClientUser('order-cleanup-'.$suffix);

        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/orders?page=1&page_size=10')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->getJson('/api/v2/client/orders/summary')
            ->assertOk()
            ->assertJsonPath('code', 0);
    }

    public function test_client_order_records_are_purchase_service_orders_not_recharge_invoices(): void
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $user = $this->createClientUser('order-boundary-'.$suffix);

        $product = Product::query()->create([
            'name' => 'Boundary Test Product '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '88.00'],
            'purchase_requires' => [],
            'config_options' => [],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 0,
            'provision_module' => null,
            'auto_setup' => 0,
        ]);

        $order = Order::query()->create([
            'order_no' => 'ORDOB'.$suffix,
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => '边界测试云服务器',
            'product_type_snapshot' => 'server',
            'type' => 'new',
            'amount' => '88.00',
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'config_snapshot' => [
                'region' => '美国',
                'node' => '三网精品',
                'cpu' => '2 vCPU',
                'memory' => '2 GiB',
            ],
            'config_pricing_snapshot' => [
                'items' => [
                    ['label' => 'CPU', 'value' => '2 vCPU', 'amount' => '20.00'],
                    ['label' => '内存', 'value' => '2 GiB', 'amount' => '18.00'],
                ],
                'total_amount' => '88.00',
            ],
            'status' => OrderStatus::PENDING,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'INVOB'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => '边界测试云服务器',
            'product_type_snapshot' => 'server',
            'type' => 'new',
            'amount' => '88.00',
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::UNPAID,
            'due_date' => now()->addDay(),
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'name' => 'boundary-service',
            'domain' => 'boundary.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '88.00',
            'status' => 1,
        ]);
        $order->forceFill(['service_id' => (int) $service->id])->save();

        $payment = Payment::query()->create([
            'payment_no' => 'PAYORD'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => 'alipay',
            'trade_no' => 'TRADEORD'.$suffix,
            'amount' => '88.00',
            'status' => PaymentStatus::PENDING,
        ]);

        Invoice::query()->create([
            'invoice_no' => 'INVRECH'.$suffix,
            'user_id' => (int) $user->id,
            'type' => 'recharge',
            'amount' => '120.00',
            'paid_amount' => '120.00',
            'status' => InvoiceStatus::PAID,
            'due_date' => now()->addDay(),
            'paid_at' => now(),
        ]);

        Sanctum::actingAs($user);

        // 使用 /api/v2/client/invoices 替代已删除的 /api/v2/client/orders
        // invoices 按 id DESC 排序，所以 recharge invoice (id 较大) 排在前面
        $this->getJson('/api/v2/client/invoices?page_size=20')
            ->assertOk()
            ->assertJsonPath('data.total', 2)
            ->assertJsonPath('data.list.0.type', 'recharge')
            ->assertJsonPath('data.list.1.invoice_no', (string) $invoice->invoice_no)
            ->assertJsonPath('data.list.1.type', 'new')
            ->assertJsonPath('data.list.1.product_spec_display', '边界测试云服务器');

        $this->getJson('/api/v2/client/invoices/'.$invoice->id)
            ->assertOk()
            ->assertJsonPath('data.invoice.id', (int) $invoice->id)
            ->assertJsonPath('data.invoice.basic.invoice_no', (string) $invoice->invoice_no)
            ->assertJsonPath('data.invoice.payment_chain.payments.0.payment_no', (string) $payment->payment_no)
            ->assertJsonPath('data.invoice.payment_chain.payments.0.trade_no', (string) $payment->trade_no);
    }

    private function createClientUser(string $seed): User
    {
        return User::query()->create([
            'email' => strtolower($seed).'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Record Boundary',
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
}
