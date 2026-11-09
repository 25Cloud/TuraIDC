<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServiceNatService;
use App\Services\ClientServiceConsole\ServiceSecurityGroupService;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Services\System\OperationLogService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

/**
 * 安全组原生 API 通道：能力驱动。
 *
 * 约定：驱动实现了哪个原生方法，就开放哪个操作；没实现的落到「当前上游仅支持安全组列表和应用操作」。
 * 这里用匿名类当驱动，靠「有没有声明方法」来模拟能力差异——zjmf 适配器就是「一个都没有」的那种。
 */
class ServiceSecurityGroupNativeApiTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_uses_native_security_group_list_without_loading_a_custom_module_page(): void
    {
        $supplier = $this->makeSupplier();
        $localService = new Service;
        $localService->id = 901;
        // 只实现列表：四个写开关必须全是 false，前端因此不会给出必然失败的按钮
        $runtime = new class
        {
            public array $listRequest = [];

            public function login(Supplier $supplier): string
            {
                return 'native-jwt';
            }

            public function getSecurityGroups(Supplier $supplier, int $page, int $limit, ?string $jwt = null): array
            {
                $this->listRequest = compact('supplier', 'page', 'limit', 'jwt');

                return ['status' => 200, 'data' => ['list' => []]];
            }
        };
        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService
            ->shouldReceive('resolveManagedSupplierAndHost')
            ->twice()
            ->with($localService)
            ->andReturn([$supplier, 89]);
        $detailService
            ->shouldReceive('resolveRuntimeCapabilityForSupplier')
            ->once()
            ->with($supplier)
            ->andReturn($runtime);
        $detailService
            ->shouldReceive('assertSuccess')
            ->once()
            ->with(Mockery::type('array'), '读取安全组');
        $detailService
            ->shouldReceive('extractPayload')
            ->once()
            ->andReturn([
                'list' => [
                    ['id' => 44, 'name' => '默认安全组', 'description' => '用于测试', 'host_ids' => '89,90'],
                    ['id' => 45, 'name' => '备用安全组'],
                ],
            ]);

        $securityGroups = new ServiceSecurityGroupService(
            Mockery::mock(OperationLogService::class),
            $detailService,
            Mockery::mock(ServiceTransformService::class),
            Mockery::mock(ServiceNatService::class),
        );

        $context = $securityGroups->resolveSecurityGroupContext($localService, true);

        $this->assertSame('native', $context['mode']);
        $this->assertSame(['page' => 1, 'limit' => 9999, 'jwt' => 'native-jwt'], array_diff_key($runtime->listRequest, ['supplier' => true]));
        $this->assertFalse($context['can_create']);
        $this->assertCount(1, $context['groups']);
        $this->assertFalse($context['groups'][0]['can_view']);
        $this->assertFalse($context['groups'][0]['can_add_rule']);
        $this->assertFalse($context['groups'][0]['can_apply']);
        $this->assertFalse($context['groups'][0]['can_delete']);
        $this->assertTrue($context['groups'][0]['delete_disabled']);
        $this->assertTrue($context['groups'][0]['is_applied']);
        $this->assertCount(2, $context['raw_groups']);
    }

    public function test_it_opens_every_switch_when_the_driver_implements_all_native_methods(): void
    {
        $supplier = $this->makeSupplier();
        $localService = new Service;
        $localService->id = 906;
        $runtime = $this->makeFullNativeDriver();
        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('resolveManagedSupplierAndHost')->twice()->with($localService)->andReturn([$supplier, 89]);
        $detailService->shouldReceive('resolveRuntimeCapabilityForSupplier')->once()->with($supplier)->andReturn($runtime);
        $detailService->shouldReceive('assertSuccess')->once()->with(Mockery::type('array'), '读取安全组');
        $detailService->shouldReceive('extractPayload')->once()->andReturn([
            'list' => [['id' => 44, 'name' => '默认安全组', 'host_ids' => '89']],
        ]);

        $securityGroups = new ServiceSecurityGroupService(
            Mockery::mock(OperationLogService::class),
            $detailService,
            Mockery::mock(ServiceTransformService::class),
            Mockery::mock(ServiceNatService::class),
        );

        $context = $securityGroups->resolveSecurityGroupContext($localService, true);
        $group = $context['groups'][0];

        $this->assertTrue($context['can_create']);
        $this->assertTrue($group['can_view']);
        $this->assertTrue($group['can_add_rule']);
        $this->assertTrue($group['can_apply']);
        $this->assertTrue($group['can_delete']);
        $this->assertFalse($group['delete_disabled']);
    }

    public function test_it_creates_a_native_security_group_through_the_driver_and_writes_a_log(): void
    {
        $supplier = $this->makeSupplier();
        $localService = new Service;
        $localService->id = 907;
        $runtime = $this->makeFullNativeDriver();
        $response = ['status' => 200, 'msg' => '安全组创建成功'];

        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($localService);
        $detailService->shouldReceive('resolveRuntimeCapabilityForSupplier')->once()->with($supplier)->andReturn($runtime);
        $detailService->shouldReceive('assertSuccess')->once()->with($response, '创建安全组');

        $operationLogs = Mockery::mock(OperationLogService::class);
        $operationLogs
            ->shouldReceive('writeServiceConsoleLog')
            ->once()
            ->with(
                $localService,
                'service.console.security_group.create',
                Mockery::on(fn (array $payload): bool => $payload['host_id'] === 89
                    && $payload['group_name'] === 'web-one'
                    && $payload['description'] === '面向公网'),
                Mockery::type('array')
            );

        $securityGroups = $this->makeStubbedService($detailService, $operationLogs, [
            'mode' => 'native',
            'supplier' => $supplier,
            'supplier_id' => 10,
            'host_id' => 89,
            'jwt' => 'native-jwt',
            'groups' => [],
            'raw_groups' => [],
        ]);

        $result = $securityGroups->createSecurityGroupForUser(new User, 907, ['name' => 'web-one', 'description' => '面向公网']);

        $this->assertSame('安全组创建成功', $result['message']);
        $this->assertSame([[
            'supplier' => $supplier,
            'params' => ['name' => 'web-one', 'description' => '面向公网', 'host_id' => 89],
            'jwt' => 'native-jwt',
        ]], $runtime->callsFor('createSecurityGroup'));
    }

    public function test_it_rejects_native_creation_with_the_old_message_when_the_driver_lacks_the_method(): void
    {
        $supplier = $this->makeSupplier();
        $localService = new Service;
        $localService->id = 908;
        $runtime = new class
        {
            public function login(Supplier $supplier): string
            {
                return 'native-jwt';
            }

            public function getSecurityGroups(Supplier $supplier, int $page, int $limit, ?string $jwt = null): array
            {
                return ['status' => 200, 'data' => ['list' => []]];
            }
        };

        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($localService);
        $detailService->shouldReceive('resolveRuntimeCapabilityForSupplier')->once()->with($supplier)->andReturn($runtime);

        $operationLogs = Mockery::mock(OperationLogService::class);
        // 能力缺口在调用驱动之前就拒绝，写日志不该发生
        $operationLogs->shouldNotReceive('writeServiceConsoleLog');

        $securityGroups = $this->makeStubbedService($detailService, $operationLogs, [
            'mode' => 'native',
            'supplier' => $supplier,
            'supplier_id' => 10,
            'host_id' => 89,
            'jwt' => 'native-jwt',
            'groups' => [],
            'raw_groups' => [],
        ]);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('当前上游仅支持安全组列表和应用操作');

        $securityGroups->createSecurityGroupForUser(new User, 908, ['name' => 'web-one']);
    }

    public function test_it_reads_native_security_rules_and_keeps_the_console_field_names(): void
    {
        $supplier = $this->makeSupplier();
        $localService = new Service;
        $localService->id = 909;
        $runtime = $this->makeFullNativeDriver();
        $response = ['status' => 200, 'data' => ['list' => []]];

        // 规则读取前的归属断言按 operation_logs 里的历史动作反推 owned 集合：
        // 种一条「本服务在 89 号主机创建过 44 号安全组」的日志，断言才有可判定的前提。
        DB::table('operation_logs')->insert([
            'action' => 'service.console.security_group.create',
            'module' => 'service',
            'context' => json_encode([
                'service_id' => 909,
                'host_id' => 89,
                'group_id' => 44,
                'group_name' => '默认安全组',
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
        ]);

        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($localService);
        $detailService->shouldReceive('resolveManagedSupplierAndHost')->andReturn([$supplier, 89]);
        $detailService->shouldReceive('resolveRuntimeCapabilityForSupplier')->once()->with($supplier)->andReturn($runtime);
        $detailService->shouldReceive('assertSuccess')->once()->with($response, '读取安全组规则');
        $detailService->shouldReceive('extractPayload')->once()->andReturn([
            'list' => [[
                'id' => 7,
                'direction' => 'in',
                'protocol' => 'tcp',
                'port' => '80',
                'ip' => '10.0.0.1',
                'description' => 'web 80',
            ]],
        ]);

        $securityGroups = $this->makeStubbedService($detailService, Mockery::mock(OperationLogService::class), [
            'mode' => 'native',
            'supplier' => $supplier,
            'supplier_id' => 10,
            'host_id' => 89,
            'jwt' => 'native-jwt',
            'host_type' => '',
            'groups' => [['id' => 44, 'name' => '默认安全组']],
            'raw_groups' => [['id' => 44, 'name' => '默认安全组']],
        ]);

        $result = $securityGroups->getSecurityGroupRulesForUser(new User, 909, 44);

        $this->assertSame(44, $result['group_id']);
        $this->assertSame('', $result['host_type']);
        $this->assertCount(1, $result['list']);
        $rule = $result['list'][0];
        $this->assertSame(7, $rule['id']);
        $this->assertSame('入方向', $rule['direction_label']);
        $this->assertSame('tcp', $rule['protocol']);
        $this->assertSame('80', $rule['port']);
        $this->assertSame('10.0.0.1', $rule['ip']);
        $this->assertSame('web 80', $rule['description']);
        $this->assertSame('', $rule['host_type']);
        $this->assertSame([[
            'supplier' => $supplier,
            'groupId' => 44,
            'hostId' => 89,
            'jwt' => 'native-jwt',
        ]], $runtime->callsFor('getSecurityGroupRules'));
    }

    public function test_it_applies_a_visible_native_security_group_to_the_current_host(): void
    {
        $supplier = $this->makeSupplier();
        $localService = new Service;
        $localService->id = 902;
        $runtime = new class
        {
            public array $applyRequest = [];

            public function applySecurityGroup(Supplier $supplier, int $groupId, int $hostId, ?string $jwt = null): array
            {
                $this->applyRequest = compact('supplier', 'groupId', 'hostId', 'jwt');

                return ['status' => 200, 'msg' => '安全组已应用'];
            }
        };
        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($localService);
        $detailService
            ->shouldReceive('resolveRuntimeCapabilityForSupplier')
            ->once()
            ->with($supplier)
            ->andReturn($runtime);
        $detailService
            ->shouldReceive('assertSuccess')
            ->once()
            ->with(['status' => 200, 'msg' => '安全组已应用'], '应用安全组');
        $operationLogs = Mockery::mock(OperationLogService::class);
        $operationLogs->shouldReceive('writeServiceConsoleLog')->once();

        $securityGroups = $this->makeStubbedService($detailService, $operationLogs, [
            'mode' => 'native',
            'supplier' => $supplier,
            'host_id' => 89,
            'jwt' => 'native-jwt',
            'groups' => [['id' => 44, 'name' => '默认安全组']],
        ]);

        $result = $securityGroups->applySecurityGroupForUser(new User, 902, 44);

        $this->assertSame('安全组已应用', $result['message']);
        $this->assertSame(['groupId' => 44, 'hostId' => 89, 'jwt' => 'native-jwt'], array_diff_key($runtime->applyRequest, ['supplier' => true]));
    }

    public function test_it_deletes_a_native_security_group_through_the_driver(): void
    {
        $supplier = $this->makeSupplier();
        $localService = new Service;
        $localService->id = 910;
        $runtime = $this->makeFullNativeDriver();
        $response = ['status' => 200, 'msg' => '安全组已删除'];

        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($localService);
        $detailService->shouldReceive('resolveRuntimeCapabilityForSupplier')->once()->with($supplier)->andReturn($runtime);
        $detailService->shouldReceive('assertSuccess')->once()->with($response, '删除安全组');

        $operationLogs = Mockery::mock(OperationLogService::class);
        $operationLogs->shouldReceive('writeServiceConsoleLog')
            ->once()
            ->with(
                $localService,
                'service.console.security_group.delete',
                Mockery::on(fn (array $payload): bool => $payload['group_id'] === 44 && $payload['group_name'] === '默认安全组'),
                Mockery::type('array')
            );

        $securityGroups = $this->makeStubbedService($detailService, $operationLogs, [
            'mode' => 'native',
            'supplier' => $supplier,
            'host_id' => 89,
            'jwt' => 'native-jwt',
            'groups' => [['id' => 44, 'name' => '默认安全组']],
        ]);

        $result = $securityGroups->deleteSecurityGroupForUser(new User, 910, 44);

        $this->assertSame('安全组已删除', $result['message']);
        $this->assertSame([[
            'supplier' => $supplier,
            'groupId' => 44,
            'hostId' => 89,
            'jwt' => 'native-jwt',
        ]], $runtime->callsFor('deleteSecurityGroup'));
    }

    public function test_it_creates_and_deletes_native_security_rules_through_the_driver(): void
    {
        $supplier = $this->makeSupplier();
        $localService = new Service;
        $localService->id = 911;
        $runtime = $this->makeFullNativeDriver();
        $createResponse = ['status' => 200, 'msg' => '规则创建成功'];
        $deleteResponse = ['status' => 200, 'msg' => '规则已删除'];

        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->twice()->andReturn($localService);
        // 创建规则一次、删除前归属断言读列表一次、删除规则一次
        $detailService->shouldReceive('resolveRuntimeCapabilityForSupplier')->times(3)->with($supplier)->andReturn($runtime);
        $detailService->shouldReceive('assertSuccess')->once()->with($createResponse, '创建安全组规则');
        // 删除规则前的归属断言会先拉一次规则列表，规则 id 必须出现在里面
        $detailService->shouldReceive('extractPayload')->once()->andReturn([
            'list' => [['id' => 7, 'direction' => 'in', 'protocol' => 'tcp', 'port' => '80', 'ip' => '10.0.0.1']],
        ]);
        $detailService->shouldReceive('assertSuccess')->once()->with(Mockery::type('array'), '读取安全组规则');
        $detailService->shouldReceive('assertSuccess')->once()->with($deleteResponse, '删除安全组规则');

        $operationLogs = Mockery::mock(OperationLogService::class);
        $operationLogs->shouldReceive('writeServiceConsoleLog')->twice();

        $transformService = Mockery::mock(ServiceTransformService::class);
        $transformService->shouldReceive('resolveSelectOptionLabel')
            ->once()
            ->with([['value' => 'in', 'label' => '入方向']], 'in')
            ->andReturn('入方向');
        $transformService->shouldReceive('resolveSelectOptionLabel')
            ->once()
            ->with([['value' => 'tcp', 'label' => 'TCP']], 'tcp')
            ->andReturn('TCP');

        $securityGroups = $this->makeStubbedService($detailService, $operationLogs, [
            'mode' => 'native',
            'supplier' => $supplier,
            'host_id' => 89,
            'jwt' => 'native-jwt',
            'host_type' => '',
            'directions' => [['value' => 'in', 'label' => '入方向']],
            'protocols' => [['value' => 'tcp', 'label' => 'TCP']],
            'groups' => [['id' => 44, 'name' => '默认安全组']],
            'raw_groups' => [['id' => 44, 'name' => '默认安全组']],
        ], $transformService);

        $created = $securityGroups->createSecurityRuleForUser(new User, 911, 44, [
            'direction' => 'in',
            'protocol' => 'tcp',
            'port' => '80',
            'ip' => '10.0.0.1',
            'description' => 'web 80',
        ]);
        $this->assertSame('规则创建成功', $created['message']);
        $this->assertSame([[
            'supplier' => $supplier,
            'groupId' => 44,
            'params' => [
                'direction' => 'in',
                'protocol' => 'tcp',
                'port' => '80',
                'ip' => '10.0.0.1',
                'description' => 'web 80',
            ],
            'hostId' => 89,
            'jwt' => 'native-jwt',
        ]], $runtime->callsFor('createSecurityRule'));

        $deleted = $securityGroups->deleteSecurityRuleForUser(new User, 911, 44, 7);
        $this->assertSame('规则已删除', $deleted['message']);
        $this->assertSame([[
            'supplier' => $supplier,
            'groupId' => 44,
            'ruleId' => 7,
            'hostId' => 89,
            'jwt' => 'native-jwt',
        ]], $runtime->callsFor('deleteSecurityRule'));
    }

    public function test_it_reports_a_missing_security_group_module_as_unsupported(): void
    {
        $supplier = $this->makeSupplier();
        $localService = new Service;
        $localService->id = 904;
        $runtime = new class
        {
            public function login(Supplier $supplier): string
            {
                return 'custom-jwt';
            }
        };
        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($localService);
        $detailService->shouldReceive('resolveManagedSupplierAndHost')->once()->with($localService)->andReturn([$supplier, 71462]);
        $detailService->shouldReceive('resolveRuntimeCapabilityForSupplier')->once()->with($supplier)->andReturn($runtime);
        $detailService
            ->shouldReceive('fetchSupportedModules')
            ->once()
            ->with($supplier, 71462, 'custom-jwt', true)
            ->andReturn([
                'status' => 200,
                'data' => [
                    ['type' => 'custom', 'function' => 'exitRescue', 'name' => '退出救援系统'],
                ],
            ]);
        $transformService = Mockery::mock(ServiceTransformService::class);
        $transformService->shouldReceive('canManageService')->once()->with($localService)->andReturnTrue();

        $securityGroups = new ServiceSecurityGroupService(
            Mockery::mock(OperationLogService::class),
            $detailService,
            $transformService,
            Mockery::mock(ServiceNatService::class),
        );

        $result = $securityGroups->getSecurityGroupsForUser(new User, 904, true);

        $this->assertFalse($result['supported']);
        $this->assertNotSame('', $result['message']);
        $this->assertSame('', $result['error']);
        $this->assertFalse($result['can_create']);
    }

    private function makeSupplier(): Supplier
    {
        $supplier = new Supplier;
        $supplier->id = 10;

        return $supplier;
    }

    /**
     * 全能力驱动：把每次调用连实参一起记下来，供断言「到底调到了哪个方法、传了什么」。
     */
    private function makeFullNativeDriver(): object
    {
        return new class
        {
            /** @var array<int, array{method: string, args: array<string, mixed>}> */
            public array $calls = [];

            public function login(Supplier $supplier): string
            {
                return 'native-jwt';
            }

            public function getSecurityGroups(Supplier $supplier, int $page, int $limit, ?string $jwt = null): array
            {
                return ['status' => 200, 'data' => ['list' => []]];
            }

            public function applySecurityGroup(Supplier $supplier, int $groupId, int $hostId = 0, ?string $jwt = null): array
            {
                $this->calls[] = ['method' => 'applySecurityGroup', 'args' => compact('supplier', 'groupId', 'hostId', 'jwt')];

                return ['status' => 200, 'msg' => '安全组已应用'];
            }

            public function createSecurityGroup(Supplier $supplier, array $params, ?string $jwt = null): array
            {
                $this->calls[] = ['method' => 'createSecurityGroup', 'args' => compact('supplier', 'params', 'jwt')];

                return ['status' => 200, 'msg' => '安全组创建成功'];
            }

            public function deleteSecurityGroup(Supplier $supplier, int $groupId, int $hostId = 0, ?string $jwt = null): array
            {
                $this->calls[] = ['method' => 'deleteSecurityGroup', 'args' => compact('supplier', 'groupId', 'hostId', 'jwt')];

                return ['status' => 200, 'msg' => '安全组已删除'];
            }

            public function getSecurityGroupRules(Supplier $supplier, int $groupId, int $hostId = 0, ?string $jwt = null): array
            {
                $this->calls[] = ['method' => 'getSecurityGroupRules', 'args' => compact('supplier', 'groupId', 'hostId', 'jwt')];

                return ['status' => 200, 'data' => ['list' => []]];
            }

            public function createSecurityRule(Supplier $supplier, int $groupId, array $params, int $hostId = 0, ?string $jwt = null): array
            {
                $this->calls[] = ['method' => 'createSecurityRule', 'args' => compact('supplier', 'groupId', 'params', 'hostId', 'jwt')];

                return ['status' => 200, 'msg' => '规则创建成功'];
            }

            public function deleteSecurityRule(Supplier $supplier, int $groupId, int $ruleId, int $hostId = 0, ?string $jwt = null): array
            {
                $this->calls[] = ['method' => 'deleteSecurityRule', 'args' => compact('supplier', 'groupId', 'ruleId', 'hostId', 'jwt')];

                return ['status' => 200, 'msg' => '规则已删除'];
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
     * 固定上下文的被测服务：绕过上游解析（那部分由别的用例覆盖），
     * 只验证「能力分派」本身；forgetSecurityGroupContextCache 同样短路，避免多余查询。
     *
     * @param  array<string, mixed>  $context
     */
    private function makeStubbedService(object $detailService, object $operationLogs, array $context, ?object $transformService = null): ServiceSecurityGroupService
    {
        $service = new class($operationLogs, $detailService, $transformService ?? Mockery::mock(ServiceTransformService::class), Mockery::mock(ServiceNatService::class)) extends ServiceSecurityGroupService
        {
            /** @var array<string, mixed> */
            public array $stubbedContext = [];

            public function resolveSecurityGroupContext(Service $service, bool $fresh = false): array
            {
                return $this->stubbedContext;
            }

            public function forgetSecurityGroupContextCache(Service $service): void
            {
                // 测试上下文是构造出来的，没有真实缓存需要失效
            }
        };
        $service->stubbedContext = $context;

        return $service;
    }
}
