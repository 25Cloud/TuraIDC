<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\IntegrationPlugin;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginInstaller;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\Contracts\ProvidesConsoleRuntime;
use App\Services\Upstream\Contracts\ProvidesProvisioning;
use App\Services\Upstream\Contracts\ProvidesRenewal;
use App\Services\Upstream\ProviderRegistry;
use TuraIDC\Plugins\Servers\KangHostx\Logic\KangHostx;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class KangHostxPluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->cleanPluginTables();
    }

    protected function tearDown(): void
    {
        $this->cleanPluginTables();
        parent::tearDown();
    }

    public function test_kanghostx_registers_as_upstream_provider(): void
    {
        $this->ensurePluginTables();
        $this->activatePlugin('upstream', 'kanghostx');

        $this->app->forgetInstance(ProviderRegistry::class);

        $driver = app(ProviderRegistry::class)->find('kanghostx');

        $this->assertNotNull($driver);
        $this->assertSame('kanghostx', $driver->key());
        $this->assertSame('康乐虚拟主机', $driver->label());
        $this->assertTrue($driver->supports(ProvidesProvisioning::class));
        $this->assertTrue($driver->supports(ProvidesConsoleCatalog::class));
        $this->assertInstanceOf(KangHostx::class, $driver->resolve(ProvidesConsoleRuntime::class));

        $schema = $driver->supplierFormSchema();
        $fields = collect($schema['fields'] ?? [])->keyBy('key');

        $this->assertTrue($fields->has('api_url'));
        $this->assertTrue($fields->has('api_key'));
        $this->assertFalse($fields->has('web_quota_mb'));
        $this->assertFalse($fields->has('db_quota_mb'));
        $this->assertFalse($fields->has('flow_limit_gb'));
        $this->assertFalse($fields->has('api_username'));

        $catalogCapability = $driver->resolve(ProvidesConsoleCatalog::class);
        $this->assertInstanceOf(KangHostx::class, $catalogCapability);

        $catalog = $catalogCapability->getProductCatalog($this->makeSupplier());
        $this->assertNotEmpty($catalog['products'] ?? []);

        $template = $catalogCapability->getProductConfigTemplate($this->makeSupplier(), 1);
        $configFields = collect($template['config_options'] ?? [])->pluck('field');
        $this->assertTrue($configFields->contains('web_quota_mb'));
        $this->assertTrue($configFields->contains('db_quota_mb'));
        $this->assertTrue($configFields->contains('flow_limit_gb'));
    }

    public function test_kanghostx_provision_builds_signed_add_vh_request(): void
    {
        $this->ensurePluginTables();
        $this->activatePlugin('upstream', 'kanghostx');
        $this->app->forgetInstance(ProviderRegistry::class);

        $requests = [];
        Http::fake(function (Request $request) use (&$requests) {
            $query = $this->queryFromRequest($request);
            $requests[] = $query;

            $this->assertSame('whm', $query['c'] ?? null);
            $this->assertMatchesRegularExpression('/^\d{6}$/', (string) ($query['r'] ?? ''));
            $this->assertSame(md5(($query['a'] ?? '').'secret-accesshash'.($query['r'] ?? '')), $query['s'] ?? null);

            return Http::response([
                'result' => 200,
                'status' => 0,
                'name' => $query['name'] ?? '',
            ]);
        });

        $driver = app(ProviderRegistry::class)->find('kanghostx');
        $provisioning = $driver?->resolve(ProvidesProvisioning::class);
        $this->assertInstanceOf(KangHostx::class, $provisioning);

        $service = new Service;
        $service->id = 456;

        $result = $provisioning->provisionOrder($this->makeOrderWithService(123, 456), $this->makeSupplier(), $service);

        $this->assertSame(456, $result['upstream_host_id']);
        $this->assertSame([456], $result['upstream_host_ids']);
        $this->assertSame('cw456', $result['requested_host']);
        $this->assertSame('Active', $result['host_detail']['domainstatus'] ?? null);
        $this->assertSame('cw456', $result['host_detail']['username'] ?? null);
        $this->assertSame('PanelPassw0rd!', $result['host_detail']['password'] ?? null);

        $createRequest = $requests[0] ?? [];
        $this->assertSame('add_vh', $createRequest['a'] ?? null);
        $this->assertSame('cw456', $createRequest['name'] ?? null);
        $this->assertSame('PanelPassw0rd!', $createRequest['passwd'] ?? null);
        $this->assertSame('2048', (string) ($createRequest['web_quota'] ?? ''));
        $this->assertSame('512', (string) ($createRequest['db_quota'] ?? ''));
        $this->assertSame('300', (string) ($createRequest['flow_limit'] ?? ''));
        $this->assertSame((string) (20 * 128), (string) ($createRequest['speed_limit'] ?? ''));
        $this->assertSame('wwwroot', $createRequest['subdir'] ?? null);
        $this->assertSame('php', $createRequest['module'] ?? null);
        $this->assertSame('mysql', $createRequest['db_type'] ?? null);
    }

    public function test_kanghostx_runtime_actions_map_to_kangle_whm_api(): void
    {
        $this->ensurePluginTables();
        $this->activatePlugin('upstream', 'kanghostx');
        $this->app->forgetInstance(ProviderRegistry::class);

        $requests = [];
        Http::fake(function (Request $request) use (&$requests) {
            $query = $this->queryFromRequest($request);
            $requests[] = $query;

            return match ($query['a'] ?? '') {
                'info' => Http::response(['result' => 200, 'version' => 'test']),
                'getVh' => Http::response([
                    'result' => 200,
                    'data' => [
                        'status' => 1,
                        'name' => $query['name'] ?? '',
                        'web_quota' => 1024,
                        'db_quota' => 512,
                        'flow_limit' => 100,
                    ],
                ]),
                'update_vh', 'change_password' => Http::response(['result' => 200]),
                default => Http::response(['result' => 500, 'msg' => 'unexpected action'], 200),
            };
        });

        $driver = app(ProviderRegistry::class)->find('kanghostx');
        $runtime = $driver?->resolve(ProvidesConsoleRuntime::class);
        $this->assertInstanceOf(KangHostx::class, $runtime);
        $renewal = $driver?->resolve(ProvidesRenewal::class);
        $this->assertInstanceOf(KangHostx::class, $renewal);

        $supplier = $this->makeSupplier();
        $this->assertSame('kanghostx:88', $runtime->login($supplier));

        $balance = $renewal->getBalance($supplier);
        $this->assertSame('0.00', $balance['balance'] ?? null);
        $this->assertSame('connected', $balance['connection_status'] ?? null);
        $this->assertSame('连接正常', $balance['connection_message'] ?? null);

        $detail = $runtime->getHostDetail($supplier, 456);
        $this->assertSame('Suspended', $detail['data']['host']['domainstatus'] ?? null);
        $this->assertSame('cw456', $detail['data']['host']['username'] ?? null);

        $power = $runtime->powerAction($supplier, 456, 'off');
        $this->assertSame('stopped', $power['data']['power_state'] ?? null);

        $reset = $runtime->resetPassword($supplier, 456, 'NewPassw0rd!');
        $this->assertTrue($reset['data']['reset'] ?? false);

        $actions = array_column($requests, 'a');
        $this->assertContains('info', $actions);
        $this->assertContains('getVh', $actions);
        $this->assertContains('update_vh', $actions);
        $this->assertContains('change_password', $actions);

        $detailRequest = collect($requests)->firstWhere('a', 'getVh');
        $this->assertSame('cw456', $detailRequest['name'] ?? null);
        $this->assertSame('1', (string) ($detailRequest['showpasswd'] ?? ''));

        $update = collect($requests)->firstWhere('a', 'update_vh');
        $this->assertSame('cw456', $update['name'] ?? null);
        $this->assertSame('1', (string) ($update['status'] ?? ''));

        $password = collect($requests)->firstWhere('a', 'change_password');
        $this->assertSame('cw456', $password['name'] ?? null);
        $this->assertSame('NewPassw0rd!', $password['passwd'] ?? null);
    }

    public function test_kanghostx_supplier_refresh_card_tolerates_array_info_fields(): void
    {
        $this->ensurePluginTables();
        $this->activatePlugin('upstream', 'kanghostx');
        $this->app->forgetInstance(ProviderRegistry::class);

        Http::fake(function (Request $request) {
            $query = $this->queryFromRequest($request);
            $this->assertSame('info', $query['a'] ?? null);

            return Http::response([
                'result' => 200,
                'version' => ['raw' => 'nested-version'],
                'data' => [
                    'version' => 'panel-3.5',
                ],
            ]);
        });

        $driver = app(ProviderRegistry::class)->find('kanghostx');
        $runtime = $driver?->resolve(ProvidesConsoleRuntime::class);
        $this->assertInstanceOf(KangHostx::class, $runtime);

        $result = $runtime->execute([
            'action' => 'server.supplier.refresh_card',
            'context' => [
                'supplier' => $this->makeSupplier(),
                'binding' => [
                    'base_url' => ['unexpected' => 'array'],
                    'last_check_status' => ['unexpected' => 'array'],
                    'has_api_key' => true,
                ],
            ],
        ]);

        $fields = collect($result['data']['card']['fields'] ?? [])->keyBy('key');

        $this->assertTrue((bool) ($result['success'] ?? false));
        $this->assertSame('connected', $result['data']['remote']['connection_status'] ?? null);
        $this->assertSame('panel-3.5', $result['data']['remote']['client']['version'] ?? null);
        $this->assertSame('http://panel.example.test:3312', $fields->get('panel_url')['value'] ?? null);
        $this->assertSame('正常', $fields->get('connection_status')['value'] ?? null);
    }

    public function test_kanghostx_supplier_refresh_card_reports_array_error_messages(): void
    {
        $this->ensurePluginTables();
        $this->activatePlugin('upstream', 'kanghostx');
        $this->app->forgetInstance(ProviderRegistry::class);

        Http::fake(function (Request $request) {
            $query = $this->queryFromRequest($request);
            $this->assertSame('info', $query['a'] ?? null);

            return Http::response([
                'result' => 500,
                'msg' => [
                    'message' => 'accesshash invalid',
                ],
            ]);
        });

        $driver = app(ProviderRegistry::class)->find('kanghostx');
        $runtime = $driver?->resolve(ProvidesConsoleRuntime::class);
        $this->assertInstanceOf(KangHostx::class, $runtime);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('康乐连接检测失败：accesshash invalid');

        $runtime->execute([
            'action' => 'server.supplier.refresh_card',
            'context' => [
                'supplier' => $this->makeSupplier(),
                'binding' => [
                    'base_url' => 'http://panel.example.test:3312',
                    'has_api_key' => true,
                ],
            ],
        ]);
    }

    private function activatePlugin(string $domain, string $slug): IntegrationPlugin
    {
        $scanner = app(PluginScanner::class);
        $installer = app(PluginInstaller::class);
        $configRepository = app(PluginConfigRepository::class);

        $manifest = $scanner->requireManifest($domain, $slug);
        $plugin = $installer->install($domain, $slug);
        $configRepository->save($plugin, $manifest, []);

        return $installer->enable($plugin);
    }

    private function makeSupplier(): Supplier
    {
        $supplier = new Supplier;
        $supplier->id = 88;
        $supplier->name = '康乐测试供应商';
        $supplier->setAttribute('provider_key', 'kanghostx');
        $supplier->setAttribute('api_url', 'http://panel.example.test:3312');
        $supplier->setAttribute('api_key', 'secret-accesshash');
        $supplier->setAttribute('provider_config', [
            'ssl_verify' => false,
            'timeout' => 5,
        ]);

        return $supplier;
    }

    private function makeOrderWithService(int $orderId, int $serviceId): Order
    {
        $order = new Order;
        $order->id = $orderId;
        $order->order_no = 'T'.$orderId;
        $order->billing_cycle = 'monthly';
        $order->config_snapshot = [
            'password' => 'PanelPassw0rd!',
        ];

        $service = new Service;
        $service->id = $serviceId;
        $order->setRelation('service', $service);

        $product = new Product;
        $product->id = 789;
        $product->config_options = [
            $this->kanghostxConfigOption('web_quota_mb', '2048'),
            $this->kanghostxConfigOption('db_quota_mb', '512'),
            $this->kanghostxConfigOption('flow_limit_gb', '300'),
            $this->kanghostxConfigOption('domain_limit', '-1'),
            $this->kanghostxConfigOption('max_subdir', '0'),
            $this->kanghostxConfigOption('default_subdir', 'wwwroot'),
            $this->kanghostxConfigOption('speed_limit_mbps', '20'),
            $this->kanghostxConfigOption('ftp', '1'),
            $this->kanghostxConfigOption('module', 'php'),
            $this->kanghostxConfigOption('db_type', 'mysql'),
        ];
        $order->setRelation('product', $product);

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    private function kanghostxConfigOption(string $field, string $value): array
    {
        $subItem = [
            'id' => $value,
            'value' => $value,
            'label' => $field,
            'option_name' => $field,
            'option_name_first' => $value,
            'is_default' => 1,
            'default' => 1,
        ];

        return [
            'field' => $field,
            'name' => $field,
            'option_name' => $field,
            'option_mode' => 'select',
            'parameter' => $value.'|'.$field,
            'sub' => [$subItem],
            'sub_items' => [$subItem],
            'required' => true,
            'hidden' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function queryFromRequest(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return is_array($query) ? $query : [];
    }

    private function cleanPluginTables(): void
    {
        if (Schema::hasTable('integration_plugin_configs')) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0');
            DB::table('integration_plugin_configs')->truncate();
            DB::table('integration_plugins')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function ensurePluginTables(): void
    {
        if (! Schema::hasTable('integration_plugins')) {
            Schema::create('integration_plugins', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 32);
                $table->string('slug', 120);
                $table->string('plugin_key', 120);
                $table->string('name', 120);
                $table->string('version', 32)->default('1.0.0');
                $table->string('provider_class', 255)->nullable();
                $table->string('entry_class', 255);
                $table->json('capabilities_json')->nullable();
                $table->json('config_schema_json')->nullable();
                $table->unsignedTinyInteger('status')->default(0);
                $table->timestamp('installed_at')->nullable();
                $table->timestamps();
                $table->unique(['domain', 'slug']);
                $table->unique(['domain', 'plugin_key']);
            });
        }

        if (! Schema::hasTable('integration_plugin_configs')) {
            Schema::create('integration_plugin_configs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('plugin_id');
                $table->json('config_json')->nullable();
                $table->longText('secret_json')->nullable();
                $table->json('has_secret_json')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique('plugin_id');
            });
        }
    }
}
