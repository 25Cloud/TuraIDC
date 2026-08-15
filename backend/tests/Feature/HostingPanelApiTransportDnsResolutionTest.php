<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use ReflectionMethod;
use Tests\TestCase;

class HostingPanelApiTransportDnsResolutionTest extends TestCase
{
    public function test_literal_ip_bypasses_dns_lookup(): void
    {
        $client = new class extends HostingPanelApiTransport
        {
            protected function lookupHostAddresses(string $host): array
            {
                throw new \RuntimeException('lookup should not be called for literal IP');
            }
        };

        $result = $this->invokePrivateMethod($client, 'resolveHostAddresses', ['203.0.113.10']);

        $this->assertSame(['203.0.113.10'], $result);
    }

    public function test_reserved_addresses_are_allowed_in_local_environment(): void
    {
        app()->instance('env', 'local');

        $client = new class extends HostingPanelApiTransport
        {
            protected function lookupHostAddresses(string $host): array
            {
                return ['198.18.0.113', 'fdfe:dcba:9876::72'];
            }
        };

        $url = $this->invokePrivateMethod($client, 'buildUrl', [
            'https://www.meidecloud.com',
            '/v1/login_api',
        ]);

        $this->assertSame('https://www.meidecloud.com/v1/login_api', $url);
    }

    public function test_reserved_addresses_remain_blocked_outside_local_environment(): void
    {
        app()->instance('env', 'testing');
        $this->assertSame('testing', app()->environment());

        $client = new class extends HostingPanelApiTransport
        {
            protected function lookupHostAddresses(string $host): array
            {
                return ['198.18.0.113', 'fdfe:dcba:9876::72'];
            }
        };

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('供应商接口地址禁止解析到内网或保留地址');

        $this->invokePrivateMethod($client, 'buildUrl', [
            'https://reserved-upstream-v6.invalid',
            '/v1/login_api',
        ]);
    }

    private function invokePrivateMethod(object $target, string $method, array $arguments = []): mixed
    {
        $reflection = new ReflectionMethod(HostingPanelApiTransport::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($target, $arguments);
    }
}
