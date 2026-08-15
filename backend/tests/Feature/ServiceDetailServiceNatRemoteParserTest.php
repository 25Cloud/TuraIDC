<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServiceResolverService;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Services\System\OperationLogService;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ServiceDetailServiceNatRemoteParserTest extends TestCase
{
    #[Test]
    public function it_parses_nat_remote_address_from_nat_acl_box_markup(): void
    {
        $service = $this->makeServiceDetailService();

        $result = $this->invokePrivateMethod($service, 'parseNatServiceDetailPage', [
            <<<'HTML'
<div>
  <label>远程地址：</label>
  <span id="nat_aclBox">222.211.73.53:30329</span>
</div>
HTML,
        ]);

        $this->assertSame('222.211.73.53:30329', $result['remote_address'] ?? null);
        $this->assertSame('222.211.73.53', $result['host'] ?? null);
        $this->assertSame(30329, $result['port'] ?? null);
    }

    #[Test]
    public function it_parses_nat_remote_address_from_meidecloud_label_layout_without_fixed_id(): void
    {
        $service = $this->makeServiceDetailService();

        $result = $this->invokePrivateMethod($service, 'parseNatServiceDetailPage', [
            <<<'HTML'
<div class="bg-primary rounded-sm d-flex flex-column justify-content-center text-white py-2 px-1 mb-2">
  <div class="d-flex justify-content-between align-items-center">
    <span>
      <label>远程地址：</label>
      <span class="remote-address-value">cloudhost1.meidecloud.com:33891</span>
    </span>
  </div>
</div>
HTML,
        ]);

        $this->assertSame('cloudhost1.meidecloud.com:33891', $result['remote_address'] ?? null);
        $this->assertSame('cloudhost1.meidecloud.com', $result['host'] ?? null);
        $this->assertSame(33891, $result['port'] ?? null);
    }

    private function makeServiceDetailService(): ServiceDetailService
    {
        return new ServiceDetailService(
            new ProviderResolver(new ProviderRegistry([])),
            $this->createMock(OperationLogService::class),
            $this->createMock(ServiceResolverService::class),
            $this->createMock(ServiceTransformService::class),
        );
    }

    private function invokePrivateMethod(object $target, string $method, array $arguments = []): mixed
    {
        $reflection = new \ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $arguments);
    }
}
