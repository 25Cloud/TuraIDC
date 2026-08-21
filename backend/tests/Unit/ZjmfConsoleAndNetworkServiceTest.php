<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exceptions\BusinessException;
use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use Mockery;
use Tests\TestCase;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfAuthManager;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfCloudConfigTemplate;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfConsoleService;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfFinanceAdapter;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfFinanceTransport;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfNetworkService;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfSecurityService;

class ZjmfConsoleAndNetworkServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(PluginFileLoader::class)->ensureLoaded(
            app(PluginScanner::class)->requireManifest('upstream', 'zjmf_finance')
        );
    }

    public function test_console_service_requests_vnc_through_plugin_transport(): void
    {
        $supplier = $this->makeSupplier();
        $hostingTransport = Mockery::mock(HostingPanelApiTransport::class);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->with(
                $supplier,
                'POST',
                '/provision/default',
                ['id' => 123, 'func' => 'vnc'],
                'jwt-token',
                ['content-type: application/x-www-form-urlencoded', 'Authorization: Bearer jwt-token'],
                []
            )
            ->andReturn(['status' => 200, 'data' => ['url' => 'wss://vnc.example/ws']]);

        $service = new ZjmfConsoleService($this->makeTransport($hostingTransport));

        $response = $service->getVncUrl($supplier, 123, 'jwt-token');

        $this->assertSame('wss://vnc.example/ws', $response['data']['url']);
    }

    public function test_console_service_posts_power_actions_and_status_as_form_data(): void
    {
        $supplier = $this->makeSupplier();
        $hostingTransport = Mockery::mock(HostingPanelApiTransport::class);
        $actions = ['on', 'off', 'reboot', 'hard_off', 'hard_reboot'];

        foreach ($actions as $action) {
            $hostingTransport
                ->shouldReceive('request')
                ->once()
                ->with(
                    $supplier,
                    'POST',
                    '/provision/default',
                    ['id' => 123, 'func' => $action],
                    'jwt-token',
                    ['content-type: application/x-www-form-urlencoded', 'Authorization: Bearer jwt-token'],
                    []
                )
                ->andReturn(['status' => 200, 'data' => ['action' => $action]]);
        }

        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->with(
                $supplier,
                'POST',
                '/provision/default',
                ['id' => 123, 'func' => 'status'],
                'jwt-token',
                ['content-type: application/x-www-form-urlencoded', 'Authorization: Bearer jwt-token'],
                []
            )
            ->andReturn(['status' => 200, 'data' => ['status' => 'on', 'des' => '开机']]);

        $service = new ZjmfConsoleService($this->makeTransport($hostingTransport));

        foreach ($actions as $action) {
            $this->assertSame($action, $service->powerAction($supplier, 123, $action, 'jwt-token')['data']['action']);
        }

        $status = $service->getModuleStatus($supplier, 123, 'reinstall', 'jwt-token');

        $this->assertSame(['status' => 'on', 'des' => '开机'], $status['data']);
    }

    public function test_console_service_posts_password_reset_and_reinstall_as_form_data(): void
    {
        $supplier = $this->makeSupplier();
        $hostingTransport = Mockery::mock(HostingPanelApiTransport::class);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->with(
                $supplier,
                'POST',
                '/provision/default',
                ['id' => 123, 'func' => 'crack_pass', 'password' => 'NewPassw0rd!'],
                'jwt-token',
                ['content-type: application/x-www-form-urlencoded', 'Authorization: Bearer jwt-token'],
                []
            )
            ->andReturn(['status' => 200, 'msg' => '密码重置指令已提交']);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->with(
                $supplier,
                'POST',
                '/provision/default',
                ['id' => 123, 'func' => 'reinstall', 'os' => '203'],
                'jwt-token',
                ['content-type: application/x-www-form-urlencoded', 'Authorization: Bearer jwt-token'],
                []
            )
            ->andReturn(['status' => 200, 'msg' => '重装任务已提交']);

        $service = new ZjmfConsoleService($this->makeTransport($hostingTransport));

        $this->assertSame(200, $service->resetPassword($supplier, 123, 'NewPassw0rd!', 'jwt-token')['status']);
        $this->assertSame(200, $service->reinstall($supplier, 123, '203', 'jwt-token')['status']);
    }

    public function test_console_service_builds_reinstall_options_from_host_header(): void
    {
        $supplier = $this->makeSupplier();
        $hostingTransport = Mockery::mock(HostingPanelApiTransport::class);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->with($supplier, 'GET', '/host/header', [], 'jwt-token', ['Authorization: Bearer jwt-token'], ['host_id' => 123, 'source' => 'API'])
            ->andReturn([
                'status' => 200,
                'data' => [
                    'host_data' => ['id' => 123],
                    'cloud_os_group' => [
                        ['id' => 10, 'name' => 'Linux', 'img' => '/linux.svg'],
                        ['id' => 20, 'name' => 'Windows'],
                    ],
                    'cloud_os' => [
                        ['id' => 101, 'name' => 'Ubuntu 22.04', 'group' => 10],
                        ['id' => 202, 'name' => 'Windows Server 2022', 'group' => 20],
                    ],
                ],
            ]);

        $service = new ZjmfConsoleService($this->makeTransport($hostingTransport));
        $response = $service->getReinstallOptions($supplier, 123, 'jwt-token');

        $this->assertSame([
            'os' => [
                ['os_id' => '101', 'name' => 'Ubuntu 22.04', 'group_name' => 'Linux'],
                ['os_id' => '202', 'name' => 'Windows Server 2022', 'group_name' => 'Windows'],
            ],
            'os_group' => [
                ['group_name' => 'Linux', 'img' => '/linux.svg'],
                ['group_name' => 'Windows', 'img' => ''],
            ],
        ], $response['data']);
    }

    public function test_console_service_builds_supported_modules_from_host_header(): void
    {
        $supplier = $this->makeSupplier();
        $hostingTransport = Mockery::mock(HostingPanelApiTransport::class);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->with($supplier, 'GET', '/host/header', [], 'jwt-token', ['Authorization: Bearer jwt-token'], ['host_id' => 123, 'source' => 'API'])
            ->andReturn([
                'status' => 200,
                'data' => [
                    'module_button' => [
                        'control' => [
                            ['func' => 'on', 'type' => 'default', 'name' => '开机'],
                            ['func' => 'reinstall', 'type' => 'default', 'name' => '重装系统'],
                        ],
                        'console' => [
                            ['func' => 'vnc', 'type' => 'default', 'name' => 'VNC'],
                        ],
                    ],
                    'module_client_area' => [
                        ['key' => 'security_groups', 'name' => '安全组'],
                    ],
                ],
            ]);

        $service = new ZjmfConsoleService($this->makeTransport($hostingTransport));
        $response = $service->getSupportedModules($supplier, 123, 'jwt-token');

        $this->assertSame([
            ['type' => 'default', 'function' => 'on', 'name' => '开机', 'select' => 'control'],
            ['type' => 'default', 'function' => 'reinstall', 'name' => '重装系统', 'select' => 'control'],
            ['type' => 'default', 'function' => 'vnc', 'name' => 'VNC', 'select' => 'console'],
            ['type' => 'custom', 'function' => 'security_groups', 'name' => '安全组', 'select' => 'client_area'],
        ], $response['data']);
    }

    public function test_console_service_uses_provision_chart_endpoint_with_native_series_payload(): void
    {
        $supplier = $this->makeSupplier();
        $hostingTransport = Mockery::mock(HostingPanelApiTransport::class);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->with(
                $supplier,
                'GET',
                '/provision/chart/123',
                [],
                'jwt-token',
                ['Authorization: Bearer jwt-token'],
                ['id' => 123, 'type' => 'flow', 'start' => 1710000000000, 'end' => 1710086400000],
            )
            ->andReturn([
                'status' => 200,
                'data' => [
                    'chart_type' => 'line',
                    'label' => ['流入', '流出'],
                    'unit' => 'Mbps',
                    'list' => [
                        [['time' => '2024-03-09 16:00:00', 'value' => 12.5]],
                        [['time' => '2024-03-09 16:00:00', 'value' => 4.25]],
                    ],
                ],
            ]);

        $service = new ZjmfConsoleService($this->makeTransport($hostingTransport));
        $response = $service->getMonitorChart($supplier, 123, [
            'type' => 'flow',
            'start' => 1710000000000,
            'end' => 1710086400000,
        ], 'jwt-token');

        $this->assertSame(['流入', '流出'], $response['data']['label']);
        $this->assertSame('Mbps', $response['data']['unit']);
        $this->assertSame(12.5, $response['data']['list'][0][0]['value']);
        $this->assertSame(4.25, $response['data']['list'][1][0]['value']);
    }

    public function test_adapter_exposes_same_system_batch_monitor_requests(): void
    {
        $supplier = $this->makeSupplier();
        $hostingTransport = new class extends HostingPanelApiTransport
        {
            public array $requests = [];

            public function parallelGet(Supplier $supplier, array $requests, ?string $jwt = null, array $headers = []): array
            {
                $this->requests = $requests;

                return [
                    'cpu' => [
                        'status_code' => 200,
                        'response' => [
                            'status' => 200,
                            'data' => [
                                'chart_type' => 'line',
                                'label' => ['CPU'],
                                'unit' => '%',
                                'list' => [[['time' => '2024-03-09 16:00:00', 'value' => 42]]],
                            ],
                        ],
                    ],
                ];
            }
        };
        $transport = $this->makeTransport($hostingTransport);
        $console = new ZjmfConsoleService($transport);
        $adapter = new ZjmfFinanceAdapter(
            $hostingTransport,
            new ZjmfCloudConfigTemplate,
            zjmfTransport: $transport,
            consoleService: $console,
        );

        $responses = $adapter->getMonitorCharts($supplier, 123, [
            'cpu' => ['type' => 'cpu', 'start' => 1710000000000, 'end' => 1710086400000],
        ], 'jwt-token');

        $this->assertSame('/provision/chart/123', $hostingTransport->requests['cpu']['uri'] ?? null);
        $this->assertSame(['id' => 123, 'type' => 'cpu', 'start' => 1710000000000, 'end' => 1710086400000], $hostingTransport->requests['cpu']['query'] ?? null);
        $this->assertSame(42, $responses['cpu']['response']['data']['list'][0][0]['value'] ?? null);
    }

    public function test_security_service_submits_same_system_custom_module_form_actions(): void
    {
        $supplier = $this->makeSupplier();
        $hostingTransport = Mockery::mock(HostingPanelApiTransport::class);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->with(
                $supplier,
                'POST',
                'https://upstream.example/provision/custom/123',
                ['func' => 'linkSecurityGroup', 'id' => 44],
                'jwt-token',
                ['content-type: application/x-www-form-urlencoded', 'Authorization: JWT jwt-token'],
                [],
            )
            ->andReturn(['status' => 200, 'msg' => '操作成功']);

        $service = new ZjmfSecurityService($this->makeTransport($hostingTransport));
        $response = $service->submitCustomModuleAction(
            $supplier,
            'https://upstream.example/provision/custom/123',
            ['func' => 'linkSecurityGroup', 'id' => 44],
            'jwt-token',
        );

        $this->assertSame(200, $response['status']);
    }

    public function test_adapter_uses_same_system_custom_module_security_actions(): void
    {
        $supplier = $this->makeSupplier();
        $hostingTransport = Mockery::mock(HostingPanelApiTransport::class);
        $adapter = new ZjmfFinanceAdapter($hostingTransport, new ZjmfCloudConfigTemplate);

        $this->assertTrue(method_exists($adapter, 'submitCustomModuleAction'));
        $this->assertSame('https://upstream.example/provision/custom/123', $adapter->getCustomModuleActionEndpoint($supplier, 123));
        $this->assertFalse(method_exists($adapter, 'getSecurityGroups'));
        $this->assertFalse(method_exists($adapter, 'applySecurityGroup'));
    }

    public function test_security_service_rejects_non_custom_module_endpoint(): void
    {
        $service = new ZjmfSecurityService($this->makeTransport(Mockery::mock(HostingPanelApiTransport::class)));

        $this->expectException(BusinessException::class);
        $service->submitCustomModuleAction($this->makeSupplier(), '/v1/security-groups', [], 'jwt-token');
    }

    public function test_security_service_rejects_custom_module_endpoint_without_host_id(): void
    {
        $service = new ZjmfSecurityService($this->makeTransport(Mockery::mock(HostingPanelApiTransport::class)));

        $this->expectException(BusinessException::class);
        $service->submitCustomModuleAction($this->makeSupplier(), '/provision/custom/security', [], 'jwt-token');
    }

    public function test_security_service_rejects_cross_origin_custom_module_endpoint(): void
    {
        $service = new ZjmfSecurityService($this->makeTransport(Mockery::mock(HostingPanelApiTransport::class)));

        $this->expectException(BusinessException::class);
        $service->submitCustomModuleAction($this->makeSupplier(), 'https://unexpected.example/provision/custom/123', [], 'jwt-token');
    }

    public function test_console_service_fetches_custom_module_page_from_same_system_entry(): void
    {
        $supplier = $this->makeSupplier();
        $hostingTransport = Mockery::mock(HostingPanelApiTransport::class);
        $hostingTransport
            ->shouldReceive('requestText')
            ->once()
            ->with(
                $supplier,
                'GET',
                'https://upstream.example/provision/custom/content',
                [],
                'jwt-token',
                ['Authorization: JWT jwt-token'],
                ['id' => 123, 'key' => 'nat_acl', 'jwt' => 'jwt-token']
            )
            ->andReturn('{"status":200,"data":{"html":"<section>NAT</section>"}}');

        $service = new ZjmfConsoleService($this->makeTransport($hostingTransport));

        $this->assertSame('<section>NAT</section>', $service->fetchCustomModulePage($supplier, 123, 'nat_acl', 'jwt-token'));
    }

    public function test_network_service_purchases_traffic_package_as_high_level_plugin_action(): void
    {
        $supplier = $this->makeSupplier();
        $hostingTransport = Mockery::mock(HostingPanelApiTransport::class);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->with($supplier, 'POST', '/v1/hosts/123/actions/upgradeconfig', ['configoption' => ['11' => 22]], 'jwt-token', ['Authorization: Bearer jwt-token'], [])
            ->andReturn(['status' => 200, 'data' => []]);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->with($supplier, 'POST', '/v1/hosts/123/actions/upgradeconfig/checkout', [], 'jwt-token', ['Authorization: Bearer jwt-token'], [])
            ->andReturn(['status' => 200, 'data' => ['invoiceid' => 456]]);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->with($supplier, 'POST', '/v1/invoices/456/fund', [], 'jwt-token', ['Authorization: Bearer jwt-token'], [])
            ->andReturn(['status' => 1001, 'data' => []]);
        $hostingTransport
            ->shouldReceive('requestWithMeta')
            ->once()
            ->with($supplier, 'GET', '/host/header', [], 'jwt-token', ['Authorization: Bearer jwt-token'], ['host_id' => 123, 'source' => 'API'])
            ->andReturn([
                'response' => ['status' => 200, 'data' => ['host_data' => ['bwlimit' => 2048]]],
                'headers' => [],
                'http_code' => 200,
                'content_type' => 'application/json',
            ]);

        $transport = $this->makeTransport($hostingTransport);
        $consoleService = new ZjmfConsoleService($transport);
        $networkService = new ZjmfNetworkService($transport, $consoleService);

        $result = $networkService->purchaseTrafficPackage(
            $supplier,
            123,
            'upgradeconfig',
            ['11' => 22],
            0,
            'https://upstream.example',
            'jwt-token'
        );

        $this->assertSame(456, $result['upstream_invoice_id']);
        $this->assertSame(2048, $result['host_detail']['bwlimit']);
    }

    public function test_network_service_purchases_host_upgrade_as_high_level_plugin_action(): void
    {
        $supplier = $this->makeSupplier();
        $hostingTransport = Mockery::mock(HostingPanelApiTransport::class);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->with($supplier, 'POST', '/v1/hosts/77/actions/upgrade', ['product_id' => 88, 'billingcycle' => 'monthly'], 'jwt-token', ['Authorization: Bearer jwt-token'], [])
            ->andReturn(['status' => 200, 'data' => []]);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->with($supplier, 'PUT', '/v1/hosts/77/actions/upgrade/promo', ['promo_code' => 'PROMO10'], 'jwt-token', ['Authorization: Bearer jwt-token'], [])
            ->andReturn(['status' => 200, 'data' => []]);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->with($supplier, 'POST', '/v1/hosts/77/actions/upgrade/checkout', [], 'jwt-token', ['Authorization: Bearer jwt-token'], [])
            ->andReturn(['status' => 200, 'data' => ['invoiceid' => 990]]);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->with($supplier, 'POST', '/v1/invoices/990/fund', [], 'jwt-token', ['Authorization: Bearer jwt-token'], [])
            ->andReturn(['status' => 1001, 'data' => []]);
        $hostingTransport
            ->shouldReceive('requestWithMeta')
            ->once()
            ->with($supplier, 'GET', '/host/header', [], 'jwt-token', ['Authorization: Bearer jwt-token'], ['host_id' => 77, 'source' => 'API'])
            ->andReturn([
                'response' => ['status' => 200, 'data' => ['host_data' => ['productid' => 88]]],
                'headers' => [],
                'http_code' => 200,
                'content_type' => 'application/json',
            ]);

        $transport = $this->makeTransport($hostingTransport);
        $consoleService = new ZjmfConsoleService($transport);
        $networkService = new ZjmfNetworkService($transport, $consoleService);

        $result = $networkService->purchaseHostUpgrade($supplier, 77, 88, 'monthly', 'PROMO10', 'jwt-token');

        $this->assertSame(990, $result['upstream_invoice_id']);
        $this->assertSame(88, $result['host_detail']['productid']);
    }

    public function test_network_service_does_not_read_host_or_report_success_while_fund_is_pending(): void
    {
        $supplier = $this->makeSupplier();
        $hostingTransport = Mockery::mock(HostingPanelApiTransport::class);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->with($supplier, 'POST', '/v1/hosts/77/actions/upgrade', ['product_id' => 88, 'billingcycle' => 'monthly'], 'jwt-token', ['Authorization: Bearer jwt-token'], [])
            ->andReturn(['status' => 200, 'data' => []]);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->with($supplier, 'POST', '/v1/hosts/77/actions/upgrade/checkout', [], 'jwt-token', ['Authorization: Bearer jwt-token'], [])
            ->andReturn(['status' => 200, 'data' => ['invoiceid' => 990]]);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->with($supplier, 'POST', '/v1/invoices/990/fund', [], 'jwt-token', ['Authorization: Bearer jwt-token'], [])
            ->andReturn(['status' => 200, 'data' => ['invoiceid' => 990]]);
        $hostingTransport
            ->shouldNotReceive('request')
            ->with($supplier, 'GET', '/host/header', Mockery::any(), Mockery::any(), Mockery::any(), Mockery::any());

        $networkService = new ZjmfNetworkService(
            $this->makeTransport($hostingTransport),
            new ZjmfConsoleService($this->makeTransport($hostingTransport))
        );

        $this->expectException(BusinessException::class);
        $networkService->purchaseHostUpgrade($supplier, 77, 88, 'monthly', '', 'jwt-token');
    }

    public function test_network_service_does_not_confirm_host_upgrade_when_host_detail_http_fails(): void
    {
        $supplier = $this->makeSupplier();
        $hostingTransport = Mockery::mock(HostingPanelApiTransport::class);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->with($supplier, 'POST', '/v1/hosts/77/actions/upgrade', ['product_id' => 88, 'billingcycle' => 'monthly'], 'jwt-token', ['Authorization: Bearer jwt-token'], [])
            ->andReturn(['status' => 200, 'data' => []]);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->with($supplier, 'POST', '/v1/hosts/77/actions/upgrade/checkout', [], 'jwt-token', ['Authorization: Bearer jwt-token'], [])
            ->andReturn(['status' => 200, 'data' => ['invoiceid' => 990]]);
        $hostingTransport
            ->shouldReceive('request')
            ->once()
            ->with($supplier, 'POST', '/v1/invoices/990/fund', [], 'jwt-token', ['Authorization: Bearer jwt-token'], [])
            ->andReturn(['status' => 1001, 'data' => []]);
        $hostingTransport
            ->shouldReceive('requestWithMeta')
            ->once()
            ->with($supplier, 'GET', '/host/header', [], 'jwt-token', ['Authorization: Bearer jwt-token'], ['host_id' => 77, 'source' => 'API'])
            ->andReturn([
                'response' => ['data' => []],
                'headers' => [],
                'http_code' => 502,
                'content_type' => 'application/json',
            ]);

        $networkService = new ZjmfNetworkService(
            $this->makeTransport($hostingTransport),
            new ZjmfConsoleService($this->makeTransport($hostingTransport))
        );

        $this->expectException(BusinessException::class);
        $networkService->purchaseHostUpgrade($supplier, 77, 88, 'monthly', '', 'jwt-token');
    }

    private function makeTransport(HostingPanelApiTransport $hostingTransport): ZjmfFinanceTransport
    {
        return new ZjmfFinanceTransport($hostingTransport, new ZjmfAuthManager($hostingTransport));
    }

    private function makeSupplier(): Supplier
    {
        $supplier = new Supplier;
        $supplier->forceFill([
            'id' => 10,
            'api_url' => 'https://upstream.example/api',
            'interface_type' => 'zjmf_finance_api',
        ]);

        return $supplier;
    }
}
