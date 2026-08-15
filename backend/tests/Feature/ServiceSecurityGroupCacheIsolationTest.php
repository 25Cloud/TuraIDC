<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Service;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServiceNatService;
use App\Services\ClientServiceConsole\ServiceSecurityGroupService;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Services\System\OperationLogService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ServiceSecurityGroupCacheIsolationTest extends TestCase
{
    #[Test]
    public function it_builds_distinct_security_group_context_cache_keys_for_different_local_services(): void
    {
        $service = new ServiceSecurityGroupService(
            $this->createMock(OperationLogService::class),
            $this->createMock(ServiceDetailService::class),
            $this->createMock(ServiceTransformService::class),
            $this->createMock(ServiceNatService::class),
        );

        $first = new Service;
        $first->id = 101;
        $first->user_id = 11;
        $first->provision_data = [
            'supplier_id' => 9,
            'upstream_host_id' => 88,
        ];

        $second = new Service;
        $second->id = 102;
        $second->user_id = 12;
        $second->provision_data = [
            'supplier_id' => 9,
            'upstream_host_id' => 88,
        ];

        $firstKey = $this->invokePrivateMethod($service, 'buildSecurityGroupContextCacheKey', [$first]);
        $secondKey = $this->invokePrivateMethod($service, 'buildSecurityGroupContextCacheKey', [$second]);

        $this->assertNotSame($firstKey, $secondKey);
        $this->assertSame('sg_ctx:service:101', $firstKey);
        $this->assertSame('sg_ctx:service:102', $secondKey);
    }

    private function invokePrivateMethod(object $target, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $arguments);
    }
}
