<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\ServiceStatus;
use App\Models\ApiKey;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceTrafficPackageService;
use App\Services\ClientServiceConsole\ServiceUpgradeService;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\OpenApi\ApiKeyService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use TuraIDC\Plugins\Servers\TuraOpenApi\Logic\TuraOpenApi;

/**
 * 开放接口「配置项升级 + 流量包」五个端点，以及下游 tura_open_api 驱动的协议映射。
 *
 * 两组用例，覆盖两层契约：
 * 1. 端点层（本实例作上游）：鉴权域、参数校验、越权拒绝、账单投影；
 * 2. 驱动层（本实例作下游）：五个上游端点的 URL/请求体/响应映射与能力声明。
 *
 * 端点层正常流用 mock 替掉两个业务服务类：升级/流量包的真实执行依赖上游驱动
 * （demo_servers 不实现升级与流量包），mock 才能把「开放接口做了什么投影」单独锁住；
 * 越权用例走真实服务类，因为归属校验必须发生在任何上游调用之前。
 */
class OpenServiceUpgradeApiTest extends TestCase
{
    use DatabaseTransactions;

    private const UPSTREAM = 'https://upstream-open.example.test';

    private const UPSTREAM_SERVICE_ID = 52001;

    private const UPSTREAM_INVOICE_ID = 91001;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::setValues('open_api', ['enabled' => '1']);
        $this->activateIntegrationPluginForTest('upstream', 'tura_open_api');
        $this->activateIntegrationPluginForTest('upstream', 'demo_servers');
    }

    /* ------------------------------- 端点契约 ------------------------------- */

    public function test_upgrade_preview_requires_services_read_scope(): void
    {
        $user = $this->makeUser();
        // 只给 orders 域：services 域的读权限缺失，必须在进业务层之前被拒
        [, $plain] = $this->createKey($user, ['orders' => 'write']);

        $this->withToken($plain)
            ->getJson('/api/v2/open/services/1/upgrades')
            ->assertStatus(403)
            ->assertJsonPath('code', 40300);
    }

    public function test_upgrade_preview_returns_options_payload(): void
    {
        $user = $this->makeUser();
        [, $plain] = $this->createKey($user);

        $this->mock(ServiceUpgradeService::class, function ($mock): void {
            $mock->shouldReceive('previewForUser')->once()->andReturn([
                'service_id' => 1,
                'service_name' => '示例实例',
                'upstream_host_id' => self::UPSTREAM_SERVICE_ID,
                'options' => [
                    ['id' => 1, 'name' => '套餐', 'sub' => [['id' => 11, 'option_name' => '德国 Plus']]],
                ],
            ]);
        });

        $this->withToken($plain)
            ->getJson('/api/v2/open/services/1/upgrades')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.service_id', 1)
            ->assertJsonPath('data.options.0.sub.0.id', 11);
    }

    public function test_upgrade_quote_and_order_require_selection_fields(): void
    {
        $user = $this->makeUser();
        [, $plain] = $this->createKey($user);

        // 校验发生在服务调用之前：缺 product_id / billing_cycle 必须 422，
        // 不能打穿到业务层再抛上游错误（API 校验错误放在 data.errors 里）
        $this->withToken($plain)
            ->postJson('/api/v2/open/services/1/upgrades/quotes', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['product_id', 'billing_cycle']]]);

        $this->withToken($plain)
            ->postJson('/api/v2/open/services/1/upgrades/orders', ['product_id' => 0, 'billing_cycle' => ''])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['errors' => ['product_id', 'billing_cycle']]]);
    }

    public function test_upgrade_quote_returns_quote_payload(): void
    {
        $user = $this->makeUser();
        [, $plain] = $this->createKey($user);

        $this->mock(ServiceUpgradeService::class, function ($mock): void {
            $mock->shouldReceive('quoteForUser')
                ->once()
                ->withArgs(fn (User $u, int $serviceId, array $selection): bool => $serviceId === 7
                    && (int) ($selection['product_id'] ?? 0) === 3
                    && (string) ($selection['billing_cycle'] ?? '') === 'monthly')
                ->andReturn([
                    'service_id' => 7,
                    'product_id' => 3,
                    'billing_cycle' => 'monthly',
                    'quote' => ['amount_total' => '128.00', 'currency' => 'CNY'],
                ]);
        });

        $this->withToken($plain)
            ->postJson('/api/v2/open/services/7/upgrades/quotes', [
                'product_id' => 3,
                'billing_cycle' => 'monthly',
            ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.quote.amount_total', '128.00');
    }

    public function test_upgrade_order_returns_invoice_projection(): void
    {
        $user = $this->makeUser();
        $invoice = $this->makeInvoice($user, '112.50');
        [, $plain] = $this->createKey($user);

        $this->mock(ServiceUpgradeService::class, function ($mock) use ($invoice): void {
            $mock->shouldReceive('createInvoiceForUser')->once()->andReturn($invoice);
        });

        $this->withToken($plain)
            ->postJson('/api/v2/open/services/9/upgrades/orders', [
                'product_id' => 3,
                'billing_cycle' => 'monthly',
                'promo_code' => 'PROMO10',
            ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', (int) $invoice->id)
            ->assertJsonPath('data.invoice_no', (string) $invoice->invoice_no)
            ->assertJsonPath('data.amount', '112.50');
    }

    public function test_flow_package_preview_and_order(): void
    {
        $user = $this->makeUser();
        $invoice = $this->makeInvoice($user, '30.00');
        [, $plain] = $this->createKey($user);

        $this->mock(ServiceTrafficPackageService::class, function ($mock) use ($invoice): void {
            $mock->shouldReceive('previewForUser')->once()->andReturn([
                'supported' => true,
                'message' => '',
                'service_id' => 5,
                'service_name' => '示例实例',
                'traffic' => ['used' => '10.00', 'limit' => '100.00'],
                'packages' => [
                    ['target_value' => 200, 'target_label' => '200GB', 'price' => '30.00'],
                ],
            ]);
            $mock->shouldReceive('createInvoiceForUser')
                ->once()
                ->withArgs(fn (User $u, int $serviceId, array $selection): bool => $serviceId === 5
                    && (int) ($selection['target_value'] ?? 0) === 200)
                ->andReturn($invoice);
        });

        $this->withToken($plain)
            ->getJson('/api/v2/open/services/5/flow-packages')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.supported', true)
            ->assertJsonPath('data.packages.0.target_value', 200);

        $this->withToken($plain)
            ->postJson('/api/v2/open/services/5/flow-packages/orders', ['target_value' => 200])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', (int) $invoice->id)
            ->assertJsonPath('data.invoice_no', (string) $invoice->invoice_no)
            ->assertJsonPath('data.amount', '30.00');
    }

    public function test_flow_package_order_requires_target_value(): void
    {
        $user = $this->makeUser();
        [, $plain] = $this->createKey($user);

        $this->withToken($plain)
            ->postJson('/api/v2/open/services/5/flow-packages/orders', [])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['target_value']]]);
    }

    public function test_foreign_service_is_rejected_before_any_upstream_call(): void
    {
        Http::fake();

        $owner = $this->makeUser();
        $service = $this->makeService($owner);

        // 另一个账号的密钥：服务不属于它，必须在触达上游之前 404
        $intruder = $this->makeUser();
        [, $plain] = $this->createKey($intruder);

        $this->withToken($plain)
            ->getJson('/api/v2/open/services/'.$service->id.'/upgrades')
            ->assertStatus(404)
            ->assertJsonPath('code', 40400);

        $this->withToken($plain)
            ->getJson('/api/v2/open/services/'.$service->id.'/flow-packages')
            ->assertStatus(404)
            ->assertJsonPath('code', 40400);

        $this->withToken($plain)
            ->postJson('/api/v2/open/services/'.$service->id.'/flow-packages/orders', ['target_value' => 200])
            ->assertStatus(404)
            ->assertJsonPath('code', 40400);

        Http::assertNothingSent();
    }

    /* ------------------------------- 驱动契约 ------------------------------- */

    public function test_driver_maps_upgrade_preview_quote_and_purchase(): void
    {
        $this->fakeUpstream(function (string $method, string $url): array {
            if ($method === 'GET' && str_ends_with($url, '/services/52001/upgrades')) {
                return [
                    'code' => 0,
                    'message' => 'ok',
                    'data' => [
                        'service_id' => self::UPSTREAM_SERVICE_ID,
                        'options' => [['id' => 1, 'name' => '套餐']],
                    ],
                ];
            }

            if ($method === 'POST' && str_ends_with($url, '/services/52001/upgrades/quotes')) {
                return [
                    'code' => 0,
                    'message' => 'ok',
                    'data' => ['product_id' => 51, 'billing_cycle' => 'monthly', 'quote' => ['amount_total' => '12.00']],
                ];
            }

            if ($method === 'POST' && str_ends_with($url, '/services/52001/upgrades/orders')) {
                return [
                    'code' => 0,
                    'message' => 'ok',
                    'data' => ['id' => self::UPSTREAM_INVOICE_ID, 'invoice_no' => 'INV-91001', 'amount' => '12.00'],
                ];
            }

            if ($method === 'POST' && str_ends_with($url, '/orders/91001/pay')) {
                return ['code' => 0, 'message' => 'ok', 'data' => ['id' => self::UPSTREAM_INVOICE_ID, 'status' => InvoiceStatus::PAID]];
            }

            if ($method === 'GET' && str_ends_with($url, '/services/52001')) {
                return [
                    'code' => 0,
                    'message' => 'ok',
                    'data' => [
                        'id' => self::UPSTREAM_SERVICE_ID,
                        'name' => 'upstream-host',
                        'domain' => 'upstream.example.test',
                        'status' => ServiceStatus::ACTIVE,
                        'expires_at' => '2027-06-01 00:00:00',
                    ],
                ];
            }

            return ['code' => 50000, 'message' => '测试未模拟的上游端点: '.$method.' '.$url];
        });

        $supplier = $this->withRuntimeCredentials($this->makeSupplier());
        $driver = new TuraOpenApi;

        $preview = $driver->getHostUpgradeOptions($supplier, self::UPSTREAM_SERVICE_ID);
        $this->assertSame(200, (int) $preview['status']);
        $this->assertSame(1, (int) ($preview['data'][0]['id'] ?? 0));

        $quote = $driver->previewHostUpgrade($supplier, self::UPSTREAM_SERVICE_ID, 51, 'monthly');
        $this->assertSame('12.00', (string) ($quote['data']['amount_total'] ?? ''));

        $purchase = $driver->purchaseHostUpgrade($supplier, self::UPSTREAM_SERVICE_ID, 51, 'monthly', 'PROMO10');
        $this->assertSame(self::UPSTREAM_INVOICE_ID, (int) $purchase['upstream_invoice_id']);
        $this->assertSame(self::UPSTREAM_SERVICE_ID, (int) ($purchase['host_detail']['id'] ?? 0));

        // 报价/下单请求体必须与上游端点契约一致
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/services/52001/upgrades/quotes')
            && (int) ($request->data()['product_id'] ?? 0) === 51
            && (string) ($request->data()['billing_cycle'] ?? '') === 'monthly');
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/services/52001/upgrades/orders')
            && (int) ($request->data()['product_id'] ?? 0) === 51
            && (string) ($request->data()['promo_code'] ?? '') === 'PROMO10');
    }

    public function test_driver_maps_traffic_packages_and_purchase(): void
    {
        $this->fakeUpstream(function (string $method, string $url): array {
            if ($method === 'GET' && str_ends_with($url, '/services/52001/flow-packages')) {
                return [
                    'code' => 0,
                    'message' => 'ok',
                    'data' => [
                        'supported' => true,
                        'message' => '',
                        'service_id' => self::UPSTREAM_SERVICE_ID,
                        'traffic' => ['used' => '10.00', 'limit' => '100.00'],
                        'packages' => [
                            ['target_value' => 200, 'target_label' => '200GB', 'price' => '30.00'],
                            ['target_value' => 500, 'target_label' => '500GB', 'price' => '60.00'],
                        ],
                    ],
                ];
            }

            if ($method === 'POST' && str_ends_with($url, '/services/52001/flow-packages/orders')) {
                return [
                    'code' => 0,
                    'message' => 'ok',
                    'data' => ['id' => self::UPSTREAM_INVOICE_ID, 'invoice_no' => 'INV-91001', 'amount' => '30.00'],
                ];
            }

            if ($method === 'POST' && str_ends_with($url, '/orders/91001/pay')) {
                return ['code' => 0, 'message' => 'ok', 'data' => ['id' => self::UPSTREAM_INVOICE_ID]];
            }

            if ($method === 'GET' && str_ends_with($url, '/services/52001')) {
                return [
                    'code' => 0,
                    'message' => 'ok',
                    'data' => ['id' => self::UPSTREAM_SERVICE_ID, 'name' => 'upstream-host', 'status' => ServiceStatus::ACTIVE],
                ];
            }

            return ['code' => 50000, 'message' => '测试未模拟的上游端点: '.$method.' '.$url];
        });

        $supplier = $this->withRuntimeCredentials($this->makeSupplier());
        $driver = new TuraOpenApi;

        $packages = $driver->getTrafficPackages($supplier, self::UPSTREAM_SERVICE_ID);
        $this->assertTrue((bool) $packages['supported']);
        $this->assertCount(2, (array) $packages['packages']);

        // 兼容层：把档位合成升级配置项，下游 configure → [optionId => target_value]
        $config = $driver->getHostUpgradeConfigOptions($supplier, self::UPSTREAM_SERVICE_ID);
        $this->assertSame(200, (int) ($config['response']['status'] ?? 0));
        $option = (array) ($config['options'][0] ?? []);
        $this->assertSame('flow_limit', (string) ($option['field'] ?? ''));
        $this->assertSame(200, (int) ($option['sub'][0]['id'] ?? 0));
        $this->assertSame(500, (int) ($option['sub'][1]['id'] ?? 0));

        // 下游按 [optionId => subId] 传 configoption，驱动要能反解成 target_value
        $purchase = $driver->purchaseTrafficPackage(
            $supplier,
            self::UPSTREAM_SERVICE_ID,
            'upgradeconfig',
            [1 => 200],
            0,
            self::UPSTREAM,
        );
        $this->assertSame(self::UPSTREAM_INVOICE_ID, (int) $purchase['upstream_invoice_id']);
        $this->assertSame(self::UPSTREAM_SERVICE_ID, (int) ($purchase['host_detail']['id'] ?? 0));

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/services/52001/flow-packages/orders')
            && (int) ($request->data()['target_value'] ?? 0) === 200);
    }

    public function test_driver_declares_host_upgrade_and_flow_packet_capabilities(): void
    {
        $driver = new TuraOpenApi;
        $capabilities = $driver->capabilities();

        $this->assertContains(TuraOpenApi::CAPABILITY_HOST_UPGRADE, $capabilities);
        $this->assertContains(TuraOpenApi::CAPABILITY_FLOW_PACKET, $capabilities);
        $this->assertTrue($driver->supports(TuraOpenApi::CAPABILITY_HOST_UPGRADE));
        $this->assertTrue($driver->supports(TuraOpenApi::CAPABILITY_FLOW_PACKET));
        $this->assertSame($driver, $driver->resolve(TuraOpenApi::CAPABILITY_HOST_UPGRADE));

        // 编排层读的是插件清单（PluginUpstreamDriver::capabilities），两处必须一致
        $manifest = app(PluginScanner::class)->requireManifest('upstream', 'tura_open_api');
        $this->assertContains(TuraOpenApi::CAPABILITY_HOST_UPGRADE, $manifest->capabilities);
        $this->assertContains(TuraOpenApi::CAPABILITY_FLOW_PACKET, $manifest->capabilities);
    }

    /* --------------------------------- 夹具 --------------------------------- */

    private function makeUser(): User
    {
        $unique = 'open-upgrade-'.bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => $unique.'@example.test',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);
    }

    private function makeSupplier(): Supplier
    {
        $unique = 'open-upgrade-supplier-'.bin2hex(random_bytes(4));
        $pluginId = (int) DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where('plugin_key', 'tura_open_api')
            ->value('id');
        $this->assertGreaterThan(0, $pluginId);

        $supplier = Supplier::query()->create([
            'name' => 'Open Upgrade Supplier '.$unique,
            'code' => $unique,
            'status' => 1,
            'sort_order' => 1,
        ]);

        DB::table('supplier_plugin_bindings')->insert([
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

        return $supplier;
    }

    private function makeService(User $user): Service
    {
        $unique = 'open-upgrade-service-'.bin2hex(random_bytes(4));

        $product = Product::query()->create([
            'name' => 'Open Upgrade Product '.$unique,
            'product_type' => 'server',
            'pricing' => ['monthly' => '99.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
        ]);

        return Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Open Upgrade Service '.$unique,
            'domain' => $unique.'.example.test',
            'billing_cycle' => 'monthly',
            'amount' => '99.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => [],
            'provision_data' => [],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);
    }

    private function makeInvoice(User $user, string $amount): Invoice
    {
        $unique = strtoupper(bin2hex(random_bytes(4)));

        return Invoice::query()->create([
            'invoice_no' => 'OPENUPG'.$unique,
            'user_id' => (int) $user->id,
            'type' => 'upgrade',
            'amount' => $amount,
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::UNPAID,
            'due_date' => now()->addDays(7),
        ]);
    }

    /**
     * @param  array<string, string>  $scopes
     * @return array{0: ApiKey, 1: string}
     */
    private function createKey(User $user, array $scopes = ['services' => 'write', 'orders' => 'write']): array
    {
        return app(ApiKeyService::class)->createForUser($user, '升级流量包测试', $scopes);
    }

    private function withRuntimeCredentials(Supplier $supplier): Supplier
    {
        return app(PluginBindingResolver::class)->supplierWithRuntimeCredentials($supplier);
    }

    /**
     * 闭包式 fake：按方法与 URL 分发，避免通配符歧义。
     *
     * @param  \Closure(string, string): array<string, mixed>  $handler
     */
    private function fakeUpstream(\Closure $handler): void
    {
        Http::fake(function ($request) use ($handler) {
            return Http::response($handler((string) $request->method(), (string) $request->url()));
        });
    }
}
