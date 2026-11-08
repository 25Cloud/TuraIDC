<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Service;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServiceNatService;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Services\System\OperationLogService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 缓存键隔离：不同本地服务的 NAT 上下文键不能串。
 *
 * 必须继承框架的 TestCase 而不是 PHPUnit 的裸 TestCase：`new Service` 会触发
 * Service::booted() → DatabaseSchema 解析列信息，那一步要 config()，没有容器就会
 * 抛 "Class config does not exist"。以前这份用例靠「同进程更早的测试先 boot 过」
 * 蒙对，单独跑或换顺序必红。
 */
class ServiceNatCacheIsolationTest extends TestCase
{
    #[Test]
    public function it_builds_distinct_nat_context_cache_keys_for_different_local_services(): void
    {
        $service = new ServiceNatService(
            $this->createMock(OperationLogService::class),
            $this->createMock(ServiceDetailService::class),
            $this->createMock(ServiceTransformService::class),
        );

        $first = new Service;
        $first->id = 201;
        $first->user_id = 21;
        $first->provision_data = [
            'supplier_id' => 9,
            'upstream_host_id' => 88,
        ];

        $second = new Service;
        $second->id = 202;
        $second->user_id = 22;
        $second->provision_data = [
            'supplier_id' => 9,
            'upstream_host_id' => 88,
        ];

        $firstKey = $this->invokePrivateMethod($service, 'buildNatAclContextCacheKey', [$first]);
        $secondKey = $this->invokePrivateMethod($service, 'buildNatAclContextCacheKey', [$second]);

        $this->assertNotSame($firstKey, $secondKey);
        $this->assertSame('nat_acl_ctx:service:201', $firstKey);
        $this->assertSame('nat_acl_ctx:service:202', $secondKey);
    }

    private function invokePrivateMethod(object $target, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $arguments);
    }
}
