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
use Mockery;
use Tests\TestCase;

class ServiceSecurityGroupNativeApiTest extends TestCase
{
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
        $this->assertTrue($context['groups'][0]['can_apply']);
        $this->assertTrue($context['groups'][0]['is_applied']);
        $this->assertCount(2, $context['raw_groups']);
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

        $securityGroups = new class($operationLogs, $detailService, Mockery::mock(ServiceTransformService::class), Mockery::mock(ServiceNatService::class)) extends ServiceSecurityGroupService
        {
            public array $nativeContext = [];

            public function resolveSecurityGroupContext(Service $service, bool $fresh = false): array
            {
                return $this->nativeContext;
            }
        };
        $securityGroups->nativeContext = [
            'mode' => 'native',
            'supplier' => $supplier,
            'host_id' => 89,
            'jwt' => 'native-jwt',
            'groups' => [['id' => 44, 'name' => '默认安全组']],
        ];

        $result = $securityGroups->applySecurityGroupForUser(new User, 902, 44);

        $this->assertSame('安全组已应用', $result['message']);
        $this->assertSame(['groupId' => 44, 'hostId' => 89, 'jwt' => 'native-jwt'], array_diff_key($runtime->applyRequest, ['supplier' => true]));
    }

    public function test_it_rejects_native_rule_requests_without_falling_back_to_custom_module_actions(): void
    {
        $localService = new Service;
        $localService->id = 903;
        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($localService);

        $securityGroups = new class(Mockery::mock(OperationLogService::class), $detailService, Mockery::mock(ServiceTransformService::class), Mockery::mock(ServiceNatService::class)) extends ServiceSecurityGroupService
        {
            public function resolveSecurityGroupContext(Service $service, bool $fresh = false): array
            {
                return ['mode' => 'native'];
            }
        };

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('当前上游仅支持安全组列表和应用操作');

        $securityGroups->getSecurityGroupRulesForUser(new User, 903, 44);
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
}
