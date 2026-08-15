<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Models\AdminUser;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\User;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2AdminOrderApiTest extends TestCase
{
    public function test_admin_orders_require_login_and_permission(): void
    {
        $fixture = $this->createOrderFixture();

        $this->getJson('/api/v2/admin/orders')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([]));

        $this->getJson('/api/v2/admin/orders')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        $this->getJson('/api/v2/admin/orders/'.$fixture['order']->id)
            ->assertForbidden()
            ->assertJsonPath('code', 40300);
    }

    public function test_admin_order_list_rejects_per_page_and_returns_summary_whitelist(): void
    {
        $fixture = $this->createOrderFixture();

        Sanctum::actingAs($this->createAdmin([AdminPermissions::ORDER_LIST]));

        $this->getJson('/api/v2/admin/orders?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/admin/orders?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $response = $this->getJson('/api/v2/admin/orders?'.http_build_query([
            'keyword' => $fixture['order']->order_no,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 10)
            ->assertJsonPath('data.list.0.id', $fixture['order']->id)
            ->assertJsonMissingPath('data.list.0.config_snapshot')
            ->assertJsonMissingPath('data.list.0.config_pricing_snapshot')
            ->assertJsonMissingPath('data.list.0.payments');

        $this->assertSame($this->orderListWhitelist(), array_keys($response->json('data.list.0')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_admin_order_detail_requires_detail_permission_and_returns_modules_safely(): void
    {
        $fixture = $this->createOrderFixture();

        Sanctum::actingAs($this->createAdmin([AdminPermissions::ORDER_LIST]));

        $this->getJson('/api/v2/admin/orders/'.$fixture['order']->id)
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::ORDER_DETAIL]));
        $paymentCount = Payment::query()->count();

        $this->getJson('/api/v2/admin/orders/'.$fixture['order']->id.'?page=1')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['page']]]);

        $this->getJson('/api/v2/admin/orders/'.$fixture['order']->id.'?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $response = $this->getJson('/api/v2/admin/orders/'.$fixture['order']->id)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.order.id', $fixture['order']->id)
            ->assertJsonPath('data.order.basic.order_no', (string) $fixture['order']->order_no)
            ->assertJsonPath('data.order.financial.amount', '88.00')
            ->assertJsonPath('data.order.configuration.config_snapshot.cpu', '2')
            ->assertJsonPath('data.order.configuration.service_snapshot.instance_id', 1)
            ->assertJsonPath(
                'data.order.configuration.service_snapshot.hostname',
                (string) $fixture['order']->service_snapshot['hostname'],
            )
            ->assertJsonPath('data.order.payment_chain.payments.0.payment_no', (string) $fixture['payment']->payment_no)
            ->assertJsonMissingPath('data.order.configuration.config_snapshot.password')
            ->assertJsonMissingPath('data.order.configuration.config_snapshot.api_key')
            ->assertJsonMissingPath('data.order.configuration.config_pricing_snapshot.raw_response')
            ->assertJsonMissingPath('data.order.payment_chain.payments.0.callback_raw');

        $this->assertSame($paymentCount, Payment::query()->count());
        $this->assertSame($this->orderDetailWhitelist(), array_keys($response->json('data.order')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_admin_order_detail_only_exposes_service_snapshot_for_new_purchase_orders(): void
    {
        $fixture = $this->createOrderFixture();
        $order = $fixture['order'];

        // 续费订单即使残留快照数据，也不应在管理端暴露实例快照。
        $order->forceFill([
            'type' => 'renew',
            'service_snapshot' => ['instance_id' => 999, 'hostname' => 'renew.example.test'],
        ])->save();

        Sanctum::actingAs($this->createAdmin([AdminPermissions::ORDER_DETAIL]));

        $this->getJson('/api/v2/admin/orders/'.$order->id)
            ->assertOk()
            ->assertJsonPath('data.order.configuration.service_snapshot', null);
    }

    /**
     * @return array{user: User, product: Product, order: Order, invoice: Invoice, payment: Payment, service: Service}
     */
    private function createOrderFixture(): array
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $user = User::query()->create([
            'email' => 'v2-order-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'V2 Order '.$suffix,
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

        $product = Product::query()->create([
            'custom_display_name' => 'V2 Order Product '.$suffix,
            'product_type' => 'vps',
            'service_type_code' => 'vps',
            'pricing' => ['monthly' => '88.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 1,
            'auto_setup' => 0,
        ]);

        $order = Order::query()->create([
            'order_no' => 'V2ORD'.$suffix,
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => 'V2 Order Product '.$suffix,
            'product_type_snapshot' => 'vps',
            'type' => 'new',
            'amount' => '88.00',
            'discount' => '8.00',
            'paid_amount' => '80.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'config_snapshot' => [
                'cpu' => '2',
                'memory' => '4G',
                'password' => 'must-not-leak',
                'api_key' => 'must-not-leak',
            ],
            'config_pricing_snapshot' => [
                'items' => [
                    ['field' => 'cpu', 'label' => 'CPU', 'value' => '2', 'amount' => '20.00'],
                ],
                'total_amount' => '88.00',
                'raw_response' => ['must' => 'not leak'],
            ],
            'coupon_snapshot' => [
                'code' => 'COUPON'.$suffix,
                'secret' => 'must-not-leak',
            ],
            'service_snapshot' => [
                'instance_id' => 1,
                'hostname' => 'v2-order-'.$suffix.'.example.test',
            ],
            'status' => OrderStatus::PAID,
            'paid_at' => now(),
            'trace_id' => 'trace-order-'.$suffix,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'V2INV'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => 'V2 Order Product '.$suffix,
            'product_type_snapshot' => 'vps',
            'type' => 'new',
            'amount' => '88.00',
            'discount' => '8.00',
            'paid_amount' => '80.00',
            'status' => InvoiceStatus::PAID,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'due_date' => now()->addDay(),
            'paid_at' => now(),
            'trace_id' => 'trace-invoice-'.$suffix,
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'name' => 'v2-order-service-'.$suffix,
            'domain' => 'v2-order-'.$suffix.'.example.test',
            'billing_cycle' => 'monthly',
            'amount' => '88.00',
            'status' => 1,
            'expires_at' => now()->addMonth(),
        ]);

        $order->forceFill(['service_id' => (int) $service->id])->save();

        $payment = Payment::query()->create([
            'payment_no' => 'V2PAY'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => PaymentGatewayCode::ALIPAY,
            'gateway_key' => PaymentGatewayCode::ALIPAY,
            'trade_no' => 'V2TRADE'.$suffix,
            'amount' => '80.00',
            'status' => PaymentStatus::SUCCESS,
            'callback_raw' => [
                'raw_response' => 'must-not-leak',
                'api_key' => 'must-not-leak',
            ],
            'paid_at' => now(),
            'trace_id' => 'trace-payment-'.$suffix,
        ]);

        return [
            'user' => $user,
            'product' => $product,
            'order' => $order->refresh(),
            'invoice' => $invoice,
            'payment' => $payment,
            'service' => $service,
        ];
    }

    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-orders-'.$suffix,
            'label' => 'V2 Orders',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-orders-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Orders',
            'email' => 'v2-orders-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    /**
     * @return list<string>
     */
    private function orderListWhitelist(): array
    {
        return [
            'id',
            'order_no',
            'user_id',
            'user',
            'invoice_id',
            'invoice',
            'product_id',
            'product_name',
            'product_full_path',
            'product_type',
            'service',
            'type',
            'type_label',
            'status',
            'status_label',
            'amount',
            'discount',
            'paid_amount',
            'billing_cycle',
            'quantity',
            'paid_at',
            'created_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function orderDetailWhitelist(): array
    {
        return [
            'id',
            'basic',
            'financial',
            'user',
            'invoice',
            'product',
            'service',
            'coupon',
            'configuration',
            'payment_chain',
            'audit',
            'timestamps',
        ];
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response', 'token'] as $needle) {
                    $this->assertStringNotContainsString($needle, strtolower($key));
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
