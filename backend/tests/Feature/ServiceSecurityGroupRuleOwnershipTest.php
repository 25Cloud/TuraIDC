<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Models\Supplier;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServiceNatService;
use App\Services\ClientServiceConsole\ServiceSecurityGroupService;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Services\System\OperationLogService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ServiceSecurityGroupRuleOwnershipTest extends TestCase
{
    #[Test]
    public function it_rejects_rule_id_that_does_not_belong_to_target_group(): void
    {
        $service = $this->makeServiceWithUpstreamRules(55, [
            ['id' => 9001, 'port' => '80'],
        ]);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('安全组规则不存在或不属于当前安全组');

        $this->invokePrivateMethod($service, 'assertSecurityRuleBelongsToGroup', [
            $this->stubService(),
            $this->customContext(),
            55,
            9002,
        ]);
    }

    #[Test]
    public function it_allows_rule_id_that_belongs_to_target_group(): void
    {
        $service = $this->makeServiceWithUpstreamRules(55, [
            ['id' => 9001, 'port' => '80'],
        ]);

        $this->invokePrivateMethod($service, 'assertSecurityRuleBelongsToGroup', [
            $this->stubService(),
            $this->customContext(),
            55,
            9001,
        ]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_rejects_native_context_for_rule_ownership_assertion(): void
    {
        $service = $this->makeServiceWithUpstreamRules(55, []);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('当前上游仅支持安全组列表和应用操作');

        $this->invokePrivateMethod($service, 'assertSecurityRuleBelongsToGroup', [
            $this->stubService(),
            ['mode' => 'native'],
            55,
            9001,
        ]);
    }

    #[Test]
    public function it_accepts_preview_shaped_rule_list_from_upstream(): void
    {
        $service = $this->makeServiceWithUpstreamRules(55, ['preview' => [['id' => 9001, 'port' => '80']]]);

        $this->invokePrivateMethod($service, 'assertSecurityRuleBelongsToGroup', [
            $this->stubService(),
            $this->customContext(),
            55,
            9001,
        ]);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function it_ignores_scalar_rows_when_matching_rule_id(): void
    {
        $service = $this->makeServiceWithUpstreamRules(55, [9001, 'str-row', ['id' => 9002]]);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('安全组规则不存在或不属于当前安全组');

        $this->invokePrivateMethod($service, 'assertSecurityRuleBelongsToGroup', [
            $this->stubService(),
            $this->customContext(),
            55,
            9001,
        ]);
    }

    private function stubService(): Service
    {
        // 免启动 Service 实例：bootSoftDeletes 会触发 Schema facade，纯单测进程不可用。
        return (new \ReflectionClass(Service::class))->newInstanceWithoutConstructor();
    }

    private function customContext(): array
    {
        return [
            'mode' => 'custom',
            'supplier' => new Supplier,
            'endpoint' => 'https://upstream.test/security',
            'jwt' => 'stub-jwt',
        ];
    }

    /**
     * 用 mock 的 ServiceDetailService 与匿名 runtime 桩掉上游交互，
     * 让真实 callSecurityGroupAction → extractPayload → 归属匹配链路跑通。
     */
    private function makeServiceWithUpstreamRules(int $groupId, array $ruleList): ServiceSecurityGroupService
    {
        $detailService = $this->createMock(ServiceDetailService::class);
        $detailService->method('resolveRuntimeCapabilityForSupplier')->willReturn(new class($groupId, $ruleList) {
            public function __construct(
                private readonly int $groupId,
                private readonly array $ruleList,
            ) {}

            public function submitCustomModuleAction(Supplier $supplier, string $endpoint, array $payload, string $jwt): array
            {
                unset($supplier, $endpoint, $jwt);

                return [
                    'status' => 200,
                    'data' => ['list' => $payload['id'] === $this->groupId ? $this->ruleList : []],
                ];
            }
        });
        $detailService->method('extractPayload')->willReturnCallback(
            static fn (array $response): array => is_array($response['data'] ?? null) ? $response['data'] : $response
        );

        return new ServiceSecurityGroupService(
            $this->createMock(OperationLogService::class),
            $detailService,
            $this->createMock(ServiceTransformService::class),
            $this->createMock(ServiceNatService::class),
        );
    }

    private function invokePrivateMethod(object $target, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $arguments);
    }
}
