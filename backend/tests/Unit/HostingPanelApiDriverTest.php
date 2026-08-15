<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Upstream\Contracts\ProvidesConsoleRuntime;
use App\Services\Upstream\Contracts\ProvidesProvisioning;
use App\Services\Upstream\Contracts\ProvidesRenewal;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiDriver;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use App\Services\Upstream\ProviderKey;
use PHPUnit\Framework\TestCase;

class HostingPanelApiDriverTest extends TestCase
{
    public function test_it_reports_key_label_and_capabilities(): void
    {
        $transport = $this->createMock(HostingPanelApiTransport::class);
        $driver = new HostingPanelApiDriver($transport);

        $this->assertSame(ProviderKey::HOSTING_PANEL_API, $driver->key());
        $this->assertSame('主机面板接口', $driver->label());
        $this->assertContains(ProvidesProvisioning::class, $driver->capabilities());
        $this->assertContains(ProvidesRenewal::class, $driver->capabilities());
        $this->assertTrue($driver->supports(ProvidesProvisioning::class));
        $this->assertTrue($driver->supports(ProvidesConsoleRuntime::class));
        $this->assertSame($transport, $driver->resolve(ProvidesProvisioning::class));
    }
}
