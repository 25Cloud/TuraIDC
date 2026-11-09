<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServiceIsoService;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Services\System\OperationLogService;
use Mockery;
use Tests\TestCase;

/**
 * ISO 面板：可选镜像、当前挂载、启动项，以及挂载/卸载/改启动项的入参校验。
 */
class ServiceIsoConsoleApiTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_panel_exposes_images_current_iso_and_boot_order(): void
    {
        $service = $this->makeService(920);
        $runtime = new class
        {
            public function getIsoImages(Supplier $supplier, int $hostId, ?string $jwt = null): array
            {
                return ['status' => 200, 'data' => [
                    'list' => [
                        ['id' => '12', 'name' => 'ubuntu-22.04.iso'],
                        ['id' => '', 'name' => '空 ID 应被丢掉'],
                    ],
                    'current_iso' => '12',
                    'bootorder' => 'cdrom',
                ]];
            }

            public function mountIso(Supplier $supplier, int $hostId, string $isoId, ?string $jwt = null): array
            {
                return ['status' => 200, 'msg' => 'ok'];
            }

            public function unmountIso(Supplier $supplier, int $hostId, ?string $jwt = null): array
            {
                return ['status' => 200, 'msg' => 'ok'];
            }

            public function setBootOrder(Supplier $supplier, int $hostId, string $order, ?string $jwt = null): array
            {
                return ['status' => 200, 'msg' => 'ok'];
            }
        };

        $detailService = $this->mockDetail($service, $runtime);
        $detailService->shouldReceive('assertSuccess')->once()->with(Mockery::type('array'), '读取 ISO 列表');
        $detailService->shouldReceive('extractPayload')->once()->andReturn([
            'list' => [
                ['id' => '12', 'name' => 'ubuntu-22.04.iso'],
                ['id' => '', 'name' => '空 ID 应被丢掉'],
            ],
            'current_iso' => '12',
            'bootorder' => 'cdrom',
        ]);

        $result = $this->makeServiceInstance($detailService, true)->getIsoPanelForUser(new User, 920);

        $this->assertTrue($result['supported']);
        $this->assertSame('', $result['error']);
        $this->assertCount(1, $result['images']);
        $this->assertSame('ubuntu-22.04.iso', $result['current_iso_name']);
        $this->assertTrue($result['can_mount']);
        $this->assertTrue($result['can_unmount']);
        $this->assertSame('cdrom', $result['bootorder']);
        $this->assertSame('光驱优先', $result['bootorder_label']);
        $this->assertSame(['disk', 'cdrom'], array_column($result['bootorder_options'], 'value'));
    }

    public function test_panel_reports_unsupported_when_driver_has_no_iso_capability(): void
    {
        $service = $this->makeService(921);
        $runtime = new class
        {
            public function login(Supplier $supplier): string
            {
                return 'jwt';
            }
        };

        $result = $this->makeServiceInstance($this->mockDetail($service, $runtime), true)
            ->getIsoPanelForUser(new User, 921);

        $this->assertFalse($result['supported']);
        $this->assertNotSame('', $result['message']);
        $this->assertSame([], $result['images']);
        $this->assertFalse($result['can_mount']);
    }

    public function test_mount_rejects_an_empty_iso_id(): void
    {
        $service = $this->makeService(922);
        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($service);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('请选择要挂载的 ISO');

        $this->makeServiceInstance($detailService, true)->mountIsoForUser(new User, 922, ['iso_id' => ' ']);
    }

    public function test_mount_forwards_the_iso_and_writes_an_operation_log(): void
    {
        $service = $this->makeService(923);
        $runtime = new class
        {
            public array $request = [];

            public function mountIso(Supplier $supplier, int $hostId, string $isoId, ?string $jwt = null): array
            {
                $this->request = compact('hostId', 'isoId', 'jwt');

                return ['status' => 200, 'msg' => 'ISO挂载成功'];
            }
        };

        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($service);
        $detailService->shouldReceive('resolveUpstreamContext')->once()->andReturn([$runtime, $this->makeSupplier(), 88, 'jwt']);
        $detailService->shouldReceive('assertSuccess')->once();

        $logs = Mockery::mock(OperationLogService::class);
        $logs->shouldReceive('writeServiceConsoleLog')
            ->once()
            ->with($service, 'service.console.iso.mount', Mockery::on(
                static fn (array $detail): bool => $detail['category'] === 'iso'
                    && $detail['iso_id'] === '12'
                    && $detail['host_id'] === 88
            ), Mockery::type('array'));

        $result = $this->makeServiceInstance($detailService, true, $logs)
            ->mountIsoForUser(new User, 923, ['iso_id' => '12']);

        $this->assertSame('ISO挂载成功', $result['message']);
        $this->assertSame(['hostId' => 88, 'isoId' => '12', 'jwt' => 'jwt'], $runtime->request);
    }

    public function test_set_boot_order_rejects_values_outside_the_whitelist(): void
    {
        $service = $this->makeService(924);
        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($service);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('启动项只能是硬盘优先或光驱优先');

        $this->makeServiceInstance($detailService, true)
            ->setBootOrderForUser(new User, 924, ['bootorder' => 'network']);
    }

    public function test_unmount_requires_the_upstream_capability(): void
    {
        $service = $this->makeService(925);
        $runtime = new class
        {
            public function getIsoImages(Supplier $supplier, int $hostId, ?string $jwt = null): array
            {
                return ['status' => 200, 'data' => ['list' => [], 'current_iso' => '12']];
            }
        };

        $detailService = $this->mockDetail($service, $runtime);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('当前上游不提供 ISO 卸载能力');

        $this->makeServiceInstance($detailService, true)->unmountIsoForUser(new User, 925);
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

    private function makeServiceInstance(Mockery\MockInterface $detailService, bool $manageable, ?Mockery\MockInterface $logs = null): ServiceIsoService
    {
        $transformService = Mockery::mock(ServiceTransformService::class);
        $transformService->shouldReceive('canManageService')->andReturn($manageable);

        return new ServiceIsoService(
            $logs ?? Mockery::mock(OperationLogService::class),
            $detailService,
            $transformService,
        );
    }
}
