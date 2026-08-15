<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use ReflectionClass;
use Tests\TestCase;

class HostingPanelApiTransportLogSanitizationTest extends TestCase
{
    public function test_it_redacts_sensitive_values_from_response_summaries(): void
    {
        $transport = new HostingPanelApiTransport;
        $method = (new ReflectionClass($transport))->getMethod('summarizeLogResponse');
        $method->setAccessible(true);

        $summary = $method->invoke($transport, [
            'status' => 200,
            'data' => [
                'client' => [
                    'account' => 'demo@example.com',
                    'email' => 'demo@example.com',
                    'phone' => '13800138000',
                    'username' => 'demo-user',
                    'ip_address' => '203.0.113.10',
                    'jwt' => 'jwt-secret',
                    'password' => 'plain-secret',
                    'message' => 'Authorization: Bearer abc.def.ghi from 203.0.113.10',
                ],
            ],
        ]);

        $encoded = json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('demo@example.com', $encoded);
        $this->assertStringNotContainsString('13800138000', $encoded);
        $this->assertStringNotContainsString('203.0.113.10', $encoded);
        $this->assertStringNotContainsString('demo-user', $encoded);
        $this->assertStringNotContainsString('jwt-secret', $encoded);
        $this->assertStringNotContainsString('plain-secret', $encoded);
        $this->assertStringNotContainsString('abc.def.ghi', $encoded);
        $this->assertStringContainsString('[REDACTED]', $encoded);
        $this->assertStringContainsString('***', $encoded);
    }
}
