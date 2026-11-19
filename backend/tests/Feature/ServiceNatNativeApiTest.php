<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServiceNatService;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Services\System\OperationLogService;
use Mockery;
use Tests\TestCase;

/**
 * NAT 转发原生 API 通道：能力驱动。
 *
 * 驱动实现了 getNatRules 就走原生（读 createNatRule / deleteNatRule），
 * 没实现就落回原来的 HTML 自定义模块通道——zjmf 适配器属于后者，行为必须逐字不变。
 */
class ServiceNatNativeApiTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_reads_native_nat_rules_without_fetching_custom_modules(): void
    {
        $supplier = $this->makeSupplier();
        $localService = $this->makeService(921);
        $runtime = $this->makeNativeDriver();

        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($localService);
        $detailService->shouldReceive('resolveManagedSupplierAndHost')->once()->with($localService)->andReturn([$supplier, 89]);
        $detailService->shouldReceive('resolveRuntimeCapabilityForSupplier')->once()->with($supplier)->andReturn($runtime);
        // 原生通道不碰自定义模块列表
        $detailService->shouldNotReceive('fetchSupportedModules');
        $detailService->shouldReceive('assertSuccess')->once()->with($runtime->readResponse, '读取端口转发');
        $detailService->shouldReceive('extractPayload')->once()->andReturn([
            'list' => [[
                'id' => 12,
                'name' => '官网',
                'external_address' => '1.2.3.4:8080',
                'external_host' => '1.2.3.4',
                'external_port' => '8080',
                'internal_port' => '80',
                'protocol' => 'tcp',
                'protocol_label' => 'TCP',
                'can_delete' => true,
                'is_default' => false,
            ]],
            'protocols' => [
                ['value' => 'tcp', 'label' => 'TCP'],
                ['value' => 'udp', 'label' => 'UDP'],
                ['value' => 'tcp+udp', 'label' => 'TCP+UDP'],
            ],
            'can_create' => true,
        ]);

        $natService = new ServiceNatService(
            Mockery::mock(OperationLogService::class),
            $detailService,
            $this->makeTransformService($localService)
        );

        $result = $natService->getNatForwardingsForUser(new User, 921);

        $this->assertTrue($result['supported']);
        $this->assertSame('', $result['error']);
        $this->assertSame('nat_acl', $result['module_key']);
        $this->assertSame('NAT 转发', $result['module_name']);
        // 用户端不暴露上游请求地址
        $this->assertSame('', $result['endpoint']);
        $this->assertTrue($result['can_create']);
        $this->assertSame(['tcp', 'udp', 'tcp+udp'], array_column($result['protocols'], 'value'));
        $this->assertSame(12, $result['list'][0]['id']);
        $this->assertSame('1.2.3.4:8080', $result['list'][0]['external_address']);
        $this->assertSame('TCP', $result['list'][0]['protocol_label']);
        $this->assertSame(['total' => 1], $result['summary']);
        $this->assertSame(1, $runtime->callCount('getNatRules'));
    }

    public function test_it_falls_back_to_the_html_module_when_the_driver_lacks_get_nat_rules(): void
    {
        $supplier = $this->makeSupplier();
        $localService = $this->makeService(922);
        $runtime = new class
        {
            public string $html = '<div class="table-responsive"><table><tbody>'
                .'<tr><td>官网</td><td>1.2.3.4:8080</td><td>80</td><td>TCP</td>'
                .'<td><a class="deleteNAT" data-id="12">删除</a></td></tr>'
                .'</tbody></table></div>'
                .'<select name="select-protocol"><option value="tcp">TCP</option><option value="udp">UDP</option></select>'
                .'<button data-target="natAclAddModal">新增</button>';

            public function login(Supplier $supplier): string
            {
                return 'custom-jwt';
            }

            public function fetchCustomModulePage(Supplier $supplier, int $hostId, string $moduleKey, string $jwt): string
            {
                return $this->html;
            }

            public function getCustomModuleActionEndpoint(Supplier $supplier, int $hostId): string
            {
                return 'https://upstream.example/provision/custom/89';
            }

            public function post(Supplier $supplier, string $uri, array|string $payload = [], ?string $jwt = null, array $headers = []): array
            {
                return ['status' => 200];
            }
        };

        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($localService);
        $detailService->shouldReceive('resolveManagedSupplierAndHost')->once()->with($localService)->andReturn([$supplier, 89]);
        $detailService->shouldReceive('resolveRuntimeCapabilityForSupplier')->once()->with($supplier)->andReturn($runtime);
        // 没有 getNatRules → 必须回到「找自定义模块」这条老路
        $detailService
            ->shouldReceive('fetchSupportedModules')
            ->once()
            ->with($supplier, 89, 'custom-jwt')
            ->andReturn([['type' => 'custom', 'function' => 'nat_acl', 'name' => 'NAT 转发']]);

        $transformService = $this->makeTransformService($localService);

        $natService = new ServiceNatService(Mockery::mock(OperationLogService::class), $detailService, $transformService);

        $result = $natService->getNatForwardingsForUser(new User, 922);

        $this->assertTrue($result['supported']);
        $this->assertSame('', $result['error']);
        $this->assertSame('nat_acl', $result['module_key']);
        // 用户端永远拿不到上游地址，两条通道都置空
        $this->assertSame('', $result['endpoint']);
        $this->assertSame(['tcp', 'udp'], array_column($result['protocols'], 'value'));
        $this->assertTrue($result['can_create']);
        $this->assertCount(1, $result['list']);
        $this->assertSame(12, $result['list'][0]['id']);
        $this->assertSame('1.2.3.4:8080', $result['list'][0]['external_address']);
    }

    public function test_it_creates_a_native_nat_rule_and_logs_the_console_action(): void
    {
        $supplier = $this->makeSupplier();
        $localService = $this->makeService(923);
        $runtime = $this->makeNativeDriver();

        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($localService);
        $detailService->shouldReceive('resolveManagedSupplierAndHost')->once()->with($localService)->andReturn([$supplier, 89]);
        $detailService->shouldReceive('resolveRuntimeCapabilityForSupplier')->once()->with($supplier)->andReturn($runtime);
        $detailService->shouldReceive('assertSuccess')->once()->with($runtime->readResponse, '读取端口转发');
        $detailService->shouldReceive('extractPayload')->once()->andReturn($this->nativeReadPayload());
        $detailService->shouldReceive('assertSuccess')->once()->with($runtime->createResponse, '创建端口转发');

        $transformService = Mockery::mock(ServiceTransformService::class);
        $transformService->shouldReceive('canManageService')->once()->with($localService)->andReturnTrue();
        $transformService
            ->shouldReceive('resolveSelectOptionLabel')
            ->once()
            ->with($this->nativeReadPayload()['protocols'], 'tcp+udp')
            ->andReturn('TCP+UDP');

        $operationLogs = Mockery::mock(OperationLogService::class);
        $operationLogs
            ->shouldReceive('writeServiceConsoleLog')
            ->once()
            ->with(
                $localService,
                'service.console.nat_forwarding.create',
                Mockery::on(fn (array $payload): bool => $payload['host_id'] === 89
                    && $payload['forwarding_name'] === '官网'
                    && $payload['protocol'] === 'tcp+udp'
                    && $payload['protocol_label'] === 'TCP+UDP'),
                Mockery::type('array')
            );

        $natService = $this->makeStubbedService($detailService, $transformService, $operationLogs);

        $result = $natService->createNatForwardingForUser(new User, 923, [
            'name' => '官网',
            'ext_port' => '8080',
            'int_port' => '80',
            'protocol' => 'tcp+udp',
        ]);

        $this->assertSame('端口转发创建成功', $result['message']);
        $this->assertSame([[
            'supplier' => $supplier,
            'hostId' => 89,
            'payload' => ['name' => '官网', 'ext_port' => '8080', 'int_port' => '80', 'protocol' => 'tcp+udp'],
            'jwt' => 'native-jwt',
        ]], $runtime->callsFor('createNatRule'));
        // 原生通道不该往 HTML 提交接口发任何请求
        $this->assertSame(0, $runtime->callCount('submitCustomModuleAction'));
    }

    public function test_it_rejects_a_protocol_outside_the_native_whitelist(): void
    {
        $supplier = $this->makeSupplier();
        $localService = $this->makeService(924);
        $runtime = $this->makeNativeDriver();

        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($localService);
        $detailService->shouldReceive('resolveManagedSupplierAndHost')->once()->with($localService)->andReturn([$supplier, 89]);
        $detailService->shouldReceive('resolveRuntimeCapabilityForSupplier')->once()->with($supplier)->andReturn($runtime);
        $detailService->shouldReceive('assertSuccess')->once()->with($runtime->readResponse, '读取端口转发');
        $detailService->shouldReceive('extractPayload')->once()->andReturn($this->nativeReadPayload());

        $operationLogs = Mockery::mock(OperationLogService::class);
        $operationLogs->shouldNotReceive('writeServiceConsoleLog');

        $natService = $this->makeStubbedService(
            $detailService,
            $this->makeTransformService($localService),
            $operationLogs
        );

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('NAT 转发协议不在当前上游允许范围内');

        try {
            $natService->createNatForwardingForUser(new User, 924, [
                'name' => '官网',
                'ext_port' => '8080',
                'int_port' => '80',
                'protocol' => 'sctp',
            ]);
        } finally {
            $this->assertSame(0, $runtime->callCount('createNatRule'));
        }
    }

    public function test_it_deletes_a_native_nat_rule(): void
    {
        $supplier = $this->makeSupplier();
        $localService = $this->makeService(925);
        $runtime = $this->makeNativeDriver();

        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($localService);
        $detailService->shouldReceive('resolveManagedSupplierAndHost')->once()->with($localService)->andReturn([$supplier, 89]);
        $detailService->shouldReceive('resolveRuntimeCapabilityForSupplier')->once()->with($supplier)->andReturn($runtime);
        $detailService->shouldReceive('assertSuccess')->once()->with($runtime->readResponse, '读取端口转发');
        $detailService->shouldReceive('extractPayload')->once()->andReturn($this->nativeReadPayload());
        $detailService->shouldReceive('assertSuccess')->once()->with($runtime->deleteResponse, '删除端口转发');

        $operationLogs = Mockery::mock(OperationLogService::class);
        $operationLogs->shouldReceive('writeServiceConsoleLog')
            ->once()
            ->with(
                $localService,
                'service.console.nat_forwarding.delete',
                Mockery::on(fn (array $payload): bool => $payload['host_id'] === 89 && $payload['forwarding_id'] === 12),
                Mockery::type('array')
            );

        $natService = $this->makeStubbedService(
            $detailService,
            $this->makeTransformService($localService),
            $operationLogs
        );

        $result = $natService->deleteNatForwardingForUser(new User, 925, 12);

        $this->assertSame('端口转发删除成功', $result['message']);
        $this->assertSame([[
            'supplier' => $supplier,
            'hostId' => 89,
            'ruleId' => 12,
            'jwt' => 'native-jwt',
        ]], $runtime->callsFor('deleteNatRule'));
    }

    public function test_it_rejects_native_creation_when_the_driver_lacks_create_nat_rule(): void
    {
        $supplier = $this->makeSupplier();
        $localService = $this->makeService(926);
        $runtime = new class
        {
            public function login(Supplier $supplier): string
            {
                return 'native-jwt';
            }

            public function getNatRules(Supplier $supplier, int $hostId, ?string $jwt = null): array
            {
                return [
                    'status' => 200,
                    'data' => [
                        'list' => [],
                        'protocols' => [['value' => 'tcp', 'label' => 'TCP']],
                        'can_create' => false,
                    ],
                ];
            }
        };

        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($localService);
        $detailService->shouldReceive('resolveManagedSupplierAndHost')->once()->with($localService)->andReturn([$supplier, 89]);
        $detailService->shouldReceive('resolveRuntimeCapabilityForSupplier')->once()->with($supplier)->andReturn($runtime);
        $detailService->shouldReceive('assertSuccess')->once()->with(Mockery::type('array'), '读取端口转发');
        $detailService->shouldReceive('extractPayload')->once()->andReturn([
            'list' => [],
            'protocols' => [['value' => 'tcp', 'label' => 'TCP']],
            'can_create' => false,
        ]);

        $operationLogs = Mockery::mock(OperationLogService::class);
        $operationLogs->shouldNotReceive('writeServiceConsoleLog');

        $natService = $this->makeStubbedService(
            $detailService,
            $this->makeTransformService($localService),
            $operationLogs
        );

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('当前上游仅支持 NAT 转发列表');

        $natService->createNatForwardingForUser(new User, 926, [
            'name' => '官网',
            'ext_port' => '8080',
            'int_port' => '80',
            'protocol' => 'tcp',
        ]);
    }

    /**
     * 上游自定义模块页面会间歇性返回空串：实测同一台主机连打 5 次，4 次空、1 次正常。
     * 单次空响应必须重试，否则客户看到的端口转发面板就是「时好时坏」。
     */
    public function test_it_retries_the_custom_module_page_when_upstream_returns_empty(): void
    {
        $supplier = $this->makeSupplier();
        $localService = $this->makeService(927);

        $runtime = new class
        {
            public int $pageCalls = 0;

            public function login(Supplier $supplier): string
            {
                return 'custom-jwt';
            }

            public function fetchCustomModulePage(Supplier $supplier, int $hostId, string $moduleKey, string $jwt): string
            {
                $this->pageCalls++;

                // 前两次是上游抖动的空响应，第三次才拿到真页面
                return $this->pageCalls >= 3
                    ? '<div><table><tbody></tbody></table></div>'
                        .'<select name="select-protocol"><option value="tcp">TCP</option></select>'
                        .'<button data-target="natAclAddModal">新增</button>'
                    : '';
            }

            public function getCustomModuleActionEndpoint(Supplier $supplier, int $hostId): string
            {
                return 'https://upstream.example/provision/custom/89';
            }
        };

        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($localService);
        $detailService->shouldReceive('resolveManagedSupplierAndHost')->once()->with($localService)->andReturn([$supplier, 89]);
        $detailService->shouldReceive('resolveRuntimeCapabilityForSupplier')->once()->with($supplier)->andReturn($runtime);
        $detailService
            ->shouldReceive('fetchSupportedModules')
            ->once()
            ->andReturn([['type' => 'custom', 'function' => 'nat_acl', 'name' => 'NAT 转发']]);

        $natService = new ServiceNatService(
            Mockery::mock(OperationLogService::class),
            $detailService,
            $this->makeTransformService($localService)
        );

        $result = $natService->getNatForwardingsForUser(new User, 927);

        $this->assertSame('', $result['error'], '重试拿到页面就不该把面板判成读取失败');
        $this->assertTrue($result['supported']);
        $this->assertSame(3, $runtime->pageCalls, '空响应要重试，拿到内容才停');
        $this->assertSame('nat_acl', $result['module_key']);
    }

    /**
     * 模块列表抖动到「没有 NAT」时，靠探模块页面兜底：页面取得到就说明模块在。
     * 这是客户反馈「端口转发没被解析成系统面板、走了通用面板」的另一半成因。
     */
    public function test_it_probes_the_module_page_when_the_flaky_module_list_omits_nat(): void
    {
        $supplier = $this->makeSupplier();
        $localService = $this->makeService(928);

        $runtime = new class
        {
            public function login(Supplier $supplier): string
            {
                return 'custom-jwt';
            }

            public function fetchCustomModulePage(Supplier $supplier, int $hostId, string $moduleKey, string $jwt): string
            {
                return '<div><table><tbody></tbody></table></div>'
                    .'<select name="select-protocol"><option value="tcp">TCP</option><option value="udp">UDP</option></select>'
                    .'<button data-target="natAclAddModal">新增</button>';
            }

            public function getCustomModuleActionEndpoint(Supplier $supplier, int $hostId): string
            {
                return 'https://upstream.example/provision/custom/89';
            }
        };

        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($localService);
        $detailService->shouldReceive('resolveManagedSupplierAndHost')->once()->with($localService)->andReturn([$supplier, 89]);
        $detailService->shouldReceive('resolveRuntimeCapabilityForSupplier')->once()->with($supplier)->andReturn($runtime);
        // 抖动到只剩「安全组」，没有 NAT
        $detailService
            ->shouldReceive('fetchSupportedModules')
            ->once()
            ->andReturn([['type' => 'custom', 'function' => 'security_groups', 'name' => '安全组']]);

        $natService = new ServiceNatService(
            Mockery::mock(OperationLogService::class),
            $detailService,
            $this->makeTransformService($localService)
        );

        $result = $natService->getNatForwardingsForUser(new User, 928);

        $this->assertSame('', $result['error']);
        $this->assertSame('nat_acl', $result['module_key']);
        $this->assertSame(['tcp', 'udp'], array_column($result['protocols'], 'value'));
        $this->assertTrue($result['can_create']);
    }

    /**
     * 上游对不存在的路由回的是自带 404 模板页（HTTP 200、1297 字节、非空）。
     * 按「非空即成功」判会把它当真内容解析，协议选项为空 → 客户加不了端口。
     */
    public function test_it_rejects_the_upstream_404_placeholder_page(): void
    {
        $this->assertFalse(
            ServiceNatService::isUsableModulePage(''),
            '空响应不是可用页面'
        );
        $this->assertFalse(
            ServiceNatService::isUsableModulePage('   '),
            '纯空白不是可用页面'
        );
        $this->assertFalse(
            ServiceNatService::isUsableModulePage('<html><head><title>404</title></head><body>抱歉找不到页面...</body></html>'),
            '魔方财务 404 模板页不是可用页面'
        );
        $this->assertFalse(
            ServiceNatService::isUsableModulePage('<div class="maintain-box"><p>维护中</p></div>'),
            '维护占位页不是可用页面'
        );
        $this->assertTrue(
            ServiceNatService::isUsableModulePage('<select name="select-protocol"><option value="1">tcp</option></select>'),
            '真页面必须判为可用'
        );
    }

    /**
     * 页面取到了但里面既无协议选项又无规则行（残缺片段）时必须换一页，
     * 不能把这种 context 缓存成「无协议选项」让客户加不了端口。
     */
    public function test_it_retries_when_the_page_lacks_protocols_and_rules(): void
    {
        $supplier = $this->makeSupplier();
        $localService = $this->makeService(929);

        $runtime = new class
        {
            public int $pageCalls = 0;

            public function login(Supplier $supplier): string
            {
                return 'custom-jwt';
            }

            public function fetchCustomModulePage(Supplier $supplier, int $hostId, string $moduleKey, string $jwt): string
            {
                $this->pageCalls++;

                // 首次是一段残缺片段：非空、不是 404，但没有 select-protocol、没有规则行
                if ($this->pageCalls === 1) {
                    return '<div><p>加载中…</p></div>';
                }

                return '<div><table><tbody></tbody></table></div>'
                    .'<select name="select-protocol"><option value="3">tcp+udp</option></select>'
                    .'<button data-target="natAclAddModal">新增</button>';
            }

            public function getCustomModuleActionEndpoint(Supplier $supplier, int $hostId): string
            {
                return 'https://upstream.example/provision/custom/89';
            }
        };

        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($localService);
        $detailService->shouldReceive('resolveManagedSupplierAndHost')->once()->with($localService)->andReturn([$supplier, 89]);
        $detailService->shouldReceive('resolveRuntimeCapabilityForSupplier')->once()->with($supplier)->andReturn($runtime);
        $detailService
            ->shouldReceive('fetchSupportedModules')
            ->once()
            ->andReturn([['type' => 'custom', 'function' => 'nat_acl', 'name' => 'NAT 转发']]);

        $natService = new ServiceNatService(
            Mockery::mock(OperationLogService::class),
            $detailService,
            $this->makeTransformService($localService)
        );

        $result = $natService->getNatForwardingsForUser(new User, 929);

        $this->assertSame('', $result['error']);
        $this->assertSame([['value' => '3', 'label' => 'tcp+udp']], $result['protocols']);
        $this->assertTrue($result['can_create'], '残缺片段的「无新增按钮」不能当成上游不允许新增');
        $this->assertGreaterThanOrEqual(2, $runtime->pageCalls, '残缺片段要换一页重试');
    }

    private function makeSupplier(): Supplier
    {
        $supplier = new Supplier;
        $supplier->id = 10;

        return $supplier;
    }

    private function makeService(int $id): Service
    {
        $service = new Service;
        $service->id = $id;

        return $service;
    }

    private function makeTransformService(Service $localService): object
    {
        $transformService = Mockery::mock(ServiceTransformService::class);
        // 读取路径会两次判定可管理性（入口一次、解析上下文一次），写路径同理
        $transformService->shouldReceive('canManageService')->atLeast()->once()->with($localService)->andReturnTrue();

        return $transformService;
    }

    /**
     * 原生驱动：读 / 建 / 删三个方法都实现，并把实参逐次记下来。
     */
    private function makeNativeDriver(): object
    {
        $payload = $this->nativeReadPayload();

        return new class($payload)
        {
            /** @var array<int, array{method: string, args: array<string, mixed>}> */
            public array $calls = [];

            public array $readResponse;

            public array $createResponse = ['status' => 200, 'msg' => ''];

            public array $deleteResponse = ['status' => 200, 'msg' => ''];

            public function __construct(array $payload)
            {
                $this->readResponse = ['status' => 200, 'data' => $payload];
            }

            public function login(Supplier $supplier): string
            {
                return 'native-jwt';
            }

            public function getNatRules(Supplier $supplier, int $hostId, ?string $jwt = null): array
            {
                $this->calls[] = ['method' => 'getNatRules', 'args' => compact('supplier', 'hostId', 'jwt')];

                return $this->readResponse;
            }

            public function createNatRule(Supplier $supplier, int $hostId, array $payload, ?string $jwt = null): array
            {
                $this->calls[] = ['method' => 'createNatRule', 'args' => compact('supplier', 'hostId', 'payload', 'jwt')];

                return $this->createResponse;
            }

            public function deleteNatRule(Supplier $supplier, int $hostId, int $ruleId, ?string $jwt = null): array
            {
                $this->calls[] = ['method' => 'deleteNatRule', 'args' => compact('supplier', 'hostId', 'ruleId', 'jwt')];

                return $this->deleteResponse;
            }

            public function callCount(string $method): int
            {
                return count($this->callsFor($method));
            }

            /**
             * @return array<int, array<string, mixed>>
             */
            public function callsFor(string $method): array
            {
                return array_values(array_map(
                    static fn (array $call): array => $call['args'],
                    array_filter($this->calls, static fn (array $call): bool => $call['method'] === $method)
                ));
            }
        };
    }

    /**
     * 写操作的被测服务：详情刷新被短路，避免用例被「创建后再读一次」的重复桩干扰。
     */
    private function makeStubbedService(object $detailService, object $transformService, object $operationLogs): ServiceNatService
    {
        return new class($operationLogs, $detailService, $transformService) extends ServiceNatService
        {
            public function getNatForwardingsForUser(User $user, int $serviceId): array
            {
                return [
                    'supported' => true,
                    'message' => '',
                    'error' => '',
                    'module_key' => 'nat_acl',
                    'module_name' => 'NAT 转发',
                    'endpoint' => '',
                    'can_create' => true,
                    'protocols' => [],
                    'list' => [],
                    'summary' => ['total' => 0],
                ];
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function nativeReadPayload(): array
    {
        return [
            'list' => [[
                'id' => 12,
                'name' => '官网',
                'external_address' => '1.2.3.4:8080',
                'external_host' => '1.2.3.4',
                'external_port' => '8080',
                'internal_port' => '80',
                'protocol' => 'tcp',
                'protocol_label' => 'TCP',
                'can_delete' => true,
                'is_default' => false,
            ]],
            'protocols' => [
                ['value' => 'tcp', 'label' => 'TCP'],
                ['value' => 'udp', 'label' => 'UDP'],
                ['value' => 'tcp+udp', 'label' => 'TCP+UDP'],
            ],
            'can_create' => true,
        ];
    }
}
