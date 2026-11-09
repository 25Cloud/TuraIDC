<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServiceSnapshotService;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Services\System\OperationLogService;
use Mockery;
use Tests\TestCase;

/**
 * 快照 / 备份面板：能力探测、三态、按类型分组、写操作参数与日志。
 *
 * 全部用假驱动（匿名类）+ Mockery 的 detailService，不打数据库也不打上游。
 */
class ServiceSnapshotConsoleApiTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_groups_rows_by_type_and_exposes_capability_flags(): void
    {
        $service = $this->makeService(910);
        $runtime = new class
        {
            public function getSnapshots(Supplier $supplier, int $hostId, ?string $jwt = null): array
            {
                return ['status' => 200, 'data' => ['list' => [
                    ['id' => 1, 'name' => '开盘快照', 'type' => 'snap', 'disk_id' => 7, 'size_gb' => 1.5],
                    ['id' => 2, 'name' => '周备份', 'type' => 'backup', 'disk_id' => 7, 'size_gb' => 2],
                    ['id' => 3, 'name' => '未知类型', 'type' => 'weird'],
                ]]];
            }

            public function createSnapshot(Supplier $supplier, int $hostId, array $params, ?string $jwt = null): array
            {
                return ['status' => 200, 'msg' => 'ok'];
            }

            public function deleteSnapshot(Supplier $supplier, int $hostId, int $snapshotId, ?string $jwt = null): array
            {
                return ['status' => 200, 'msg' => 'ok'];
            }

            public function restoreSnapshot(Supplier $supplier, int $hostId, int $snapshotId, ?string $jwt = null): array
            {
                return ['status' => 200, 'msg' => 'ok'];
            }
        };

        $detailService = $this->mockDetail($service, $runtime);
        $detailService->shouldReceive('assertSuccess')->once()->with(Mockery::type('array'), '读取快照列表');
        $detailService->shouldReceive('extractPayload')->once()->andReturn([
            'list' => [
                ['id' => 1, 'name' => '开盘快照', 'type' => 'snap', 'disk_id' => 7, 'size_gb' => 1.5],
                ['id' => 2, 'name' => '周备份', 'type' => 'backup', 'disk_id' => 7, 'size_gb' => 2],
                ['id' => 3, 'name' => '未知类型', 'type' => 'weird'],
            ],
        ]);

        $result = $this->makeServiceInstance($detailService, true)->getSnapshotsForUser(new User, 910);

        $this->assertTrue($result['supported']);
        $this->assertSame('', $result['error']);
        $this->assertTrue($result['can_create']);
        // 未知 type 归到快照组，不允许出现「两组都不在」的丢行
        $this->assertCount(2, $result['snapshots']);
        $this->assertCount(1, $result['backups']);
        $this->assertSame('backup', $result['backups'][0]['type']);
        $this->assertTrue($result['snapshots'][0]['can_delete']);
        $this->assertTrue($result['snapshots'][0]['can_restore']);
        $this->assertSame(1.5, $result['snapshots'][0]['size_gb']);
    }

    public function test_it_reports_unsupported_when_driver_has_no_snapshot_capability(): void
    {
        $service = $this->makeService(911);
        $runtime = new class
        {
            public function login(Supplier $supplier): string
            {
                return 'jwt';
            }
        };

        $result = $this->makeServiceInstance($this->mockDetail($service, $runtime), true)
            ->getSnapshotsForUser(new User, 911);

        $this->assertFalse($result['supported']);
        $this->assertNotSame('', $result['message']);
        $this->assertSame('', $result['error']);
        $this->assertFalse($result['can_create']);
        $this->assertSame([], $result['snapshots']);
    }

    public function test_it_reports_unsupported_when_service_is_not_manageable(): void
    {
        $service = $this->makeService(912);
        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($service);

        $result = $this->makeServiceInstance($detailService, false)->getSnapshotsForUser(new User, 912);

        $this->assertFalse($result['supported']);
        $this->assertSame('当前服务暂不支持快照管理', $result['message']);
    }

    public function test_it_turns_upstream_failures_into_a_readable_error(): void
    {
        $service = $this->makeService(913);
        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($service);
        $detailService
            ->shouldReceive('resolveUpstreamContext')
            ->once()
            ->andThrow(new BusinessException('当前服务未接入可控的上游主机', 42200));

        $result = $this->makeServiceInstance($detailService, true)->getSnapshotsForUser(new User, 913);

        $this->assertTrue($result['supported']);
        $this->assertSame('读取快照失败，请稍后重试', $result['error']);
        $this->assertSame([], $result['snapshots']);
    }

    public function test_create_rejects_an_empty_name(): void
    {
        $service = $this->makeService(914);
        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($service);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('请填写快照名称');

        $this->makeServiceInstance($detailService, true)
            ->createSnapshotForUser(new User, 914, ['name' => '   ']);
    }

    public function test_create_forwards_params_and_writes_an_operation_log(): void
    {
        $service = $this->makeService(915);
        $runtime = new class
        {
            public array $request = [];

            public function createSnapshot(Supplier $supplier, int $hostId, array $params, ?string $jwt = null): array
            {
                $this->request = compact('hostId', 'params', 'jwt');

                return ['status' => 200, 'msg' => '快照创建任务发起成功，请稍后查看'];
            }
        };

        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($service);
        $detailService->shouldReceive('resolveUpstreamContext')->once()->andReturn([$runtime, $this->makeSupplier(), 88, 'jwt']);
        $detailService->shouldReceive('assertSuccess')->once();

        $logs = Mockery::mock(OperationLogService::class);
        $logs->shouldReceive('writeServiceConsoleLog')
            ->once()
            ->with($service, 'service.console.snapshot.create', Mockery::on(
                static fn (array $detail): bool => $detail['category'] === 'snapshot'
                    && $detail['name'] === '开盘快照'
                    && $detail['type'] === 'backup'
                    && $detail['disk_id'] === 7
                    && $detail['host_id'] === 88
            ), Mockery::type('array'));

        $result = $this->makeServiceInstance($detailService, true, $logs)
            ->createSnapshotForUser(new User, 915, ['name' => '开盘快照', 'type' => 'backup', 'disk_id' => 7]);

        $this->assertSame('快照创建任务发起成功，请稍后查看', $result['message']);
        $this->assertSame(['name' => '开盘快照', 'type' => 'backup', 'disk_id' => 7], $runtime->request['params']);
        $this->assertSame(88, $runtime->request['hostId']);
    }

    public function test_restore_message_states_that_instance_data_will_be_overwritten(): void
    {
        $service = $this->makeService(916);
        $runtime = new class
        {
            public function restoreSnapshot(Supplier $supplier, int $hostId, int $snapshotId, ?string $jwt = null): array
            {
                return ['status' => 200, 'msg' => ''];
            }
        };

        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($service);
        $detailService->shouldReceive('resolveUpstreamContext')->once()->andReturn([$runtime, $this->makeSupplier(), 88, 'jwt']);
        $detailService->shouldReceive('assertSuccess')->once();

        $logs = Mockery::mock(OperationLogService::class);
        $logs->shouldReceive('writeServiceConsoleLog')->once();

        $result = $this->makeServiceInstance($detailService, true, $logs)
            ->restoreSnapshotForUser(new User, 916, 5);

        $this->assertStringContainsString('数据将被覆盖', $result['message']);
    }

    private function makeService(int $id): Service
    {
        $service = new Service;
        $service->id = $id;

        return $service;
    }

    private function makeSupplier(): Supplier
    {
        $supplier = new Supplier;
        $supplier->id = 10;

        return $supplier;
    }

    private function mockDetail(Service $service, object $runtime): Mockery\MockInterface
    {
        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($service);
        $detailService->shouldReceive('resolveUpstreamContext')->andReturn([$runtime, $this->makeSupplier(), 88, 'jwt']);

        return $detailService;
    }

    private function makeServiceInstance(Mockery\MockInterface $detailService, bool $manageable, ?Mockery\MockInterface $logs = null): ServiceSnapshotService
    {
        $transformService = Mockery::mock(ServiceTransformService::class);
        $transformService->shouldReceive('canManageService')->andReturn($manageable);

        return new ServiceSnapshotService(
            $logs ?? Mockery::mock(OperationLogService::class),
            $detailService,
            $transformService,
        );
    }
}
