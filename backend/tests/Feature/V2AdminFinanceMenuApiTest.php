<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\OrderType;
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
use App\Services\Finance\AdminFinanceQueryService;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2AdminFinanceMenuApiTest extends TestCase
{
    public function test_finance_menu_recharges_use_v2_projection(): void
    {
        $fixture = $this->createFinanceFixture();

        $this->getJson('/api/v2/admin/finance/recharges')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([]));

        $this->getJson('/api/v2/admin/finance/recharges')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::INVOICE_LIST]));

        $this->getJson('/api/v2/admin/finance/recharges?per_page=20&pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page', 'pageSize']]]);

        $response = $this->getJson('/api/v2/admin/finance/recharges?'.http_build_query([
            'keyword' => $fixture['payment']->payment_no,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 10)
            ->assertJsonPath('data.list.0.id', $fixture['payment']->id)
            ->assertJsonMissingPath('data.list.0.payment.trace_id')
            ->assertJsonMissingPath('data.list.0.callback_raw');

        $this->assertSame(['list', 'total', 'page', 'page_size'], array_keys($response->json('data')));
        $this->assertSame($this->rechargeWhitelist(), array_keys($response->json('data.list.0')));
        $this->assertSame($this->rechargePaymentWhitelist(), array_keys($response->json('data.list.0.payment')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_finance_menu_renewal_and_upgrade_orders_use_v2_projection(): void
    {
        $fixture = $this->createFinanceFixture();

        Sanctum::actingAs($this->createAdmin([AdminPermissions::INVOICE_LIST]));

        $this->getJson('/api/v2/admin/finance/renewal-orders?per_page=20&pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page', 'pageSize']]]);

        $renewalResponse = $this->getJson('/api/v2/admin/finance/renewal-orders?'.http_build_query([
            'keyword' => $fixture['renewal']->order_no,
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', $fixture['renewal']->id)
            ->assertJsonPath('data.list.0.type', OrderType::RENEW)
            ->assertJsonMissingPath('data.list.0.config_snapshot')
            ->assertJsonMissingPath('data.list.0.config_pricing_snapshot')
            ->assertJsonMissingPath('data.list.0.trace_id');

        $this->assertSame($this->financeOrderWhitelist(), array_keys($renewalResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($renewalResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $renewalResponse->getContent()));

        $upgradeResponse = $this->getJson('/api/v2/admin/finance/upgrade-orders?'.http_build_query([
            'keyword' => $fixture['upgrade']->order_no,
            'upgrade_kind' => 'traffic_package',
            'page' => 1,
            'page_size' => 10,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', $fixture['upgrade']->id)
            ->assertJsonPath('data.list.0.type', OrderType::UPGRADE)
            ->assertJsonPath('data.list.0.upgrade_kind', 'traffic_package')
            ->assertJsonMissingPath('data.list.0.config_snapshot')
            ->assertJsonMissingPath('data.list.0.config_pricing_snapshot')
            ->assertJsonMissingPath('data.list.0.trace_id');

        $this->assertSame($this->financeUpgradeOrderWhitelist(), array_keys($upgradeResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($upgradeResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $upgradeResponse->getContent()));
    }

    public function test_new_customer_daily_summary_uses_finance_report_permission_and_v2_contract(): void
    {
        $fixture = $this->createFinanceFixture();
        $baseline = app(AdminFinanceQueryService::class)
            ->dailyCustomerSummary('2037-06-17', '2037-06-17')['summary'];
        $fixture['new_user']->forceFill([
            'created_at' => '2037-06-17 09:00:00',
            'updated_at' => '2037-06-17 09:00:00',
        ])->save();

        Sanctum::actingAs($this->createAdmin([AdminPermissions::INVOICE_LIST]));

        $this->getJson('/api/v2/admin/finance/new-customer-daily-summary?start_date=2037-06-17&end_date=2037-06-17')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::FINANCE_REPORT]));

        $this->getJson('/api/v2/admin/finance/new-customer-daily-summary?per_page=20&pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page', 'pageSize']]]);

        $response = $this->getJson('/api/v2/admin/finance/new-customer-daily-summary?'.http_build_query([
            'start_date' => '2037-06-17',
            'end_date' => '2037-06-17',
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.start_date', '2037-06-17')
            ->assertJsonPath('data.end_date', '2037-06-17')
            ->assertJsonPath('data.summary.new_customers', (int) ($baseline['new_customers'] ?? 0) + 1)
            ->assertJsonPath('data.list.0.date', '2037-06-17');

        $this->assertSame($this->dailySummaryWhitelist(), array_keys($response->json('data')));
        $this->assertSame($this->dailySummaryTotalsWhitelist(), array_keys($response->json('data.summary')));
        $this->assertSame($this->dailySummaryItemWhitelist(), array_keys($response->json('data.list.0')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    /**
     * @return array{user: User, new_user: User, product: Product, service: Service, invoice: Invoice, payment: Payment, renewal: Order, upgrade: Order}
     */
    private function createFinanceFixture(): array
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $user = $this->createUser('finance-menu-'.$suffix);
        $newUser = $this->createUser('finance-menu-new-'.$suffix);
        $product = Product::query()->create([
            'custom_display_name' => 'V2 Finance Product '.$suffix,
            'product_type' => 'vps',
            'service_type_code' => 'vps',
            'pricing' => ['monthly' => '100.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => ['secret' => 'must-not-leak'],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 1,
            'auto_setup' => 0,
        ]);

        $renewal = $this->createOrder($user, $product, $suffix.'REN', [
            'type' => OrderType::RENEW,
            'status' => OrderStatus::PAID,
            'amount' => '30.00',
            'paid_amount' => '30.00',
            'trace_id' => 'renewal-trace-'.$suffix,
        ]);
        $upgrade = $this->createOrder($user, $product, $suffix.'UPG', [
            'type' => OrderType::UPGRADE,
            'status' => OrderStatus::PAID,
            'amount' => '12.00',
            'paid_amount' => '12.00',
            'trace_id' => 'upgrade-trace-'.$suffix,
            'config_pricing_snapshot' => [
                'meta' => [
                    'kind' => 'traffic_package',
                    'mode' => 'append',
                    'target_label' => 'Traffic 100G',
                ],
                'raw_response' => ['secret' => 'must-not-leak'],
            ],
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'V2FININV'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => (int) $renewal->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => 'V2 Finance Product '.$suffix,
            'product_type_snapshot' => 'vps',
            'type' => OrderType::RENEW,
            'amount' => '30.00',
            'discount' => '0.00',
            'paid_amount' => '30.00',
            'status' => InvoiceStatus::PAID,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'due_date' => now()->addDay(),
            'paid_at' => now(),
            'trace_id' => 'invoice-trace-'.$suffix,
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'order_id' => (int) $renewal->id,
            'invoice_id' => (int) $invoice->id,
            'name' => 'v2-finance-service-'.$suffix,
            'domain' => 'v2-finance-'.$suffix.'.example.test',
            'billing_cycle' => 'monthly',
            'amount' => '30.00',
            'status' => 1,
            'expires_at' => now()->addMonth(),
        ]);
        $renewal->forceFill(['service_id' => (int) $service->id])->save();
        $upgrade->forceFill(['service_id' => (int) $service->id])->save();

        $payment = Payment::query()->create([
            'payment_no' => 'V2FINPAY'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => (int) $renewal->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => PaymentGatewayCode::ALIPAY,
            'gateway_key' => PaymentGatewayCode::ALIPAY,
            'trade_no' => 'V2FINTRADE'.$suffix,
            'amount' => '30.00',
            'status' => PaymentStatus::SUCCESS,
            'callback_raw' => [
                'raw_response' => 'must-not-leak',
                'api_key' => 'must-not-leak',
            ],
            'paid_at' => now(),
            'trace_id' => 'payment-trace-'.$suffix,
        ]);

        return [
            'user' => $user,
            'new_user' => $newUser,
            'product' => $product,
            'service' => $service,
            'invoice' => $invoice,
            'payment' => $payment,
            'renewal' => $renewal->refresh(),
            'upgrade' => $upgrade->refresh(),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createOrder(User $user, Product $product, string $suffix, array $overrides = []): Order
    {
        return Order::query()->create(array_replace([
            'order_no' => 'V2FINORD'.$suffix,
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => 'V2 Finance Product '.$suffix,
            'product_type_snapshot' => 'vps',
            'type' => OrderType::NEW,
            'amount' => '100.00',
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'config_snapshot' => [
                'password' => 'must-not-leak',
                'api_key' => 'must-not-leak',
            ],
            'config_pricing_snapshot' => [
                'raw_response' => 'must-not-leak',
            ],
            'coupon_snapshot' => [
                'secret' => 'must-not-leak',
            ],
            'status' => OrderStatus::PENDING,
            'paid_at' => now(),
        ], $overrides));
    }

    private function createUser(string $suffix): User
    {
        return User::query()->create([
            'email' => 'v2-finance-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'V2 Finance '.$suffix,
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

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-finance-menu-'.$suffix,
            'label' => 'V2 Finance Menu',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-finance-menu-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Finance Menu',
            'email' => 'v2-finance-menu-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    /**
     * @return list<string>
     */
    private function rechargeWhitelist(): array
    {
        return [
            'id',
            'payment_no',
            'gateway',
            'gateway_key',
            'gateway_label',
            'trade_no',
            'user',
            'invoice_id',
            'invoice_no',
            'invoice',
            'order',
            'amount',
            'paid_amount',
            'status',
            'status_label',
            'payment',
            'paid_at',
            'created_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function rechargePaymentWhitelist(): array
    {
        return [
            'id',
            'payment_no',
            'gateway',
            'gateway_key',
            'gateway_label',
            'trade_no',
            'amount',
            'status',
            'paid_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function financeOrderWhitelist(): array
    {
        return [
            'id',
            'order_no',
            'user_id',
            'user',
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
            'updated_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function financeUpgradeOrderWhitelist(): array
    {
        return array_merge($this->financeOrderWhitelist(), [
            'upgrade_kind',
            'upgrade_kind_label',
            'upgrade_target_label',
            'upgrade_mode',
        ]);
    }

    /**
     * @return list<string>
     */
    private function dailySummaryWhitelist(): array
    {
        return [
            'month',
            'start_date',
            'end_date',
            'summary',
            'list',
        ];
    }

    /**
     * @return list<string>
     */
    private function dailySummaryTotalsWhitelist(): array
    {
        return [
            'new_customers',
            'new_orders',
            'completed_orders',
            'new_tickets',
            'ticket_replies',
            'cancel_requests',
        ];
    }

    /**
     * @return list<string>
     */
    private function dailySummaryItemWhitelist(): array
    {
        return [
            'date',
            'new_customers',
            'new_orders',
            'completed_orders',
            'new_tickets',
            'ticket_replies',
            'cancel_requests',
        ];
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response', 'callback_raw'] as $needle) {
                    $this->assertStringNotContainsString($needle, strtolower($key));
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
