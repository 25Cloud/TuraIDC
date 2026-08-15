<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
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

class V2InvoiceApiTest extends TestCase
{
    public function test_admin_invoice_list_requires_permission_rejects_per_page_and_returns_summary(): void
    {
        $fixture = $this->createInvoiceFixture();

        $this->getJson('/api/v2/admin/invoices')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([]));

        $this->getJson('/api/v2/admin/invoices')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::INVOICE_LIST]));

        $this->getJson('/api/v2/admin/invoices?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/admin/invoices?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $response = $this->getJson('/api/v2/admin/invoices?'.http_build_query([
            'keyword' => $fixture['invoice']->invoice_no,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', $fixture['invoice']->id)
            ->assertJsonMissingPath('data.list.0.scene')
            ->assertJsonMissingPath('data.list.0.payments')
            ->assertJsonMissingPath('data.list.0.config_snapshot');

        $this->assertSame($this->adminInvoiceListWhitelist(), array_keys($response->json('data.list.0')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_admin_invoice_detail_is_modular_and_safely_projected(): void
    {
        $fixture = $this->createInvoiceFixture();

        Sanctum::actingAs($this->createAdmin([AdminPermissions::INVOICE_LIST]));

        $this->getJson('/api/v2/admin/invoices/'.$fixture['invoice']->id)
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::INVOICE_DETAIL]));
        $paymentCount = Payment::query()->count();

        $this->getJson('/api/v2/admin/invoices/'.$fixture['invoice']->id.'?page=1')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['page']]]);

        $this->getJson('/api/v2/admin/invoices/'.$fixture['invoice']->id.'?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $response = $this->getJson('/api/v2/admin/invoices/'.$fixture['invoice']->id)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.invoice.id', $fixture['invoice']->id)
            ->assertJsonPath('data.invoice.basic.invoice_no', (string) $fixture['invoice']->invoice_no)
            ->assertJsonPath('data.invoice.financial.amount', '88.00')
            ->assertJsonPath('data.invoice.configuration.config_snapshot.cpu', '2')
            ->assertJsonPath('data.invoice.payment_chain.payments.0.payment_no', (string) $fixture['payment']->payment_no)
            ->assertJsonMissingPath('data.invoice.configuration.config_snapshot.password')
            ->assertJsonMissingPath('data.invoice.configuration.config_pricing_snapshot.raw_response')
            ->assertJsonMissingPath('data.invoice.payment_chain.payments.0.callback_raw');

        $this->assertSame($paymentCount, Payment::query()->count());
        $this->assertSame($this->adminInvoiceDetailWhitelist(), array_keys($response->json('data.invoice')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_client_invoice_list_and_detail_are_owner_scoped_and_use_client_resource(): void
    {
        $fixture = $this->createInvoiceFixture();
        $otherFixture = $this->createInvoiceFixture('other');
        $rechargeInvoice = Invoice::query()->create([
            'invoice_no' => 'V2INVRECH'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => (int) $fixture['user']->id,
            'type' => 'recharge',
            'amount' => '66.00',
            'paid_amount' => '66.00',
            'status' => InvoiceStatus::PAID,
            'paid_at' => now(),
            'due_date' => null,
            'trace_id' => 'trace-recharge-invoice',
        ]);
        $rechargePayment = Payment::query()->create([
            'payment_no' => 'V2INVRECPAY'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => (int) $fixture['user']->id,
            'invoice_id' => (int) $rechargeInvoice->id,
            'gateway' => PaymentGatewayCode::ALIPAY,
            'gateway_key' => PaymentGatewayCode::ALIPAY,
            'trade_no' => 'V2INVRECTRADE'.strtoupper(bin2hex(random_bytes(4))),
            'amount' => '66.00',
            'status' => PaymentStatus::SUCCESS,
            'paid_at' => now(),
            'callback_raw' => [
                'raw_response' => 'must-not-leak',
                'api_key' => 'must-not-leak',
            ],
            'trace_id' => 'trace-recharge-payment',
        ]);

        $this->getJson('/api/v2/client/invoices')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($fixture['user']);

        $this->getJson('/api/v2/client/invoices?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/client/invoices?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $listResponse = $this->getJson('/api/v2/client/invoices?'.http_build_query([
            'keyword' => $fixture['invoice']->invoice_no,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', $fixture['invoice']->id)
            ->assertJsonPath('data.list.0.order.order_no', (string) $fixture['order']->order_no)
            ->assertJsonMissingPath('data.list.0.user')
            ->assertJsonMissingPath('data.list.0.scene')
            ->assertJsonMissingPath('data.list.0.payments');

        $this->assertSame($this->clientInvoiceListWhitelist(), array_keys($listResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($listResponse->json());

        $this->getJson('/api/v2/client/invoices?'.http_build_query([
            'keyword' => $fixture['order']->order_no,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', $fixture['invoice']->id)
            ->assertJsonPath('data.list.0.order.order_no', (string) $fixture['order']->order_no);

        $rechargeListResponse = $this->getJson('/api/v2/client/invoices?'.http_build_query([
            'keyword' => $rechargePayment->payment_no,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', $rechargeInvoice->id)
            ->assertJsonPath('data.list.0.type', 'recharge')
            ->assertJsonPath('data.list.0.payment_summary.payment_no', (string) $rechargePayment->payment_no)
            ->assertJsonMissingPath('data.list.0.payment_summary.trade_no')
            ->assertJsonMissingPath('data.list.0.payment_summary.raw_response');

        $this->assertSame($this->clientInvoiceListWhitelist(), array_keys($rechargeListResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($rechargeListResponse->json());

        $this->getJson('/api/v2/client/invoices/'.$otherFixture['invoice']->id)
            ->assertNotFound()
            ->assertJsonPath('code', 40400);

        $this->getJson('/api/v2/client/invoices/'.$fixture['invoice']->id.'?page_size=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['page_size']]]);

        $this->getJson('/api/v2/client/invoices/'.$fixture['invoice']->id.'?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $detailResponse = $this->getJson('/api/v2/client/invoices/'.$fixture['invoice']->id)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.invoice.id', $fixture['invoice']->id)
            ->assertJsonPath('data.invoice.payment_options.can_cancel', true)
            ->assertJsonPath('data.invoice.payment_options.payment_security.can_pay', true)
            ->assertJsonMissingPath('data.invoice.user')
            ->assertJsonMissingPath('data.invoice.audit')
            ->assertJsonMissingPath('data.invoice.configuration.config_snapshot.password')
            ->assertJsonMissingPath('data.invoice.payment_chain.payments.0.callback_raw');

        $this->assertSame($this->clientInvoiceDetailWhitelist(), array_keys($detailResponse->json('data.invoice')));
        $this->assertNoSensitiveKeys($detailResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $detailResponse->getContent()));
    }

    /**
     * @return array{user: User, product: Product, order: Order, invoice: Invoice, payment: Payment, service: Service}
     */
    private function createInvoiceFixture(string $prefix = 'owner'): array
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $user = User::query()->create([
            'email' => 'v2-invoice-'.$prefix.'-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'V2 Invoice '.$suffix,
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
            'custom_display_name' => 'V2 Invoice Product '.$suffix,
            'product_type' => 'vps',
            'service_type_code' => 'vps',
            'pricing' => ['monthly' => '88.00'],
            'setup_fee' => '0.00',
            'config_options' => [
                ['field' => 'cpu', 'name' => 'CPU', 'api_key' => 'must-not-leak'],
            ],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 1,
            'auto_setup' => 0,
        ]);

        $order = Order::query()->create([
            'order_no' => 'V2INVORD'.$suffix,
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => 'V2 Invoice Product '.$suffix,
            'product_type_snapshot' => 'vps',
            'type' => 'new',
            'amount' => '88.00',
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'status' => 0,
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'V2INV'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => 'V2 Invoice Product '.$suffix,
            'product_type_snapshot' => 'vps',
            'type' => 'new',
            'amount' => '88.00',
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::UNPAID,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'due_date' => now()->addDay(),
            'config_snapshot' => [
                'cpu' => '2',
                'password' => 'must-not-leak',
            ],
            'config_pricing_snapshot' => [
                'items' => [
                    ['field' => 'cpu', 'label' => 'CPU', 'value' => '2', 'amount' => '20.00'],
                ],
                'raw_response' => ['must' => 'not leak'],
            ],
            'coupon_snapshot' => [
                'name' => '测试券',
                'secret' => 'must-not-leak',
            ],
            'trace_id' => 'trace-invoice-'.$suffix,
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'name' => 'v2-invoice-service-'.$suffix,
            'domain' => 'v2-invoice-'.$suffix.'.example.test',
            'billing_cycle' => 'monthly',
            'amount' => '88.00',
            'status' => 1,
            'expires_at' => now()->addMonth(),
        ]);

        $order->forceFill(['service_id' => (int) $service->id])->save();
        $invoice->forceFill(['service_id' => (int) $service->id])->save();

        $payment = Payment::query()->create([
            'payment_no' => 'V2INVPAY'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => PaymentGatewayCode::ALIPAY,
            'gateway_key' => PaymentGatewayCode::ALIPAY,
            'trade_no' => 'V2INVTRADE'.$suffix,
            'amount' => '88.00',
            'status' => PaymentStatus::PENDING,
            'callback_raw' => [
                'raw_response' => 'must-not-leak',
                'api_key' => 'must-not-leak',
            ],
            'trace_id' => 'trace-payment-'.$suffix,
        ]);

        return [
            'user' => $user,
            'product' => $product,
            'order' => $order->refresh(),
            'invoice' => $invoice->refresh(),
            'payment' => $payment,
            'service' => $service,
        ];
    }

    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-invoices-'.$suffix,
            'label' => 'V2 Invoices',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-invoices-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Invoices',
            'email' => 'v2-invoices-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    /**
     * @return list<string>
     */
    private function adminInvoiceListWhitelist(): array
    {
        return [
            'id',
            'invoice_no',
            'user_id',
            'user',
            'order_id',
            'order',
            'product_id',
            'product',
            'product_spec_display',
            'product_display_name',
            'combined_display_name',
            'product_full_path',
            'type',
            'type_label',
            'amount',
            'discount',
            'paid_amount',
            'payable_amount',
            'status',
            'status_label',
            'billing_cycle',
            'quantity',
            'summary',
            'due_date',
            'paid_at',
            'created_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function adminInvoiceDetailWhitelist(): array
    {
        return [
            'id',
            'basic',
            'display',
            'financial',
            'user',
            'order',
            'product',
            'service',
            'scene',
            'configuration',
            'payment_chain',
            'items',
            'logs',
            'audit',
            'actions',
            'timestamps',
        ];
    }

    /**
     * @return list<string>
     */
    private function clientInvoiceListWhitelist(): array
    {
        return [
            'id',
            'invoice_no',
            'order_id',
            'order',
            'product_id',
            'product',
            'product_spec_display',
            'product_display_name',
            'combined_display_name',
            'product_full_path',
            'type',
            'type_label',
            'amount',
            'discount',
            'paid_amount',
            'payable_amount',
            'status',
            'status_label',
            'summary',
            'payment_summary',
            'due_date',
            'created_at',
            'paid_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function clientInvoiceDetailWhitelist(): array
    {
        return [
            'id',
            'basic',
            'display',
            'financial',
            'order',
            'product',
            'service',
            'scene',
            'configuration',
            'payment_chain',
            'payment_options',
            'items',
            'logs',
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
                foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response'] as $needle) {
                    $this->assertStringNotContainsString($needle, strtolower($key));
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
