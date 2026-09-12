<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\ServiceStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Automation\ServiceStatusSyncService;
use App\Services\Finance\CheckoutSecurityService;
use App\Services\Finance\CheckoutService;
use App\Services\Finance\PaymentService;
use App\Services\Provisioning\ServiceRenewService;
use App\Services\Upstream\Contracts\ProvidesSupplierBalance;
use App\Services\Upstream\ProviderResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use TuraIDC\Plugins\Servers\TuraOpenApi\Logic\TuraOpenApi;

/**
 * tura_open_api 上游驱动回归：开放 API 驱动打通「纯自有协议」的 TuraIDC↔TuraIDC
 * 无限级转售链。上游 HTTP 全部用 Http::fake 模拟，验证协议映射与编排闭环。
 */
class TuraOpenApiDriverTest extends TestCase
{
    use DatabaseTransactions;

    private const UPSTREAM = 'https://upstream-open.example.test';

    private const UPSTREAM_PRODUCT_ID = 51001;

    private const UPSTREAM_INVOICE_ID = 91001;

    private const UPSTREAM_SERVICE_ID = 52001;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('queue.default', 'sync');
        $this->activateIntegrationPluginForTest('upstream', 'tura_open_api');
    }

    public function test_purchase_by_balance_provisions_through_upstream_open_api(): void
    {
        $this->fakeUpstreamForProvision();

        $stack = $this->makeServiceStack();
        $user = $stack['user'];
        $user->forceFill(['balance' => '200.00'])->save();

        $checkout = app(CheckoutService::class);
        // hostname 会被本地归一化（点转横杠），报价凭证必须与归一化后的配置一致
        $quote = $checkout->quote($stack['product'], 'monthly', ['hostname' => 'resold-example-test'], 1);
        $tokenData = app(CheckoutSecurityService::class)->issueQuoteToken(
            $stack['product']->id, 'monthly', ['hostname' => 'resold-example-test'],
            array_merge($quote, ['subtotal_amount' => $quote['total_amount']]),
        );

        $invoice = $checkout->create((int) $user->id, [
            'product_id' => (int) $stack['product']->id,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'config' => ['hostname' => 'resold-example-test'],
            'quote_token' => (string) $tokenData['quote_token'],
        ], [
            'idempotency_key' => 'tura-open-driver-'.bin2hex(random_bytes(4)),
            'trace_id' => 'trace-tura-open-driver',
        ]);

        app(PaymentService::class)->payByBalance($invoice, $user, ['trace_id' => 'trace-tura-open-driver-pay']);

        // DatabaseTransactions 下 afterCommit 队列不触发，手动执行履约（与队列 Job 同一路径）
        app(PaymentService::class)->processPaidOrderFulfillmentById((int) $invoice->fresh()->order_id);

        $order = Order::query()->findOrFail((int) $invoice->fresh()->order_id);
        $service = Service::query()->where('order_id', (int) $order->id)->firstOrFail();
        $provisionData = (array) $service->provision_data;

        $this->assertSame(OrderStatus::COMPLETED, (int) $order->status);
        $this->assertSame(ServiceStatus::ACTIVE, (int) $service->status);
        $this->assertSame(self::UPSTREAM_INVOICE_ID, (int) ($provisionData['upstream_invoice_id'] ?? 0));
        $this->assertSame(self::UPSTREAM_SERVICE_ID, (int) ($provisionData['upstream_host_id'] ?? 0));
        $this->assertSame('Active', (string) ($provisionData['upstream_status'] ?? ''));
        $this->assertDatabaseHas('service_upstream_bindings', [
            'service_id' => (int) $service->id,
            'provider_key' => 'tura_open_api',
            'upstream_service_id' => (string) self::UPSTREAM_SERVICE_ID,
        ]);

        // 幂等键跨重试必须稳定：同一订单多次开通尝试使用同一上游幂等键
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v2/open/orders')
            && $request->method() === 'POST'
            && str_starts_with((string) data_get($request->data(), 'idempotency_key', ''), 'tura-open-provision-'));
    }

    public function test_renew_pays_upstream_and_extends_expiry_from_upstream_due_date(): void
    {
        $this->fakeUpstreamForRenew();

        $stack = $this->makeServiceStack();
        $user = $stack['user'];
        $user->forceFill(['balance' => '200.00'])->save();
        $service = $stack['service'];

        $invoice = app(ServiceRenewService::class)->createRenewInvoiceForUser(
            $user, (int) $service->id, 'monthly', 0, ['trace_id' => 'trace-tura-open-renew']
        );
        app(PaymentService::class)->payByBalance($invoice, $user, ['trace_id' => 'trace-tura-open-renew-pay']);

        // DatabaseTransactions 下 afterCommit 队列不触发，手动执行履约（与队列 Job 同一路径）
        app(PaymentService::class)->processPaidOrderFulfillmentById((int) $invoice->fresh()->order_id);

        $this->assertSame(InvoiceStatus::PAID, (int) $invoice->fresh()->status);
        // 到期时间取自上游 nextduedate，而非本地周期推导
        $this->assertSame('2027-03-01 00:00:00', $service->fresh()->expires_at?->format('Y-m-d H:i:s'));

        Http::assertSent(fn ($request): bool => str_contains($request->url(), '/api/v2/open/services/52001/renew'));
    }

    public function test_balance_maps_upstream_response(): void
    {
        $this->fakeUpstream(fn (string $url): array => match (true) {
            str_contains($url, '/api/v2/open/balance') => [
                'code' => 0, 'message' => 'ok', 'data' => ['balance' => '188.50'],
            ],
            default => ['code' => 50000, 'message' => '测试未模拟的上游端点: '.$url],
        });

        $stack = $this->makeServiceStack();
        $provider = app(ProviderResolver::class)->resolveForSupplier($stack['supplier']);
        $balance = $provider->require(ProvidesSupplierBalance::class, 'x')
            ->getBalance($this->withRuntimeCredentials($stack['supplier']));

        $this->assertSame('188.50', (string) $balance['balance']);
    }

    /**
     * 闭包式 fake：按 URL 内容分发响应，避免通配符模式匹配的歧义。
     *
     * @param  \Closure(string): array<string, mixed>  $handler
     */
    private function fakeUpstream(\Closure $handler): void
    {
        Http::fake(function ($request) use ($handler) {
            $payload = $handler((string) $request->url());

            return Http::response($payload);
        });
    }

    public function test_status_sync_pulls_upstream_service_list(): void
    {
        $this->fakeUpstream(fn (string $url): array => str_contains($url, '/api/v2/open/services?page=')
            ? [
                'code' => 0,
                'message' => 'ok',
                'data' => [
                    'list' => [[
                        'id' => self::UPSTREAM_SERVICE_ID,
                        'name' => 'upstream-service-name',
                        'product_name' => 'Upstream Product',
                        'status' => ServiceStatus::SUSPENDED,
                        'expires_at' => '2027-06-01 00:00:00',
                    ]],
                    'total' => 1,
                ],
            ]
            : ['code' => 50000, 'message' => '测试未模拟的上游端点: '.$url]);

        $stack = $this->makeServiceStack();
        $service = $stack['service'];

        app(ServiceStatusSyncService::class)->syncServices(new EloquentCollection([$service->fresh()]));

        $provisionData = (array) $service->fresh()->provision_data;
        $this->assertSame('Suspended', (string) ($provisionData['upstream_status'] ?? ''));
        $this->assertNotEmpty((string) ($provisionData['last_status_sync_at'] ?? ''));
    }

    public function test_empty_api_key_is_rejected_before_any_request(): void
    {
        $stack = $this->makeServiceStack();
        // 覆写绑定密文：去掉 api_key，模拟密钥未配置的供应商绑定
        DB::table('supplier_plugin_bindings')
            ->where('supplier_id', (int) $stack['supplier']->id)
            ->update(['secret_json' => Crypt::encryptString(json_encode([]))]);

        $driver = new TuraOpenApi;

        $this->expectException(\App\Exceptions\BusinessException::class);
        $this->expectExceptionMessage('API 密钥未配置');

        $driver->getBalance($this->withRuntimeCredentials($stack['supplier']));
    }

    public function test_catalog_lists_products_and_hydrates_selected_pricing(): void
    {
        $this->fakeUpstream(function (string $url): array {
            if (str_contains($url, '/api/v2/open/products?page=')) {
                return [
                    'code' => 0, 'message' => 'ok',
                    'data' => [
                        'list' => [[
                            'id' => self::UPSTREAM_PRODUCT_ID,
                            'name' => 'Upstream VPS',
                            'product_type' => 'server',
                            'stock' => 7,
                        ]],
                        'total' => 1,
                    ],
                ];
            }

            if (str_contains($url, '/api/v2/open/products/51001/quotes')) {
                return str_contains($url, 'billing_cycle=monthly')
                    ? ['code' => 0, 'message' => 'ok', 'data' => ['total_amount' => '49.00', 'quote_token' => 'tok-monthly']]
                    : ['code' => 42200, 'message' => '暂不支持该周期'];
            }

            return ['code' => 50000, 'message' => '测试未模拟的上游端点: '.$url];
        });

        $stack = $this->makeServiceStack();
        $driver = new TuraOpenApi;

        $catalog = $driver->getProductCatalog($this->withRuntimeCredentials($stack['supplier']));
        $this->assertCount(1, $catalog['products']);
        $this->assertSame(self::UPSTREAM_PRODUCT_ID, (int) ($catalog['products'][0]['id'] ?? 0));
        $this->assertSame(1, (int) ($catalog['products'][0]['stock_control'] ?? 0));

        $hydrated = $driver->hydrateSelectedPricing(
            $this->withRuntimeCredentials($stack['supplier']),
            $catalog['products'],
            [self::UPSTREAM_PRODUCT_ID],
        );
        $this->assertSame('49.00', (string) ($hydrated[0]['monthly_price'] ?? ''));
        $this->assertSame('monthly', (string) ($hydrated[0]['billingcycle'] ?? ''));
    }

    /**
     * 供应商凭据只存在于绑定表加密 secret_json，运行时由 PluginBindingResolver 注入；
     * 直接调用驱动（不经过编排层）时需要模拟这一步。
     */
    private function withRuntimeCredentials(Supplier $supplier): Supplier
    {
        return app(\App\Services\Integrations\Plugins\PluginBindingResolver::class)
            ->supplierWithRuntimeCredentials($supplier);
    }

    /* ------------------------------ 上游响应桩 ------------------------------ */

    private function fakeUpstreamForProvision(): void
    {
        $this->fakeUpstream(function (string $url): array {
            if (str_contains($url, '/api/v2/open/products/51001/quotes')) {
                return ['code' => 0, 'message' => 'ok', 'data' => ['total_amount' => '50.00', 'quote_token' => 'tok-provision']];
            }

            // 结账库存校验会拉商品列表（fetchBatchProductStocks）
            if (str_contains($url, '/api/v2/open/products?page=')) {
                return [
                    'code' => 0, 'message' => 'ok',
                    'data' => ['list' => [['id' => self::UPSTREAM_PRODUCT_ID, 'name' => 'Upstream VPS', 'stock' => -1]], 'total' => 1],
                ];
            }

            if (str_contains($url, '/api/v2/open/orders/91001/pay')) {
                return ['code' => 0, 'message' => 'ok', 'data' => ['id' => self::UPSTREAM_INVOICE_ID, 'status' => InvoiceStatus::PAID]];
            }

            if (preg_match('#/api/v2/open/orders/91001$#', $url) === 1) {
                return [
                    'code' => 0, 'message' => 'ok',
                    'data' => ['id' => self::UPSTREAM_INVOICE_ID, 'status' => InvoiceStatus::PAID, 'service_id' => self::UPSTREAM_SERVICE_ID],
                ];
            }

            if (str_contains($url, '/api/v2/open/orders')) {
                return ['code' => 0, 'message' => 'ok', 'data' => ['id' => self::UPSTREAM_INVOICE_ID, 'status' => InvoiceStatus::UNPAID]];
            }

            if (str_contains($url, '/api/v2/open/services/52001')) {
                return [
                    'code' => 0, 'message' => 'ok',
                    'data' => [
                        'id' => self::UPSTREAM_SERVICE_ID,
                        'status' => ServiceStatus::ACTIVE,
                        'domain' => 'upstream-host.example.test',
                        'expires_at' => '2027-02-01 00:00:00',
                        'display_name' => 'Upstream VPS',
                        'connection' => ['hostname' => '203.0.113.20', 'username' => 'root', 'port' => 22],
                    ],
                ];
            }

            return ['code' => 50000, 'message' => '测试未模拟的上游端点: '.$url];
        });
    }

    private function fakeUpstreamForRenew(): void
    {
        $this->fakeUpstream(function (string $url): array {
            if (str_contains($url, '/api/v2/open/services/52001/renew')) {
                return ['code' => 0, 'message' => 'ok', 'data' => ['invoice_id' => 91002, 'amount' => '99.00', 'paid' => true]];
            }

            if (str_contains($url, '/api/v2/open/services/52001')) {
                return [
                    'code' => 0, 'message' => 'ok',
                    'data' => [
                        'id' => self::UPSTREAM_SERVICE_ID,
                        'status' => ServiceStatus::ACTIVE,
                        'domain' => 'upstream-host.example.test',
                        'expires_at' => '2027-03-01 00:00:00',
                    ],
                ];
            }

            return ['code' => 50000, 'message' => '测试未模拟的上游端点: '.$url];
        });
    }

    /* -------------------------------- 服务栈 -------------------------------- */

    /**
     * 构建挂接 tura_open_api 上游的完整服务栈。
     *
     * @return array{user: User, supplier: Supplier, product: Product, service: Service}
     */
    private function makeServiceStack(): array
    {
        $unique = 'tura-open-'.bin2hex(random_bytes(4));
        $pluginId = (int) DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where('plugin_key', 'tura_open_api')
            ->value('id');
        $this->assertGreaterThan(0, $pluginId);

        $user = User::query()->create([
            'email' => $unique.'@example.test',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        // 凭据不在 suppliers 表（无可填充列），在绑定表加密 secret_json里，
        // 运行时由 PluginBindingResolver::supplierWithRuntimeCredentials 注入
        $supplier = Supplier::query()->create([
            'name' => 'Tura Open Supplier '.$unique,
            'code' => $unique,
            'status' => 1,
            'sort_order' => 1,
        ]);

        $product = Product::query()->create([
            'name' => 'Tura Open Product '.$unique,
            'product_type' => 'server',
            'pricing' => ['monthly' => '99.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 1,
            'supplier_id' => (int) $supplier->id,
            'supplier_product_id' => self::UPSTREAM_PRODUCT_ID,
            'provision_module' => 'tura_open_api',
        ]);

        $supplierBindingId = (int) DB::table('supplier_plugin_bindings')->insertGetId([
            'supplier_id' => (int) $supplier->id,
            'plugin_id' => $pluginId,
            'provider_key' => 'tura_open_api',
            'environment' => 'production',
            'status' => 1,
            'priority' => 0,
            'base_url' => self::UPSTREAM,
            'secret_json' => Crypt::encryptString(json_encode([
                'api_key' => 'tura_open_test_secret_0123456789abcdef',
            ])),
            'has_secret_json' => json_encode(['api_key' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productBindingId = (int) DB::table('product_upstream_bindings')->insertGetId([
            'product_id' => (int) $product->id,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'plugin_id' => $pluginId,
            'provider_key' => 'tura_open_api',
            'upstream_product_id' => (string) self::UPSTREAM_PRODUCT_ID,
            'auto_setup' => 1,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Tura Open Service '.$unique,
            'domain' => $unique.'.example.test',
            'billing_cycle' => 'monthly',
            'amount' => '99.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => [],
            'provision_data' => [
                'provider' => 'tura_open_api',
                'supplier_id' => (int) $supplier->id,
                'supplier_product_id' => self::UPSTREAM_PRODUCT_ID,
                'upstream_host_id' => self::UPSTREAM_SERVICE_ID,
            ],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);

        DB::table('service_upstream_bindings')->insert([
            'service_id' => (int) $service->id,
            'product_upstream_binding_id' => $productBindingId,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'plugin_id' => $pluginId,
            'provider_key' => 'tura_open_api',
            'upstream_service_id' => (string) self::UPSTREAM_SERVICE_ID,
            'status_snapshot' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['user' => $user, 'supplier' => $supplier, 'product' => $product, 'service' => $service];
    }
}
