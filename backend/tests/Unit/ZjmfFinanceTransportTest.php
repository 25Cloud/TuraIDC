<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfAuthManager;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfCatalogService;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfCloudConfigTemplate;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfFinanceTransport;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ZjmfFinanceTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(PluginFileLoader::class)->ensureLoaded(
            app(PluginScanner::class)->requireManifest('upstream', 'zjmf_finance')
        );
    }

    public function test_auth_manager_caches_jwt_under_zjmf_provider_key(): void
    {
        config(['idc.hosting_panel_api.jwt_cache_store' => 'array']);

        $supplier = (new Supplier)->forceFill([
            'id' => 321,
            'interface_type' => 'zjmf_finance_api',
            'api_url' => 'https://zjmf.example.test',
            'api_username' => 'demo',
            'api_key' => 'secret',
        ]);

        $transport = new class extends HostingPanelApiTransport
        {
            public array $captured = [];

            public function request(
                Supplier $supplier,
                string $method,
                string $uri,
                array|string $payload = [],
                ?string $jwt = null,
                array $headers = [],
                array $query = []
            ): array {
                $this->captured[] = compact('method', 'uri', 'payload', 'jwt', 'headers', 'query');

                return ['status' => 200, 'jwt' => 'zjmf-plugin-jwt'];
            }
        };

        $auth = new ZjmfAuthManager($transport);
        $jwt = $auth->login($supplier);

        $this->assertSame('zjmf-plugin-jwt', $jwt);
        $this->assertSame('zjmf-plugin-jwt', Cache::store('array')->get($auth->jwtCacheKey($supplier)));
        $this->assertSame('/zjmf_api_login', $transport->captured[0]['uri']);
        $this->assertSame('demo', $transport->captured[0]['payload']['username']);
        $this->assertSame('secret', $transport->captured[0]['payload']['password']);
    }

    public function test_transport_forgets_zjmf_jwt_cache_on_unauthorized_response(): void
    {
        config(['idc.hosting_panel_api.jwt_cache_store' => 'array']);

        $supplier = (new Supplier)->forceFill([
            'id' => 654,
            'interface_type' => 'zjmf_finance_api',
            'api_url' => 'https://zjmf.example.test',
            'api_username' => 'demo',
            'api_key' => 'secret',
        ]);

        $legacyTransport = new class extends HostingPanelApiTransport
        {
            public function request(
                Supplier $supplier,
                string $method,
                string $uri,
                array|string $payload = [],
                ?string $jwt = null,
                array $headers = [],
                array $query = []
            ): array {
                return ['status' => 401, 'data' => []];
            }
        };

        $auth = new ZjmfAuthManager($legacyTransport);
        Cache::store('array')->put($auth->jwtCacheKey($supplier), 'stale-jwt', now()->addMinutes(5));

        $transport = new ZjmfFinanceTransport($legacyTransport, $auth);
        $transport->get($supplier, '/v1/user', 'stale-jwt');

        $this->assertNull(Cache::store('array')->get($auth->jwtCacheKey($supplier)));
    }

    public function test_transport_sends_zjmf_jwt_as_bearer_authorization(): void
    {
        $supplier = (new Supplier)->forceFill([
            'id' => 655,
            'interface_type' => 'zjmf_finance_api',
            'api_url' => 'https://zjmf.example.test',
        ]);

        $innerTransport = new class extends HostingPanelApiTransport
        {
            public array $captured = [];

            public function request(
                Supplier $supplier,
                string $method,
                string $uri,
                array|string $payload = [],
                ?string $jwt = null,
                array $headers = [],
                array $query = []
            ): array {
                $this->captured[] = compact('method', 'uri', 'payload', 'jwt', 'headers', 'query');

                return ['status' => 200, 'data' => []];
            }
        };

        $transport = new ZjmfFinanceTransport($innerTransport, new ZjmfAuthManager($innerTransport));
        $transport->get($supplier, '/cart/index', 'zjmf-jwt');

        $this->assertSame('zjmf-jwt', $innerTransport->captured[0]['jwt']);
        $this->assertSame(['Authorization: Bearer zjmf-jwt'], $innerTransport->captured[0]['headers']);
    }

    public function test_parallel_transport_sends_zjmf_jwt_as_bearer_authorization(): void
    {
        $supplier = (new Supplier)->forceFill([
            'id' => 660,
            'interface_type' => 'zjmf_finance_api',
            'api_url' => 'https://zjmf.example.test',
        ]);

        $innerTransport = new class extends HostingPanelApiTransport
        {
            public array $captured = [];

            public function parallelGet(Supplier $supplier, array $requests, ?string $jwt = null, array $headers = []): array
            {
                $this->captured[] = compact('requests', 'jwt', 'headers');

                return [];
            }
        };

        $transport = new ZjmfFinanceTransport($innerTransport, new ZjmfAuthManager($innerTransport));
        $transport->parallelGet($supplier, ['product' => ['uri' => '/cart/get_product_config']], 'zjmf-jwt');

        $this->assertSame('zjmf-jwt', $innerTransport->captured[0]['jwt']);
        $this->assertSame(['Authorization: Bearer zjmf-jwt'], $innerTransport->captured[0]['headers']);
    }

    public function test_balance_reads_credit_from_cart_credit(): void
    {
        config(['idc.hosting_panel_api.jwt_cache_store' => 'array']);

        $supplier = (new Supplier)->forceFill([
            'id' => 656,
            'interface_type' => 'zjmf_finance_api',
            'api_url' => 'https://zjmf.example.test',
            'api_username' => 'demo',
            'api_key' => 'secret',
        ]);

        $transport = new class extends HostingPanelApiTransport
        {
            public array $captured = [];

            public function request(
                Supplier $supplier,
                string $method,
                string $uri,
                array|string $payload = [],
                ?string $jwt = null,
                array $headers = [],
                array $query = []
            ): array {
                $this->captured[] = compact('method', 'uri', 'payload', 'jwt', 'headers', 'query');

                return match ($uri) {
                    '/zjmf_api_login' => ['status' => 200, 'jwt' => 'zjmf-jwt'],
                    '/cart/credit' => [
                        'status' => 200,
                        'data' => [
                            'credit' => '100.19',
                            'currency' => ['prefix' => '¥', 'suffix' => 'CNY'],
                        ],
                    ],
                    default => ['status' => 400, 'msg' => 'unexpected uri'],
                };
            }
        };

        $client = new ZjmfFinanceTransport($transport, new ZjmfAuthManager($transport));
        $result = $client->getBalance($supplier);

        $this->assertSame('100.19', $result['balance']);
        $this->assertSame('¥', $result['currency']['prefix']);
        $this->assertSame('/zjmf_api_login', $transport->captured[0]['uri']);
        $this->assertSame('/cart/credit', $transport->captured[1]['uri']);
        $this->assertSame('zjmf-jwt', $transport->captured[1]['jwt']);
        $this->assertSame(['Authorization: Bearer zjmf-jwt'], $transport->captured[1]['headers']);
    }

    public function test_host_detail_uses_same_system_header_endpoint_and_normalizes_payload(): void
    {
        config(['idc.hosting_panel_api.jwt_cache_store' => 'array']);

        $supplier = (new Supplier)->forceFill([
            'id' => 659,
            'interface_type' => 'zjmf_finance_api',
            'api_url' => 'https://zjmf.example.test',
            'api_username' => 'demo',
            'api_key' => 'secret',
        ]);

        $innerTransport = new class extends HostingPanelApiTransport
        {
            public array $captured = [];

            public function request(
                Supplier $supplier,
                string $method,
                string $uri,
                array|string $payload = [],
                ?string $jwt = null,
                array $headers = [],
                array $query = []
            ): array {
                $this->captured[] = compact('method', 'uri', 'payload', 'jwt', 'headers', 'query');

                return match ($uri) {
                    '/zjmf_api_login' => ['status' => 200, 'jwt' => 'zjmf-jwt'],
                    '/host/header' => [
                        'status' => 200,
                        'data' => [
                            'host_data' => [
                                'id' => 84396,
                                'productid' => 453,
                                'productname' => '美国1区精品网 2H2G',
                                'domainstatus' => 'Active',
                                'dedicatedip' => '203.0.113.10',
                            ],
                            'config_options' => [[
                                'id' => 1,
                                'name_k' => 'area',
                                'name' => '区域',
                                'option_type' => 12,
                                'sub_name' => '美国',
                                'code' => 'us',
                            ]],
                        ],
                    ],
                    default => ['status' => 400, 'msg' => 'unexpected uri'],
                };
            }
        };

        $transport = new ZjmfFinanceTransport($innerTransport, new ZjmfAuthManager($innerTransport));
        $response = $transport->getHostDetail($supplier, 84396);

        $this->assertCount(2, $innerTransport->captured);
        $this->assertSame('GET', $innerTransport->captured[1]['method']);
        $this->assertSame('/host/header', $innerTransport->captured[1]['uri']);
        $this->assertSame([], $innerTransport->captured[1]['payload']);
        $this->assertSame('zjmf-jwt', $innerTransport->captured[1]['jwt']);
        $this->assertSame(['host_id' => 84396, 'source' => 'API'], $innerTransport->captured[1]['query']);
        $this->assertSame(453, $response['data']['host']['product_id']);
        $this->assertSame('美国1区精品网 2H2G', $response['data']['host']['product_name']);
        $this->assertSame('美国', $response['data']['host']['config_option'][0]['value']);
    }

    public function test_catalog_reads_cart_all_with_zjmf_jwt(): void
    {
        config(['idc.hosting_panel_api.jwt_cache_store' => 'array']);

        $supplier = (new Supplier)->forceFill([
            'id' => 657,
            'interface_type' => 'zjmf_finance_api',
            'api_url' => 'https://zjmf.example.test',
            'api_username' => 'demo',
            'api_key' => 'secret',
        ]);

        $innerTransport = new class extends HostingPanelApiTransport
        {
            public array $captured = [];

            public function request(
                Supplier $supplier,
                string $method,
                string $uri,
                array|string $payload = [],
                ?string $jwt = null,
                array $headers = [],
                array $query = []
            ): array {
                $this->captured[] = compact('method', 'uri', 'payload', 'jwt', 'headers', 'query');

                return match ($uri) {
                    '/zjmf_api_login' => ['status' => 200, 'jwt' => 'zjmf-jwt'],
                    '/cart/all' => [
                        'status' => 200,
                        'data' => [
                            'products' => [[
                                'name' => 'United States',
                                'products' => [[
                                    'name' => 'CN2',
                                    'products' => [[
                                        'id' => 453,
                                        'name' => 'Demo cart product',
                                        'type' => 'dcimcloud',
                                    ]],
                                ]],
                            ]],
                        ],
                    ],
                    default => ['status' => 400, 'msg' => 'unexpected uri'],
                };
            }
        };

        $transport = new ZjmfFinanceTransport($innerTransport, new ZjmfAuthManager($innerTransport));
        $catalog = new ZjmfCatalogService($transport, new ZjmfCloudConfigTemplate);

        $result = $catalog->getProductCatalog($supplier);

        $this->assertCount(2, $innerTransport->captured);
        $this->assertSame('POST', $innerTransport->captured[0]['method']);
        $this->assertSame('/zjmf_api_login', $innerTransport->captured[0]['uri']);
        $this->assertSame('demo', $innerTransport->captured[0]['payload']['username']);
        $this->assertSame('GET', $innerTransport->captured[1]['method']);
        $this->assertSame('/cart/all', $innerTransport->captured[1]['uri']);
        $this->assertSame('zjmf-jwt', $innerTransport->captured[1]['jwt']);
        $this->assertSame(453, $result['products'][0]['id']);
        $this->assertSame('United States / CN2', $result['products'][0]['group_label']);
    }

    public function test_product_config_uses_cart_endpoint_with_pid_and_zjmf_jwt(): void
    {
        config(['idc.hosting_panel_api.jwt_cache_store' => 'array']);

        $supplier = (new Supplier)->forceFill([
            'id' => 658,
            'interface_type' => 'zjmf_finance_api',
            'api_url' => 'https://zjmf.example.test',
            'api_username' => 'demo',
            'api_key' => 'secret',
        ]);

        $innerTransport = new class extends HostingPanelApiTransport
        {
            public array $captured = [];

            public function request(
                Supplier $supplier,
                string $method,
                string $uri,
                array|string $payload = [],
                ?string $jwt = null,
                array $headers = [],
                array $query = []
            ): array {
                $this->captured[] = compact('method', 'uri', 'payload', 'jwt', 'headers', 'query');

                return match ($uri) {
                    '/zjmf_api_login' => ['status' => 200, 'jwt' => 'zjmf-jwt'],
                    '/cart/get_product_config' => [
                        'status' => 200,
                        'data' => [
                            'config_groups' => [[
                                'options' => [[
                                    'id' => 1,
                                    'option_name' => 'cpu|CPU',
                                    'option_type' => 6,
                                ]],
                            ]],
                        ],
                    ],
                    default => ['status' => 400, 'msg' => 'unexpected uri'],
                };
            }
        };

        $transport = new ZjmfFinanceTransport($innerTransport, new ZjmfAuthManager($innerTransport));
        $catalog = new ZjmfCatalogService($transport, new ZjmfCloudConfigTemplate);

        $result = $catalog->fetchRealConfigOptions($supplier, 453);

        $this->assertSame('cpu', $result[0]['field']);
        $this->assertCount(2, $innerTransport->captured);
        $this->assertSame('/cart/get_product_config', $innerTransport->captured[1]['uri']);
        $this->assertSame(['pid' => 453], $innerTransport->captured[1]['query']);
        $this->assertSame('zjmf-jwt', $innerTransport->captured[1]['jwt']);
    }
}
