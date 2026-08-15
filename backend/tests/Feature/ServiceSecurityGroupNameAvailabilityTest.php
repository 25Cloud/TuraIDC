<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Service;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServiceNatService;
use App\Services\ClientServiceConsole\ServiceSecurityGroupService;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Services\System\OperationLogService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ServiceSecurityGroupNameAvailabilityTest extends TestCase
{
    #[Test]
    public function it_rejects_duplicate_names_found_in_unfiltered_upstream_groups(): void
    {
        $service = new class($this->createMock(OperationLogService::class), $this->createMock(ServiceDetailService::class), $this->createMock(ServiceTransformService::class), $this->createMock(ServiceNatService::class)) extends ServiceSecurityGroupService
        {
            public function resolveSecurityGroupContext(Service $service, bool $fresh = false): array
            {
                return [
                    'groups' => [],
                    'raw_groups' => [
                        ['id' => 1001, 'name' => '123'],
                        ['id' => 976, 'name' => '1'],
                    ],
                ];
            }
        };

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('安全组名称已存在，请换一个名称');

        $this->invokePrivateMethod($service, 'assertSecurityGroupNameAvailable', [new Service, '123']);
    }

    private function invokePrivateMethod(object $target, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $arguments);
    }
}
