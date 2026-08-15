<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ServiceStatus;
use App\Models\FirstProductGroup;
use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\ThirdProductGroup;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServiceNatService;
use App\Services\ClientServiceConsole\ServiceResolverService;
use App\Services\ClientServiceConsole\ServiceSecurityGroupService;
use App\Services\ClientServiceConsole\ServiceTrafficPackageService;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Services\ClientServiceConsole\ServiceVncService;
use App\Services\Finance\InvoiceService;
use App\Services\System\OperationLogService;
use App\Services\System\SettingService;
use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\Contracts\ProvidesConsoleRuntime;
use App\Services\Upstream\Contracts\UpstreamDriver;
use App\Services\Upstream\ProviderKey;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use App\Support\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceConsolePayloadSnapshotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->activateIntegrationPluginForTest('upstream', 'zjmf_finance');
        Cache::flush();
    }

    #[Test]
    public function service_detail_payload_prefers_normalized_binding_and_snapshot_tables(): void
    {
        $fixture = $this->makeBoundServiceFixture();
        $driver = $this->makeSnapshotDriver();
        $this->bindProviderResolver($driver);

        $detail = $this->makeTransformService()->transformDetail(
            $fixture['service']->fresh([
                'product.productGroup.secondProductGroup.firstProductGroup',
                'order',
                'invoice',
            ])
        );

        $this->assertSame(ProviderKey::ZJMF_FINANCE_API, $detail['upstream']['provider_key']);
        $this->assertSame((int) $fixture['supplier']->id, (int) $detail['upstream']['supplier_id']);
        $this->assertSame(8001, (int) $detail['upstream']['upstream_product_id']);
        $this->assertSame(88001, (int) $detail['upstream']['host_id']);
        $this->assertSame('running', $detail['runtime']['power_state']);
        $this->assertSame((string) $fixture['service']->domain, $detail['connection']['hostname']);
        $this->assertSame('snapshot-root', $detail['connection']['username']);
        $this->assertSame('snapshot-secret', $detail['connection']['password']);
        $this->assertSame('203.0.113.80', $detail['connection']['dedicated_ip']);

        $this->assertNotSame('hosting_panel_api', $detail['upstream']['provider_key']);
        $this->assertNotSame(999999, (int) $detail['upstream']['supplier_id']);
        $this->assertNotSame(111, (int) $detail['upstream']['host_id']);
    }

    #[Test]
    public function vnc_payload_uses_connection_snapshot_and_public_exchange_never_returns_password(): void
    {
        $fixture = $this->makeBoundServiceFixture();
        $driver = $this->makeSnapshotDriver();
        $this->bindProviderResolver($driver);

        $detailService = $this->getMockBuilder(ServiceDetailService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findUserService', 'resolveUpstreamContext', 'assertSuccess', 'extractPayload'])
            ->getMock();
        $detailService->method('findUserService')->willReturn($fixture['service']->fresh(['product']));
        $detailService->method('resolveUpstreamContext')->willReturn([$driver, $fixture['supplier'], 88001, 'snapshot-jwt']);
        $detailService->method('assertSuccess')->willReturnCallback(static function (): void {});
        $detailService->method('extractPayload')->willReturnCallback(
            static fn (array $response): array => is_array($response['data'] ?? null) ? $response['data'] : $response
        );

        $vncService = new ServiceVncService(
            $this->createMock(OperationLogService::class),
            $detailService,
            $this->makeTransformService(),
        );

        $result = $vncService->getVncUrlForUser(
            $fixture['user'],
            (int) $fixture['service']->id,
            ['request_origin' => 'https://console.example.test']
        );

        $this->assertStringContainsString('/vnc/vnc.html?', $result['url']);
        $this->assertSame('snapshot-root', $result['vnc_credentials']['username'] ?? null);
        $this->assertSame('snapshot-secret', $result['vnc_credentials']['password'] ?? null);

        parse_str((string) parse_url((string) $result['url'], PHP_URL_QUERY), $query);
        $publicToken = (string) ($query['token'] ?? '');
        $this->assertNotSame('', $publicToken);

        $publicPayload = $vncService->resolvePublicVncTokenPayload($publicToken);
        $this->assertArrayNotHasKey('password', $publicPayload);
        $this->assertSame((int) $fixture['service']->id, (int) $publicPayload['service_id']);
        $this->assertSame('/ws/vnc', $publicPayload['relay_path']);

        $relayParams = $vncService->resolveVncToken((string) $publicPayload['token']);
        // relay 建连载荷不落明文 VNC 密码（第四轮安全加固），密码仅经 vnc_credentials 返回。
        $this->assertArrayNotHasKey('password', $relayParams);
        $this->assertTrue(Cache::store('redis_volatile')->has(CacheKey::vncToken((string) $publicPayload['token'])));
    }

    #[Test]
    public function nat_and_security_group_contexts_resolve_supplier_and_host_from_service_binding(): void
    {
        $fixture = $this->makeBoundServiceFixture();
        $driver = $this->makeSnapshotDriver();
        $this->bindProviderResolver($driver);

        $detailService = $this->makeDetailService();
        $transformService = $this->makeTransformService();
        $natService = new ServiceNatService(
            $this->createMock(OperationLogService::class),
            $detailService,
            $transformService,
        );
        $securityGroupService = new ServiceSecurityGroupService(
            $this->createMock(OperationLogService::class),
            $detailService,
            $transformService,
            $natService,
        );

        [, $natSupplier, $natHostId, $natJwt, $natContext] = $natService->resolveNatAclContext($fixture['service']->fresh(['product']), true);
        $securityContext = $securityGroupService->resolveSecurityGroupContext($fixture['service']->fresh(['product']), true);

        $this->assertSame((int) $fixture['supplier']->id, (int) $natSupplier->id);
        $this->assertSame(88001, (int) $natHostId);
        $this->assertSame('snapshot-jwt', $natJwt);
        $this->assertSame('nat_acl', $natContext['module_key']);
        $this->assertSame('/provision/custom/nat', $natContext['endpoint']);

        $this->assertSame((int) $fixture['supplier']->id, (int) $securityContext['supplier_id']);
        $this->assertSame(88001, (int) $securityContext['host_id']);
        $this->assertSame('security_group', $securityContext['module_key']);
        $this->assertSame('https://upstream.example/provision/custom/88001', $securityContext['endpoint']);

        $this->assertContains(['method' => 'login', 'supplier_id' => (int) $fixture['supplier']->id], $driver->calls);
        $this->assertContains(['method' => 'modules', 'supplier_id' => (int) $fixture['supplier']->id, 'host_id' => 88001], $driver->calls);
        $this->assertContains(['method' => 'module_action_endpoint', 'supplier_id' => (int) $fixture['supplier']->id, 'host_id' => 88001], $driver->calls);
    }

    #[Test]
    public function traffic_package_preview_uses_bound_supplier_and_records_available_payload(): void
    {
        $fixture = $this->makeBoundServiceFixture();
        $driver = $this->makeSnapshotDriver();
        $this->bindProviderResolver($driver);
        $catalogProduct = $fixture['product']->fresh();
        $this->assertInstanceOf(Product::class, $catalogProduct);

        $trafficPackageSettingKeys = [
            'traffic_package_enabled',
            'traffic_package_option_field',
            'traffic_package_option_keyword',
            'traffic_package_allow_choice_mode',
            'traffic_package_allow_quantity_mode',
        ];
        $originalTrafficPackageSettings = Setting::query()
            ->where('group_key', 'traffic_package')
            ->whereIn('item_key', $trafficPackageSettingKeys)
            ->pluck('item_value', 'item_key')
            ->all();
        $originalCatalog = Setting::getValue('traffic_package_catalog', 'items', '[]');
        Setting::setValues('traffic_package', [
            'traffic_package_enabled' => '1',
            'traffic_package_option_field' => 'flow_limit',
            'traffic_package_option_keyword' => '流量',
            'traffic_package_allow_choice_mode' => '1',
            'traffic_package_allow_quantity_mode' => '1',
        ]);
        Setting::setValue('traffic_package_catalog', 'items', json_encode([[
            'category_id' => (int) $catalogProduct->product_group_id,
            'product_type' => (string) $catalogProduct->product_type,
            'product_ids' => [(int) $catalogProduct->id],
            'label' => '2TB',
            'target_value' => 2048,
            'price' => '39.90',
            'enabled' => 1,
            'sort_order' => 1,
        ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        try {
            $trafficPackageService = new ServiceTrafficPackageService(
                $this->makeDetailService(),
                new InvoiceService,
                $this->createMock(OperationLogService::class),
                new SettingService,
                app(ProviderResolver::class),
            );

            $payload = $trafficPackageService->previewForUser($fixture['user'], (int) $fixture['service']->id);
        } finally {
            Setting::setValue('traffic_package_catalog', 'items', (string) $originalCatalog);
            Setting::query()
                ->where('group_key', 'traffic_package')
                ->whereIn('item_key', $trafficPackageSettingKeys)
                ->delete();
            Setting::setValues('traffic_package', $originalTrafficPackageSettings);
            Setting::forgetCachedGroup('traffic_package');
        }

        $this->assertTrue($payload['supported'], json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertSame((int) $fixture['service']->id, (int) $payload['service_id']);
        $this->assertSame('256.00', $payload['traffic']['usage']);
        $this->assertSame(1024, (int) $payload['traffic']['limit']);
        $this->assertSame('2TB', $payload['packages'][0]['label'] ?? null);
        $this->assertSame('39.90', $payload['packages'][0]['price'] ?? null);
        $this->assertContains(['method' => 'host_detail', 'supplier_id' => (int) $fixture['supplier']->id, 'host_id' => 88001], $driver->calls);
        $this->assertContains(['method' => 'upgrade_options', 'supplier_id' => (int) $fixture['supplier']->id, 'host_id' => 88001], $driver->calls);
    }

    /**
     * @return array{user: User, supplier: Supplier, product: Product, service: Service, category: SecondProductGroup}
     */
    private function makeBoundServiceFixture(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $pluginId = (int) DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where('plugin_key', ProviderKey::ZJMF_FINANCE_API)
            ->value('id');

        $this->assertGreaterThan(0, $pluginId);

        $user = User::query()->create([
            'email' => 'service-payload-'.$suffix.'@example.test',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Snapshot Supplier '.$suffix,
            'code' => 'snapshot-'.$suffix,
            'status' => 1,
            'sort_order' => 1,
        ]);

        $rootGroup = FirstProductGroup::query()->create([
            'code' => 'vps-'.$suffix,
            'name' => 'VPS '.$suffix,
            'slug' => 'vps-'.$suffix,
            'sort_order' => 0,
            'is_visible' => 1,
            'is_system' => 0,
        ]);

        $category = SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $rootGroup->id,
            'name' => 'Snapshot Category '.$suffix,
            'slug' => 'snapshot-category-'.$suffix,
            'sort_order' => 0,
            'is_visible' => 1,
        ]);
        $thirdGroup = ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $category->id,
            'name' => 'Snapshot Leaf '.$suffix,
            'slug' => 'snapshot-leaf-'.$suffix,
            'sort_order' => 0,
            'is_visible' => 1,
        ]);

        $product = Product::query()->create([
            'product_group_id' => (int) $thirdGroup->id,
            'service_type_code' => 'vps',
            'custom_display_name' => 'Snapshot Product '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '99.00'],
            'setup_fee' => '0.00',
            'config_options' => [[
                'field' => 'flow_limit',
                'name' => '流量',
                'option_type' => 1,
                'sub' => [
                    ['id' => 85333, 'option_name_first' => '1024', 'version' => '1TB'],
                    ['id' => 85334, 'option_name_first' => '2048', 'version' => '2TB'],
                ],
            ]],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 1,
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Snapshot Service '.$suffix,
            'domain' => 'snapshot-'.$suffix.'.example.test',
            'billing_cycle' => 'monthly',
            'amount' => '99.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => [],
            'provision_data' => [
                'provider' => ProviderKey::HOSTING_PANEL_API,
                'provider_key' => ProviderKey::HOSTING_PANEL_API,
                'supplier_id' => 999999,
                'upstream_product_id' => 123,
                'upstream_host_id' => 111,
                'runtime_status' => 'legacy-running',
                'connection_secret' => $this->makeTransformService()->writeCachedConnection([
                    'hostname' => 'legacy-host.example.test',
                    'username' => 'legacy-root',
                    'password' => 'legacy-secret',
                    'port' => 22,
                ]),
            ],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);

        $supplierBindingId = DB::table('supplier_plugin_bindings')->insertGetId([
            'supplier_id' => (int) $supplier->id,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'environment' => 'production',
            'status' => 1,
            'priority' => 0,
            'base_url' => 'https://supplier-'.$suffix.'.example.test/api',
            'account_name' => 'snapshot-account',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productBindingId = DB::table('product_upstream_bindings')->insertGetId([
            'product_id' => (int) $product->id,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'upstream_product_id' => '8001',
            'auto_setup' => 1,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $serviceBindingId = DB::table('service_upstream_bindings')->insertGetId([
            'service_id' => (int) $service->id,
            'product_upstream_binding_id' => $productBindingId,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'upstream_service_id' => '88001',
            'runtime_snapshot_json' => json_encode(['bw_usage' => 256, 'bw_limit' => 1024], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'connection_snapshot_json' => json_encode(['hostname' => 'snapshot-host.example.test'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status_snapshot' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('service_runtime_snapshots')->insert([
            'service_id' => (int) $service->id,
            'service_upstream_binding_id' => $serviceBindingId,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'status_key' => 'running',
            'status_text' => '运行中',
            'resource_json' => json_encode(['os' => 'linux', 'bw_usage' => 256, 'bw_limit' => 1024], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'metrics_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'snapshot_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('service_connection_snapshots')->insert([
            'service_id' => (int) $service->id,
            'service_upstream_binding_id' => $serviceBindingId,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'connection_type' => 'default',
            'hostname' => 'snapshot-host.example.test',
            'ip_address' => '203.0.113.80',
            'port' => 5900,
            'connection_json' => json_encode([
                'hostname' => 'snapshot-host.example.test',
                'username' => 'snapshot-root',
                'port' => 5900,
                'internal_ip' => '10.0.0.8',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'secret_json' => Crypt::encryptString(json_encode(['password' => 'snapshot-secret'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'has_secret_json' => json_encode(['password' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'checked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('user', 'supplier', 'product', 'service', 'category');
    }

    private function makeDetailService(): ServiceDetailService
    {
        return new ServiceDetailService(
            app(ProviderResolver::class),
            $this->createMock(OperationLogService::class),
            new ServiceResolverService,
            $this->makeTransformService(),
        );
    }

    private function makeTransformService(): ServiceTransformService
    {
        return new ServiceTransformService(new ServiceResolverService, new SettingService);
    }

    private function bindProviderResolver(UpstreamDriver $driver): void
    {
        $resolver = new ProviderResolver(new ProviderRegistry([$driver]));
        $this->app->instance(ProviderResolver::class, $resolver);
        $this->app->instance(ProviderRegistry::class, new ProviderRegistry([$driver]));
    }

    private function makeSnapshotDriver(): UpstreamDriver
    {
        return new class implements ProvidesConsoleCatalog, ProvidesConsoleRuntime, UpstreamDriver
        {
            /** @var list<array<string, mixed>> */
            public array $calls = [];

            public function key(): string
            {
                return ProviderKey::ZJMF_FINANCE_API;
            }

            public function label(): string
            {
                return 'Zjmf Snapshot Driver';
            }

            public function capabilities(): array
            {
                return [ProvidesConsoleRuntime::class, ProvidesConsoleCatalog::class];
            }

            public function supports(string $capability): bool
            {
                return in_array($capability, $this->capabilities(), true);
            }

            public function resolve(string $capability): ?object
            {
                return $this->supports($capability) ? $this : null;
            }

            public function login(Supplier $supplier): string
            {
                $this->calls[] = ['method' => 'login', 'supplier_id' => (int) $supplier->id];

                return 'snapshot-jwt';
            }

            public function getSupportedModules(Supplier $supplier, int $hostId, string $jwt): array
            {
                $this->calls[] = ['method' => 'modules', 'supplier_id' => (int) $supplier->id, 'host_id' => $hostId];

                return [
                    'status' => 200,
                    'data' => [
                        'list' => [
                            ['type' => 'custom', 'function' => 'nat_acl', 'name' => 'NAT 转发'],
                            ['type' => 'custom', 'function' => 'security_group', 'name' => '安全组'],
                        ],
                    ],
                ];
            }

            public function fetchCustomModulePage(Supplier $supplier, int $hostId, string $moduleKey, string $jwt): string
            {
                $this->calls[] = ['method' => 'module_page', 'supplier_id' => (int) $supplier->id, 'host_id' => $hostId, 'module_key' => $moduleKey];

                if ($moduleKey === 'nat_acl') {
                    return <<<'HTML'
<button data-target="natAclAddModal">新增</button>
<script>var request = { url: '/provision/custom/nat' };</script>
<select name="select-protocol"><option value="tcp">TCP</option><option value="udp">UDP</option></select>
<div class="table-responsive"><table><tbody>
<tr><td>ssh</td><td>203.0.113.80:22022</td><td>22</td><td>TCP</td><td><button class="deleteNAT" data-id="501">删除</button></td></tr>
</tbody></table></div>
HTML;
                }

                return <<<'HTML'
<script>var host_type = 'host'; var request = { url: '/provision/custom/security' };</script>
<select name="direction"><option value="in">入方向</option></select>
<select name="protocol"><option value="tcp" data-port="22">TCP</option></select>
<div class="table-responsive"><table><tbody>
<tr><td>默认安全组</td><td>默认策略</td><td><button class="apply" data-id="601">应用</button><button class="deleteGroup" data-id="601">删除</button></td></tr>
</tbody></table></div>
HTML;
            }

            public function getCustomModuleActionEndpoint(Supplier $supplier, int $hostId): string
            {
                $this->calls[] = ['method' => 'module_action_endpoint', 'supplier_id' => (int) $supplier->id, 'host_id' => $hostId];

                return "https://upstream.example/provision/custom/{$hostId}";
            }

            public function post(Supplier $supplier, string $uri, array|string $payload = [], ?string $jwt = null, array $headers = [], array $query = []): array
            {
                $this->calls[] = ['method' => 'vnc', 'supplier_id' => (int) $supplier->id, 'uri' => $uri];

                return [
                    'status' => 200,
                    'msg' => '获取VNC链接成功',
                    'data' => [
                        'url' => 'wss://vnc.example.test:5901/websockify?path=websockify',
                    ],
                ];
            }

            public function getHostDetail(Supplier $supplier, int $hostId, string $jwt): array
            {
                $this->calls[] = ['method' => 'host_detail', 'supplier_id' => (int) $supplier->id, 'host_id' => $hostId];

                return [
                    'status' => 200,
                    'data' => [
                        'host' => [
                            'domainstatus' => 'Active',
                            'bwusage' => 256,
                            'bwlimit' => 1024,
                            'config_option' => [
                                ['id' => 15305, 'key' => 'flow_limit', 'name' => '流量', 'value' => '1024'],
                            ],
                        ],
                    ],
                ];
            }

            public function getModuleStatus(Supplier $supplier, int $hostId, string $type, string $jwt): array
            {
                return [
                    'status' => 200,
                    'data' => [
                        'status' => 'Active',
                        'des' => '运行中',
                    ],
                ];
            }

            public function getText(Supplier $supplier, string $uri, ?string $jwt = null, array $query = [], array $headers = []): string
            {
                return '<html></html>';
            }

            public function getHostUpgradeConfigOptions(Supplier $supplier, int $hostId, string $jwt): array
            {
                $this->calls[] = ['method' => 'upgrade_options', 'supplier_id' => (int) $supplier->id, 'host_id' => $hostId];

                return [
                    'response' => ['status' => 200],
                    'payload' => [],
                    'options' => [[
                        'id' => 15305,
                        'field' => 'flow_limit',
                        'name' => '流量',
                        'option_type' => 1,
                        'current_sub_id' => 85333,
                        'current_label' => '1TB',
                        'current_qty' => 1024,
                        'sub' => [
                            ['id' => 85333, 'option_name_first' => '1024', 'version' => '1TB'],
                            ['id' => 85334, 'option_name_first' => '2048', 'version' => '2TB'],
                        ],
                    ]],
                ];
            }
        };
    }
}
