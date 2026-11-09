<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServiceHostConfigService;
use App\Services\System\OperationLogService;
use Mockery;
use Tests\TestCase;

/**
 * 管理端改配：白名单与范围校验、能力探测、参数下发与日志。
 *
 * 这里只验证「服务层怎么用驱动」，不发任何 HTTP。
 */
class ServiceHostConfigServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_rejects_an_empty_change_set_before_touching_the_upstream(): void
    {
        $service = $this->makeService(930);
        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($service);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('请至少填写一项要变更的规格');

        $this->makeServiceInstance($detailService)->changeConfigForService(new User, 930, [
            'cpu' => '',
            'memory' => null,
        ]);
    }

    public function test_it_rejects_values_outside_the_whitelist_range(): void
    {
        $service = $this->makeService(931);
        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($service);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('CPU 核数');

        $this->makeServiceInstance($detailService)->changeConfigForService(new User, 931, ['cpu' => 999]);
    }

    public function test_it_rejects_non_numeric_values(): void
    {
        $service = $this->makeService(932);
        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($service);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('必须是数字');

        $this->makeServiceInstance($detailService)->changeConfigForService(new User, 932, ['memory' => '2G']);
    }

    public function test_it_requires_the_driver_to_expose_the_capability(): void
    {
        $service = $this->makeService(933);
        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($service);
        $detailService
            ->shouldReceive('resolveUpstreamContext')
            ->once()
            ->andReturn([new class {}, $this->makeSupplier(), 88, 'jwt']);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('当前上游不支持在线变更规格');

        $this->makeServiceInstance($detailService)->changeConfigForService(new User, 933, ['cpu' => 4]);
    }

    public function test_it_forwards_only_the_requested_fields_and_writes_an_operation_log(): void
    {
        $service = $this->makeService(934);
        $runtime = new class
        {
            public array $request = [];

            public function changeHostConfig(Supplier $supplier, int $hostId, array $params, ?string $jwt = null): array
            {
                $this->request = compact('hostId', 'params', 'jwt');

                return ['status' => 200, 'msg' => '规格变更已提交', 'data' => [
                    'applied' => ['cpu', 'memory'],
                    'host_detail' => ['id' => 88, 'cpu' => 4, 'memory' => 8192],
                ]];
            }
        };

        $detailService = Mockery::mock(ServiceDetailService::class);
        $detailService->shouldReceive('findUserService')->once()->andReturn($service);
        $detailService->shouldReceive('resolveUpstreamContext')->once()->andReturn([$runtime, $this->makeSupplier(), 88, 'jwt']);
        $detailService->shouldReceive('assertSuccess')->once();
        $detailService->shouldReceive('extractPayload')->once()->andReturn([
            'applied' => ['cpu', 'memory'],
            'host_detail' => ['id' => 88, 'cpu' => 4, 'memory' => 8192],
        ]);
        $detailService->shouldReceive('syncServiceFromRemote')->once()->with($service, ['id' => 88, 'cpu' => 4, 'memory' => 8192]);

        $logs = Mockery::mock(OperationLogService::class);
        $logs->shouldReceive('writeServiceConsoleLog')
            ->once()
            ->with($service, 'service.console.host_config.change', Mockery::on(
                static fn (array $detail): bool => $detail['category'] === 'host_config'
                    && $detail['host_id'] === 88
                    && $detail['applied'] === ['cpu', 'memory']
            ), Mockery::type('array'));

        $result = $this->makeServiceInstance($detailService, $logs)->changeConfigForService(
            new User,
            934,
            ['cpu' => 4, 'memory' => 8192, 'in_bw' => '', 'flow' => null, 'snap_num' => 0]
        );

        $this->assertSame('规格变更已提交', $result['message']);
        $this->assertSame(['cpu', 'memory'], $result['applied']);
        $this->assertSame(88, $runtime->request['hostId']);
        // 空值不进请求；0 只在配额字段里算「真的改成 0」
        $this->assertSame(['cpu' => 4, 'memory' => 8192, 'snap_num' => 0], $runtime->request['params']);
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

    private function makeServiceInstance(Mockery\MockInterface $detailService, ?Mockery\MockInterface $logs = null): ServiceHostConfigService
    {
        return new ServiceHostConfigService(
            $logs ?? Mockery::mock(OperationLogService::class),
            $detailService,
        );
    }
}
