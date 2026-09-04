<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Supplier;
use App\Services\ClientServiceConsole\ClientServiceConsoleService;
use App\Services\ClientServiceConsole\ServiceConsoleAreaService;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServiceNatService;
use App\Services\ClientServiceConsole\ServiceOverviewService;
use App\Services\ClientServiceConsole\ServicePowerService;
use App\Services\ClientServiceConsole\ServiceSecurityGroupService;
use App\Services\ClientServiceConsole\ServiceSuspensionService;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Services\ClientServiceConsole\ServiceVncService;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ClientServiceMonitorCacheBehaviorTest extends TestCase
{
    #[Test]
    public function it_reuses_the_same_batch_cache_key_within_one_monitor_bucket(): void
    {
        $service = $this->makeConsoleService();
        // 不执行 Eloquent 构造，避免触发 Service::bootSoftDeletes 里对 Schema Facade 的探测
        // （本类继承裸 PHPUnit TestCase，无 Laravel 应用上下文），其余方法保留真实实现。
        $target = $this->getMockBuilder(Service::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();
        $target->id = 67;
        $target->setDateFormat('Y-m-d H:i:s');
        $target->updated_at = Carbon::create(2026, 4, 13, 2, 30, 0);

        $payloadA = [
            'types' => ['memory', 'cpu'],
            'limit' => 4,
            'range' => [
                'preset' => '3h',
                'start' => 1_713_007_200_123,
                'end' => 1_713_018_000_123,
            ],
        ];
        $payloadB = [
            'types' => ['cpu', 'memory'],
            'limit' => 4,
            'range' => [
                'preset' => '3h',
                'start' => 1_713_007_260_000,
                'end' => 1_713_018_060_000,
            ],
        ];

        $keyA = $this->invokePrivateMethod($service, 'buildMonitorBatchResponseCacheKey', [$target, $payloadA]);
        $keyB = $this->invokePrivateMethod($service, 'buildMonitorBatchResponseCacheKey', [$target, $payloadB]);

        $this->assertSame($keyA, $keyB);
    }

    #[Test]
    public function it_builds_a_previous_bucket_lookup_key_for_monitor_chart_cache(): void
    {
        $service = $this->makeConsoleService();
        $supplier = new Supplier;
        $supplier->id = 2;

        $lookupKeys = $this->invokePrivateMethod($service, 'buildMonitorChartCacheLookupKeys', [
            $supplier,
            93542,
            'cpu',
            1_713_007_200_123,
            1_713_018_000_123,
        ]);

        $currentBucketKey = $this->invokePrivateMethod($service, 'buildMonitorChartCacheKey', [
            $supplier,
            93542,
            'cpu',
            1_713_007_200_123,
            1_713_018_000_123,
        ]);
        $previousBucketKey = $this->invokePrivateMethod($service, 'buildMonitorChartCacheKey', [
            $supplier,
            93542,
            'cpu',
            1_713_006_900_123,
            1_713_017_700_123,
        ]);

        $this->assertSame([$currentBucketKey, $previousBucketKey], $lookupKeys);
    }

    private function makeConsoleService(): ClientServiceConsoleService
    {
        return new ClientServiceConsoleService(
            $this->createMock(ServiceOverviewService::class),
            $this->createMock(ServiceDetailService::class),
            $this->createMock(ServiceTransformService::class),
            $this->createMock(ServicePowerService::class),
            $this->createMock(ServiceSuspensionService::class),
            $this->createMock(ServiceVncService::class),
            $this->createMock(ServiceNatService::class),
            $this->createMock(ServiceSecurityGroupService::class),
            $this->createMock(ServiceConsoleAreaService::class),
        );
    }

    private function invokePrivateMethod(object $target, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $arguments);
    }
}
