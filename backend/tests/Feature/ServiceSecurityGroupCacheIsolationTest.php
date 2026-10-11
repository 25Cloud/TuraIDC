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
        $service = $this->makeService();

        $first = $this->makeUnbootedService(101);
        $second = $this->makeUnbootedService(102);

        $firstKey = $this->invokePrivateMethod($service, 'buildSecurityGroupContextCacheKey', [$first, 9, 88]);
        $secondKey = $this->invokePrivateMethod($service, 'buildSecurityGroupContextCacheKey', [$second, 9, 88]);

        $this->assertNotSame($firstKey, $secondKey);
        $this->assertSame('sg_ctx:service:101:supplier:9:host:88', $firstKey);
        $this->assertSame('sg_ctx:service:102:supplier:9:host:88', $secondKey);
    }

    #[Test]
    public function it_builds_distinct_cache_keys_when_supplier_or_host_changes(): void
    {
        $service = $this->makeService();
        $target = $this->makeUnbootedService(101);

        $key = $this->invokePrivateMethod($service, 'buildSecurityGroupContextCacheKey', [$target, 9, 88]);
        $movedSupplier = $this->invokePrivateMethod($service, 'buildSecurityGroupContextCacheKey', [$target, 10, 88]);
        $movedHost = $this->invokePrivateMethod($service, 'buildSecurityGroupContextCacheKey', [$target, 9, 89]);

        $this->assertNotSame($key, $movedSupplier);
        $this->assertNotSame($key, $movedHost);
    }

    #[Test]
    public function it_excludes_supplier_model_jwt_and_html_from_cache_payload(): void
    {
        $service = $this->makeService();

        $payload = $this->invokePrivateMethod($service, 'buildSecurityGroupContextCachePayload', [[
            'mode' => 'custom',
            'supplier' => $this->makeUnbootedService(1),
            'supplier_id' => 9,
            'host_id' => 88,
            'jwt' => 'secret-token',
            'module_key' => 'security_group',
            'endpoint' => 'https://upstream.test/action',
            'groups' => [['id' => 1, 'name' => 'g']],
            'html' => '<html>page</html>',
        ]]);

        $this->assertArrayNotHasKey('supplier', $payload);
        $this->assertArrayNotHasKey('jwt', $payload);
        $this->assertArrayNotHasKey('html', $payload);
        $this->assertSame('custom', $payload['mode']);
        $this->assertSame('security_group', $payload['module_key']);
        $this->assertSame('https://upstream.test/action', $payload['endpoint']);
        $this->assertSame([['id' => 1, 'name' => 'g']], $payload['groups']);
    }

    private function makeService(): ServiceSecurityGroupService
    {
        return new ServiceSecurityGroupService(
            $this->createMock(OperationLogService::class),
            $this->createMock(ServiceDetailService::class),
            $this->createMock(ServiceTransformService::class),
            $this->createMock(ServiceNatService::class),
        );
    }

    /**
     * 绕过构造器创建 Service 实例：bootSoftDeletes 会触发 Schema facade，
     * 纯 PHPUnit 测试进程里没有应用容器，直接 new 会抛 RuntimeException。
     */
    private function makeUnbootedService(int $id): Service
    {
        $instance = (new \ReflectionClass(Service::class))->newInstanceWithoutConstructor();
        $instance->id = $id;

        return $instance;
    }

    private function invokePrivateMethod(object $target, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $arguments);
    }
}
