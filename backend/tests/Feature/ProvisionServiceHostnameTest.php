<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\OrderStatus;
use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\Provisioning\ProvisionService;
use App\Services\System\SettingService;
use App\Services\Upstream\Contracts\ProvidesProvisioning;
use App\Services\Upstream\Contracts\UpstreamDriver;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use App\Services\Upstream\ProviderKey;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use App\Support\ProductProvisionHostname;
use ArrayObject;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProvisionServiceHostnameTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(PluginFileLoader::class)->ensureLoaded(
            app(PluginScanner::class)->requireManifest('upstream', 'zjmf_finance')
        );
        $this->activateIntegrationPluginForTest('upstream', 'zjmf_finance');

        Cache::flush();
    }

    #[Test]
    public function it_parses_upstream_hostname_rule_from_grouped_product_config_response(): void
    {
        $service = new ProvisionService(
            $this->makeProviderResolver(new class($this->buildUpstreamProductConfigResponse()) extends HostingPanelApiTransport
            {
                public function __construct(private readonly array $response) {}

                public function login(Supplier $supplier): string
                {
                    return 'jwt-test-token';
                }

                public function get(
                    Supplier $supplier,
                    string $uri,
                    ?string $jwt = null,
                    array $query = [],
                    array $headers = []
                ): array {
                    return $this->response;
                }
            }),
            new class extends SettingService
            {
                public function getProvisionHostnameConfig(): array
                {
                    return [
                        'prefix' => 'srv',
                        'length' => 12,
                        'pool' => '0123456789',
                    ];
                }
            }
        );

        $order = $this->makeOrder('ltser1234567890');
        $rule = $this->invokeResolveUpstreamProvisionHostnameRule($service, $order);

        $this->assertSame('ser', $rule['prefix'] ?? null);
        $this->assertSame(13, $rule['length'] ?? null);
        $this->assertSame('0123456789', $rule['pool'] ?? null);
    }

    #[Test]
    public function it_ignores_stale_empty_hostname_rule_cache_and_parses_single_product_json_host_rule(): void
    {
        Cache::put('provision:upstream_hostname_rule:101:9001', [], now()->addMinutes(30));

        $service = new ProvisionService(
            $this->makeProviderResolver(new class($this->buildSingleProductUpstreamProductConfigResponse()) extends HostingPanelApiTransport
            {
                public function __construct(private readonly array $response) {}

                public function login(Supplier $supplier): string
                {
                    return 'jwt-test-token';
                }

                public function get(
                    Supplier $supplier,
                    string $uri,
                    ?string $jwt = null,
                    array $query = [],
                    array $headers = []
                ): array {
                    return $this->response;
                }
            }),
            new class extends SettingService
            {
                public function getProvisionHostnameConfig(): array
                {
                    return [
                        'prefix' => 'srv',
                        'length' => 12,
                        'pool' => '0123456789',
                    ];
                }
            }
        );

        $order = $this->makeOrder('srv142353153');
        $rule = $this->invokeResolveUpstreamProvisionHostnameRule($service, $order);

        $this->assertSame('ser', $rule['prefix'] ?? null);
        $this->assertSame(15, $rule['length'] ?? null);
        $this->assertSame('0123456789', $rule['pool'] ?? null);
    }

    #[Test]
    public function it_uses_top_level_product_rule_when_host_payload_omits_nested_rule(): void
    {
        $service = new ProvisionService(
            $this->makeProviderResolver(new class($this->buildTopLevelProductRuleUpstreamProductConfigResponse()) extends HostingPanelApiTransport
            {
                public function __construct(private readonly array $response) {}

                public function login(Supplier $supplier): string
                {
                    return 'jwt-test-token';
                }

                public function get(
                    Supplier $supplier,
                    string $uri,
                    ?string $jwt = null,
                    array $query = [],
                    array $headers = []
                ): array {
                    return $this->response;
                }
            }),
            new class extends SettingService
            {
                public function getProvisionHostnameConfig(): array
                {
                    return [
                        'prefix' => 'srv',
                        'length' => 12,
                        'pool' => '0123456789',
                    ];
                }
            }
        );

        $order = $this->makeOrder('snapshot-host', 504);
        $hostname = $this->invokeResolveProvisionHostname($service, $order);

        $this->assertStringStartsWith('ser', $hostname);
        $this->assertSame(15, mb_strlen($hostname));
        $this->assertNotSame('snapshot-host', $hostname);
        $this->assertSame($hostname, (string) (($order->config_snapshot ?? [])['hostname'] ?? ''));
    }

    #[Test]
    public function it_preserves_zjmf_provider_key_after_successful_upstream_provisioning(): void
    {
        $this->activateIntegrationPluginForTest('upstream', 'zjmf_finance');

        $transport = new class extends HostingPanelApiTransport
        {
            public function __construct() {}

            public function login(Supplier $supplier): string
            {
                return 'jwt-test-token';
            }

            public function request(
                Supplier $supplier,
                string $method,
                string $uri,
                array|string $payload = [],
                ?string $jwt = null,
                array $headers = [],
                array $query = []
            ): array {
                if ($uri === '/v1/login_api') {
                    return ['status' => 200, 'code' => 200, 'jwt' => 'jwt-test-token'];
                }

                if ($uri === '/v1/cart') {
                    return [
                        'status' => 200,
                        'code' => 200,
                        'data' => [
                            'gateway_list' => [
                                ['name' => 'credit'],
                            ],
                        ],
                    ];
                }

                if ($uri === '/v1/cart/checkout') {
                    return [
                        'status' => 200,
                        'code' => 200,
                        'data' => [
                            'invoiceid' => 8899,
                            'hostid' => [7788],
                        ],
                    ];
                }

                if ($uri === '/v1/hosts/7788') {
                    return [
                        'status' => 200,
                        'code' => 200,
                        'data' => [
                            'host' => [
                                'domain' => 'srv7788.example.test',
                                'domainstatus' => 'Active',
                                'product_id' => 9001,
                                'product_name' => 'ZJMF云服务器',
                            ],
                        ],
                    ];
                }

                return ['status' => 200, 'code' => 200, 'data' => []];
            }

            public function post(
                Supplier $supplier,
                string $uri,
                array|string $payload = [],
                ?string $jwt = null,
                array $headers = [],
                array $query = []
            ): array {
                if ($uri === '/v1/cart/checkout') {
                    return [
                        'status' => 200,
                        'code' => 200,
                        'data' => [
                            'invoiceid' => 8899,
                            'hostid' => [7788],
                        ],
                    ];
                }

                return ['status' => 200, 'code' => 200, 'data' => []];
            }

            public function get(
                Supplier $supplier,
                string $uri,
                ?string $jwt = null,
                array $query = [],
                array $headers = []
            ): array {
                if ($uri === '/v1/cart') {
                    return [
                        'status' => 200,
                        'code' => 200,
                        'data' => [
                            'gateway_list' => [
                                ['name' => 'credit'],
                            ],
                        ],
                    ];
                }

                if ($uri === '/v1/hosts/7788') {
                    return [
                        'status' => 200,
                        'code' => 200,
                        'data' => [
                            'host' => [
                                'domain' => 'srv7788.example.test',
                                'domainstatus' => 'Active',
                                'product_id' => 9001,
                                'product_name' => 'ZJMF云服务器',
                            ],
                        ],
                    ];
                }

                return ['status' => 200, 'code' => 200, 'data' => []];
            }
        };

        $service = new ProvisionService(
            $this->makeProviderResolver($transport, true),
            new class extends SettingService
            {
                public function getProvisionHostnameConfig(): array
                {
                    return [
                        'prefix' => 'srv',
                        'length' => 12,
                        'pool' => '0123456789',
                    ];
                }
            }
        );

        $order = $this->makeOrder('srv7788', 505);
        $order->exists = true;
        $order->forceFill([
            'type' => 'new',
            'status' => OrderStatus::PAID,
        ]);
        $order->product->forceFill([
            'provision_module' => ProviderKey::ZJMF_FINANCE_API,
        ]);
        $order->product->supplier->forceFill([
            'interface_type' => ProviderKey::ZJMF_FINANCE_API,
        ]);

        $createdService = $service->processPaidOrder($order);

        $this->assertSame(ProviderKey::ZJMF_FINANCE_API, $createdService->provision_data['provider_key'] ?? null);
        $this->assertSame(7788, $createdService->provision_data['upstream_host_id'] ?? null);
        $this->assertSame(9001, $createdService->provision_data['upstream_product_id'] ?? null);
        $this->assertSame(ServiceStatus::ACTIVE, (int) $createdService->status);

        $binding = DB::table('service_upstream_bindings')
            ->where('service_id', (int) $createdService->id)
            ->where('provider_key', ProviderKey::ZJMF_FINANCE_API)
            ->where('upstream_service_id', '7788')
            ->first();

        $this->assertNotNull($binding);
        $this->assertDatabaseHas('service_runtime_snapshots', [
            'service_id' => (int) $createdService->id,
            'service_upstream_binding_id' => (int) $binding->id,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'status_key' => 'Active',
        ]);
        $this->assertDatabaseHas('service_connection_snapshots', [
            'service_id' => (int) $createdService->id,
            'service_upstream_binding_id' => (int) $binding->id,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'connection_type' => 'default',
            'hostname' => 'srv7788',
        ]);
        $this->assertDatabaseHas('service_provision_attempts', [
            'service_id' => (int) $createdService->id,
            'service_upstream_binding_id' => (int) $binding->id,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'action' => 'provision',
            'attempt_status' => 'success',
        ]);
    }

    #[Test]
    public function it_falls_back_to_snapshot_hostname_when_upstream_rule_is_unavailable(): void
    {
        $service = new ProvisionService(
            $this->makeProviderResolver(new class extends HostingPanelApiTransport
            {
                public function __construct() {}

                public function login(Supplier $supplier): string
                {
                    return 'jwt-test-token';
                }

                public function get(
                    Supplier $supplier,
                    string $uri,
                    ?string $jwt = null,
                    array $query = [],
                    array $headers = []
                ): array {
                    return ['data' => ['first_group' => []]];
                }
            }),
            new class extends SettingService
            {
                public function getProvisionHostnameConfig(): array
                {
                    return [
                        'prefix' => 'srv',
                        'length' => 12,
                        'pool' => '0123456789',
                    ];
                }
            }
        );

        $order = $this->makeOrder('ltser1234567890', 502);
        $hostname = $this->invokeResolveProvisionHostname($service, $order);

        $this->assertSame('ltser1234567890', $hostname);
    }

    #[Test]
    public function it_enforces_system_hostname_rule_even_when_product_rule_uses_fixed_hostname(): void
    {
        $service = new ProvisionService(
            $this->makeProviderResolver(new class extends HostingPanelApiTransport
            {
                public function __construct() {}

                public function login(Supplier $supplier): string
                {
                    return 'jwt-test-token';
                }

                public function get(
                    Supplier $supplier,
                    string $uri,
                    ?string $jwt = null,
                    array $query = [],
                    array $headers = []
                ): array {
                    return ['data' => ['first_group' => []]];
                }
            }),
            new class extends SettingService
            {
                public function getProvisionHostnameConfig(): array
                {
                    return [
                        'enforce' => true,
                        'prefix' => 'sys',
                        'length' => 12,
                        'pool' => '0123456789',
                    ];
                }
            }
        );

        $order = $this->makeOrder('snapshot-host', 503, [
            'provision_hostname' => [
                'mode' => ProductProvisionHostname::MODE_FIXED,
                'value' => 'fixed-host',
                'length' => 10,
            ],
        ]);
        $hostname = $this->invokeResolveProvisionHostname($service, $order);

        $this->assertMatchesRegularExpression('/^sys\d{9}$/', $hostname);
        $this->assertSame(12, mb_strlen($hostname));
        $this->assertNotSame('snapshot-host', $hostname);
        $this->assertNotSame('fixed-host', $hostname);
        $this->assertSame($hostname, (string) (($order->config_snapshot ?? [])['hostname'] ?? ''));
    }

    #[Test]
    public function retry_failed_provision_reuses_order_snapshot_hostname_when_resubmitting_to_upstream(): void
    {
        $captured = new ArrayObject;

        $provisionService = new ProvisionService(
            $this->makeProviderResolver(new class($captured) extends HostingPanelApiTransport
            {
                public function __construct(private readonly ArrayObject $captured) {}

                public function login(Supplier $supplier): string
                {
                    return 'jwt-test-token';
                }

                public function get(
                    Supplier $supplier,
                    string $uri,
                    ?string $jwt = null,
                    array $query = [],
                    array $headers = []
                ): array {
                    if ($uri === '/v1/productsconfig') {
                        return ['data' => ['first_group' => []]];
                    }

                    return ['status' => 200, 'data' => []];
                }

                public function request(
                    Supplier $supplier,
                    string $method,
                    string $uri,
                    array|string $payload = [],
                    ?string $jwt = null,
                    array $headers = [],
                    array $query = []
                ): array {
                    if ($uri === '/v1/cart/products') {
                        $this->captured['payload'] = json_decode((string) $payload, true);

                        return [
                            'status' => 400,
                            'msg' => '主机名前缀必须是ser;长度为13;',
                        ];
                    }

                    return ['status' => 200, 'data' => []];
                }
            }),
            new class extends SettingService
            {
                public function getProvisionHostnameConfig(): array
                {
                    return [
                        'prefix' => 'srv',
                        'length' => 12,
                        'pool' => '0123456789',
                    ];
                }
            }
        );

        [$order, $service] = $this->makeRetryOrderAndService('ser1234567890', 'srv034315321');
        $provisionData = (array) $service->provision_data;
        unset($provisionData['upstream_host_id']);
        $service->forceFill(['provision_data' => $provisionData]);

        try {
            $provisionService->retryFailedProvision($order);
            $this->fail('预期应抛出上游购物车错误');
        } catch (BusinessException $exception) {
            $this->assertSame('加入上游购物车失败，请稍后重试', $exception->getMessage());
        }

        $this->assertSame('ser1234567890', (string) (($captured['payload']['host'] ?? '')));
        $this->assertSame('ser1234567890', (string) $service->domain);
        $this->assertSame(ServiceStatus::PENDING, (int) $service->status);
        $this->assertSame(OrderStatus::PROCESSING, (int) $order->status);
        $this->assertSame(
            '加入上游购物车失败，请稍后重试',
            (string) (($service->provision_data ?? [])['provision_error'] ?? '')
        );
        $this->assertFalse(array_key_exists('upstream_host_id', (array) ($service->provision_data ?? [])));
    }

    #[Test]
    public function paid_order_retry_does_not_create_a_second_upstream_cart_when_the_first_invoice_has_no_host_id(): void
    {
        $provisionService = new ProvisionService(
            $this->makeProviderResolver(new class extends HostingPanelApiTransport
            {
                public function login(Supplier $supplier): string
                {
                    throw new \RuntimeException('A pending upstream invoice must not open another cart.');
                }
            }),
            new class extends SettingService
            {
                public function getProvisionHostnameConfig(): array
                {
                    return [
                        'prefix' => 'ser',
                        'length' => 15,
                        'pool' => '0123456789',
                    ];
                }
            }
        );

        [$order, $service] = $this->makeRetryOrderAndService('ser1234567890', 'srv034315321');
        $provisionData = (array) $service->provision_data;
        unset($provisionData['upstream_host_id']);
        $provisionData['upstream_invoice_id'] = 7788;
        $service->forceFill(['provision_data' => $provisionData]);

        try {
            $provisionService->processPaidOrder($order);
            $this->fail('未取得上游实例 ID 的账单不得再次创建购物车。');
        } catch (BusinessException $exception) {
            $this->assertSame('已创建上游账单但未取得实例标识，请先核实上游账单状态后再处理，禁止直接重新下单', $exception->getMessage());
        }
    }

    #[Test]
    public function supplier_cart_lock_outlives_the_provision_worker_timeout(): void
    {
        config()->set('queue.turaidc_worker_timeout', 1200);

        $provisionService = new ProvisionService(
            new ProviderResolver(new ProviderRegistry([])),
            new class extends SettingService {},
        );

        $method = new \ReflectionMethod($provisionService, 'supplierCartLockTtl');
        $method->setAccessible(true);

        $this->assertSame(1260, $method->invoke($provisionService));
    }

    #[Test]
    public function supplier_cart_lock_is_shared_by_records_using_the_same_upstream_account(): void
    {
        $provisionService = new ProvisionService(
            new ProviderResolver(new ProviderRegistry([])),
            new class extends SettingService {},
        );
        $method = new \ReflectionMethod($provisionService, 'supplierCartLockKey');
        $method->setAccessible(true);

        $first = (new Supplier)->forceFill([
            'id' => 1001,
            'api_url' => 'HTTPS://zjmf.example.test/api/',
            'api_username' => 'Shared-Account',
        ]);
        $sameUpstreamAccount = (new Supplier)->forceFill([
            'id' => 1002,
            'api_url' => 'https://ZJMF.example.test/api',
            'api_username' => 'shared-account',
        ]);
        $differentUpstreamAccount = (new Supplier)->forceFill([
            'id' => 1003,
            'api_url' => 'https://zjmf.example.test/api',
            'api_username' => 'other-account',
        ]);
        $withoutRuntimeCredentials = (new Supplier)->forceFill([
            'id' => 1004,
        ]);

        $firstKey = (string) $method->invoke($provisionService, $first);

        $this->assertSame($firstKey, $method->invoke($provisionService, $sameUpstreamAccount));
        $this->assertNotSame($firstKey, $method->invoke($provisionService, $differentUpstreamAccount));
        $this->assertSame('lock:supplier:cart:1004', $method->invoke($provisionService, $withoutRuntimeCredentials));
        $this->assertStringStartsWith('lock:upstream:cart:', $firstKey);
        $this->assertStringNotContainsString('Shared-Account', $firstKey);
    }

    #[Test]
    public function ensure_local_service_uses_resolved_hostname_as_instance_name_with_auto_renew_disabled(): void
    {
        $provisionService = new ProvisionService(
            $this->makeProviderResolver(new class extends HostingPanelApiTransport
            {
                public function __construct() {}

                public function login(Supplier $supplier): string
                {
                    return 'jwt-test-token';
                }

                public function get(
                    Supplier $supplier,
                    string $uri,
                    ?string $jwt = null,
                    array $query = [],
                    array $headers = []
                ): array {
                    return ['data' => ['first_group' => []]];
                }
            }),
            new class extends SettingService
            {
                public function getProvisionHostnameConfig(): array
                {
                    return [
                        'prefix' => 'srv',
                        'length' => 12,
                        'pool' => '0123456789',
                    ];
                }
            }
        );

        $order = $this->makeOrder('ltser1234567890', 501);
        $order->forceFill([
            'amount' => 5.00,
            'product_spec_snapshot' => '通用NAT-2vcpu-1gib',
            'config_snapshot' => [
                'hostname' => 'ltser1234567890',
                'cpu' => '2',
                'memory' => '2048',
            ],
        ]);
        $order->setRelation('service', null);

        $service = $this->invokeEnsureLocalService($provisionService, $order);

        $this->assertSame('通用NAT-2vcpu-1gib', (string) $service->name);
        $this->assertSame('ltser1234567890', (string) $service->domain);
        $this->assertSame(0, (int) $service->auto_renew);
    }

    #[Test]
    public function services_database_default_disables_auto_renew(): void
    {
        $order = $this->makeOrder('ltser1234567890', 506);

        $service = Service::query()->create([
            'user_id' => $order->user_id,
            'product_id' => (int) $order->product_id,
            'name' => '默认续费状态测试',
            'domain' => '',
            'billing_cycle' => 'monthly',
            'amount' => '5.00',
            'status' => ServiceStatus::PENDING,
            'provision_data' => [],
        ]);

        $this->assertSame(0, (int) $service->auto_renew);
    }

    #[Test]
    public function process_paid_order_keeps_instance_spec_name_after_upstream_success(): void
    {
        $provisionService = new ProvisionService(
            $this->makeProviderResolver(new class extends HostingPanelApiTransport
            {
                public function __construct() {}

                public function login(Supplier $supplier): string
                {
                    return 'jwt-test-token';
                }

                public function get(
                    Supplier $supplier,
                    string $uri,
                    ?string $jwt = null,
                    array $query = [],
                    array $headers = []
                ): array {
                    if ($uri === '/v1/productsconfig') {
                        return ['data' => ['first_group' => []]];
                    }

                    if ($uri === '/v1/cart') {
                        return [
                            'status' => 200,
                            'data' => [
                                'default_gateway' => 'AliPay',
                                'gateway_list' => [
                                    ['name' => 'AliPay'],
                                ],
                            ],
                        ];
                    }

                    if (str_contains($uri, '/v1/hosts/')) {
                        return [
                            'status' => 200,
                            'data' => [
                                'host' => [
                                    'id' => 75831,
                                    'domain' => 'ser380376647391',
                                    'domainstatus' => 'Active',
                                    'product_id' => 831,
                                    'product_name' => '西安云电脑 A型',
                                    'os' => 'CentOS-7.6.1810-x64',
                                ],
                            ],
                        ];
                    }

                    return ['status' => 200, 'data' => []];
                }

                public function request(
                    Supplier $supplier,
                    string $method,
                    string $uri,
                    array|string $payload = [],
                    ?string $jwt = null,
                    array $headers = [],
                    array $query = []
                ): array {
                    if ($uri === '/v1/cart/products') {
                        return ['status' => 200, 'msg' => 'Added successfully'];
                    }

                    if ($uri === '/v1/cart/settle') {
                        return [
                            'status' => 200,
                            'data' => [
                                'cart_products' => [
                                    ['productid' => '831'],
                                ],
                            ],
                        ];
                    }

                    if ($uri === '/v1/cart/checkout') {
                        return [
                            'status' => 200,
                            'msg' => 'Successful purchase',
                            'data' => ['invoiceid' => 978109],
                        ];
                    }

                    if ($uri === '/v1/invoices/978109/fund') {
                        return [
                            'status' => 1001,
                            'msg' => 'Payment completed',
                            'data' => [
                                'hostid' => [75831],
                                'url' => 'servicedetail?id=75831',
                            ],
                        ];
                    }

                    return ['status' => 200, 'data' => []];
                }
            }),
            new class extends SettingService
            {
                public function getProvisionHostnameConfig(): array
                {
                    return [
                        'prefix' => 'ser',
                        'length' => 15,
                        'pool' => '0123456789',
                    ];
                }
            }
        );

        $order = $this->makeOrder('ser380376647391', 505);
        $order->forceFill([
            'id' => null,
            'type' => 'new',
            'status' => OrderStatus::PAID,
            'amount' => 5.00,
            'product_spec_snapshot' => '通用NAT-2vcpu-1gib',
            'config_snapshot' => [
                'hostname' => 'ser380376647391',
                'cpu' => '2',
                'memory' => '1024',
            ],
        ])->save();
        $order->exists = true;
        $order->setRelation('service', null);

        $service = $provisionService->processPaidOrder($order);

        $this->assertNotNull($service);
        $this->assertSame('通用NAT-2vcpu-1gib', (string) $service->name);
        $this->assertSame(ServiceStatus::ACTIVE, (int) $service->status);
        $this->assertSame(OrderStatus::COMPLETED, (int) $order->status);
    }

    #[Test]
    public function process_paid_order_recognizes_existing_upstream_binding_without_legacy_provision_data_host(): void
    {
        $this->activateIntegrationPluginForTest('upstream', 'zjmf_finance');

        $provisionService = new ProvisionService(
            $this->makeProviderResolver(new class extends HostingPanelApiTransport
            {
                public function login(Supplier $supplier): string
                {
                    throw new \RuntimeException('Upstream should not be called when service binding already exists.');
                }
            }, true),
            new class extends SettingService
            {
                public function getProvisionHostnameConfig(): array
                {
                    return [
                        'prefix' => 'ser',
                        'length' => 15,
                        'pool' => '0123456789',
                    ];
                }
            }
        );

        $order = $this->makeOrder('ser-binding-only', 506);
        $order->forceFill([
            'type' => 'new',
            'status' => OrderStatus::PAID,
        ]);
        $order->exists = true;
        $order->product->forceFill([
            'provision_module' => ProviderKey::ZJMF_FINANCE_API,
        ]);
        $order->product->supplier->forceFill([
            'interface_type' => ProviderKey::ZJMF_FINANCE_API,
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $order->user_id,
            'product_id' => (int) $order->product->id,
            'name' => 'Binding Only Service',
            'domain' => 'ser-binding-only',
            'billing_cycle' => 'monthly',
            'amount' => '5.00',
            'locked_pricing' => [],
            'status' => ServiceStatus::ACTIVE,
            'provision_data' => [
                'source_type' => 'upstream',
                'provider' => ProviderKey::ZJMF_FINANCE_API,
            ],
        ]);
        $order->setRelation('service', $service);

        $pluginId = (int) DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where('plugin_key', ProviderKey::ZJMF_FINANCE_API)
            ->value('id');
        $this->assertGreaterThan(0, $pluginId);

        DB::table('service_upstream_bindings')->insert([
            'service_id' => (int) $service->id,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'upstream_service_id' => '98765',
            'status_snapshot' => 'Active',
            'last_synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $result = $provisionService->processPaidOrder($order);

        $this->assertSame((int) $service->id, (int) $result?->id);
        $this->assertSame(OrderStatus::COMPLETED, (int) $order->status);
    }

    private function makeOrder(
        string $snapshotHostname,
        int $productId = 501,
        array $purchaseRequires = [
            'provision_hostname' => [
                'mode' => 'system',
            ],
        ]
    ): Order {
        $suffix = bin2hex(random_bytes(4));

        $supplier = Supplier::query()->create([
            'code' => 'test-zjmf-'.$suffix,
            'name' => '测试供应商-'.$suffix,
            'interface_type' => ProviderKey::ZJMF_FINANCE_API,
            'status' => 1,
            'sort_order' => 0,
        ]);

        $user = User::query()->create([
            'email' => 'provision-hostname-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Provision Hostname',
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
            'id' => $productId,
            'custom_display_name' => '测试云电脑-'.$suffix,
            'product_type' => 'vps',
            'remark' => '西安云电脑',
            'supplier_id' => (int) $supplier->id,
            'supplier_product_id' => 9001,
            'auto_setup' => 1,
            'provision_module' => ProviderKey::ZJMF_FINANCE_API,
            'pricing' => ['monthly' => '5.00'],
            'setup_fee' => '0.00',
            'purchase_requires' => $purchaseRequires,
            'stock' => -1,
            'status' => 1,
            'config_options' => [
                [
                    'field' => 'cpu',
                    'option_type' => 6,
                    'sub' => [
                        ['id' => '2', 'option_name' => '2核'],
                    ],
                ],
                [
                    'field' => 'memory',
                    'option_type' => 8,
                    'sub' => [
                        ['id' => '2048', 'option_name' => '2G'],
                    ],
                ],
            ],
        ]);
        $product->setRelation('supplier', $supplier);
        $this->bindProductToZjmf($supplier, $product, 9001);
        $actualProductId = (int) $product->id;

        $order = new class extends Order
        {
            public function save(array $options = []): bool
            {
                $this->syncOriginal();

                return true;
            }
        };

        $order->forceFill([
            'id' => 3001,
            'order_no' => 'ORD20260404000001TEST',
            'user_id' => (int) $user->id,
            'type' => 'new',
            'product_id' => $actualProductId,
            'billing_cycle' => 'monthly',
            'amount' => 5.00,
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'status' => OrderStatus::PENDING,
            'product_spec_snapshot' => '通用NAT-2vcpu-1gib',
            'config_snapshot' => [
                'hostname' => $snapshotHostname,
            ],
        ]);
        $order->setRelation('product', $product);

        DB::table('orders')->updateOrInsert(['id' => 3001], [
            'order_no' => 'ORD20260404000001TEST',
            'user_id' => (int) $user->id,
            'type' => 'new',
            'product_id' => $actualProductId,
            'billing_cycle' => 'monthly',
            'amount' => '5.00',
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'quantity' => 1,
            'status' => OrderStatus::PENDING,
            'product_spec_snapshot' => '通用NAT-2vcpu-1gib',
            'config_snapshot' => json_encode(['hostname' => $snapshotHostname], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $order;
    }

    private function makeRetryOrderAndService(string $snapshotHostname, string $serviceDomain): array
    {
        $suffix = bin2hex(random_bytes(4));

        $supplier = Supplier::query()->create([
            'code' => 'retry-zjmf-'.$suffix,
            'name' => '重试供应商-'.$suffix,
            'interface_type' => ProviderKey::ZJMF_FINANCE_API,
            'status' => 1,
            'sort_order' => 0,
        ]);

        $product = Product::query()->create([
            'custom_display_name' => '成都云电脑 2H2G',
            'product_type' => 'vps',
            'supplier_id' => (int) $supplier->id,
            'supplier_product_id' => 665,
            'auto_setup' => 1,
            'provision_module' => ProviderKey::ZJMF_FINANCE_API,
            'pricing' => ['monthly' => '99.00'],
            'setup_fee' => '0.00',
            'purchase_requires' => [
                'provision_hostname' => [
                    'mode' => 'system',
                ],
            ],
            'config_options' => [],
            'stock' => -1,
            'status' => 1,
        ]);
        $product->setRelation('supplier', $supplier);
        $this->bindProductToZjmf($supplier, $product, 665);

        $order = new class extends Order
        {
            public function save(array $options = []): bool
            {
                $this->syncOriginal();

                return true;
            }
        };

        $order->forceFill([
            'id' => 4001,
            'order_no' => 'ORD20260404000002RETRY',
            'type' => 'new',
            'user_id' => 8001,
            'product_id' => (int) $product->id,
            'service_id' => 5001,
            'billing_cycle' => 'monthly',
            'amount' => 99.00,
            'status' => OrderStatus::PROCESSING,
            'config_snapshot' => [
                'hostname' => $snapshotHostname,
                'password' => 'TestPass123',
            ],
        ]);
        $order->exists = true;
        $order->setRelation('product', $product);

        $service = new class extends Service
        {
            public function save(array $options = []): bool
            {
                $this->syncOriginal();

                return true;
            }
        };

        $service->forceFill([
            'id' => 5001,
            'user_id' => 8001,
            'product_id' => (int) $product->id,
            'order_id' => 4001,
            'name' => '成都云电脑 2H2G',
            'domain' => $serviceDomain,
            'billing_cycle' => 'monthly',
            'amount' => 99.00,
            'status' => ServiceStatus::PENDING,
            'provision_data' => [
                'provision_error' => '旧的上游开通失败记录',
                'requested_config' => [
                    'hostname' => $snapshotHostname,
                    'password' => '***',
                ],
                'upstream_host_id' => 9999,
            ],
        ]);
        $service->exists = true;
        $service->setRelation('product', $product);
        $service->setRelation('order', $order);

        $order->setRelation('service', $service);

        return [$order, $service];
    }

    private function invokeResolveProvisionHostname(ProvisionService $service, Order $order): string
    {
        $method = new \ReflectionMethod($service, 'resolveProvisionHostname');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $order);
    }

    private function invokeResolveUpstreamProvisionHostnameRule(ProvisionService $service, Order $order): array
    {
        $method = new \ReflectionMethod($service, 'resolveUpstreamProvisionHostnameRule');
        $method->setAccessible(true);

        return (array) $method->invoke($service, $order);
    }

    private function invokeEnsureLocalService(ProvisionService $service, Order $order): Service
    {
        $method = new \ReflectionMethod($service, 'ensureLocalService');
        $method->setAccessible(true);

        return $method->invoke($service, $order);
    }

    private function makeProviderResolver(HostingPanelApiTransport $transport, bool $includeZjmf = true): ProviderResolver
    {
        $drivers = [];

        if ($includeZjmf) {
            $drivers[] = new class($transport) implements UpstreamDriver
            {
                public function __construct(private readonly HostingPanelApiTransport $transport) {}

                public function key(): string
                {
                    return ProviderKey::ZJMF_FINANCE_API;
                }

                public function label(): string
                {
                    return 'ZJMF 财务接口';
                }

                public function capabilities(): array
                {
                    return [ProvidesProvisioning::class];
                }

                public function supports(string $capability): bool
                {
                    return $capability === ProvidesProvisioning::class
                        && $this->transport instanceof ProvidesProvisioning;
                }

                public function resolve(string $capability): ?object
                {
                    return $this->supports($capability) ? $this->transport : null;
                }
            };
        }

        return new ProviderResolver(new ProviderRegistry($drivers));
    }

    private function bindProductToZjmf(Supplier $supplier, Product $product, int|string $upstreamProductId): void
    {
        $pluginId = (int) DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where('plugin_key', ProviderKey::ZJMF_FINANCE_API)
            ->value('id');

        $this->assertGreaterThan(0, $pluginId);

        DB::table('supplier_plugin_bindings')->updateOrInsert([
            'supplier_id' => (int) $supplier->id,
            'plugin_id' => $pluginId,
            'environment' => 'production',
        ], [
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'status' => 1,
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $supplierBindingId = (int) DB::table('supplier_plugin_bindings')
            ->where('supplier_id', (int) $supplier->id)
            ->where('plugin_id', $pluginId)
            ->where('environment', 'production')
            ->value('id');

        DB::table('product_upstream_bindings')->updateOrInsert([
            'product_id' => (int) $product->id,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'upstream_product_id' => (string) $upstreamProductId,
        ], [
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'auto_setup' => 1,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function buildUpstreamProductConfigResponse(): array
    {
        return [
            'data' => [
                'first_group' => [
                    [
                        'group' => [
                            [
                                'products' => [
                                    [
                                        'id' => 9001,
                                        'host' => [
                                            'show' => 1,
                                            'prefix' => 'ser',
                                            'len_num' => 13,
                                            'num' => 1,
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function buildSingleProductUpstreamProductConfigResponse(): array
    {
        return [
            'data' => [
                'products' => [
                    'id' => 9001,
                    'host' => json_encode([
                        'show' => 0,
                        'prefix' => 'ser',
                        'rule' => [
                            'num' => 1,
                            'len_num' => 12,
                        ],
                    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ],
            ],
        ];
    }

    private function buildTopLevelProductRuleUpstreamProductConfigResponse(): array
    {
        return [
            'data' => [
                'product' => [
                    'id' => 9001,
                    'host' => [
                        'show' => 0,
                        'prefix' => 'ser',
                    ],
                    'rule' => [
                        'num' => 1,
                        'len_num' => 12,
                    ],
                ],
            ],
        ];
    }
}
