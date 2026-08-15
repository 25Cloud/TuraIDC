<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Integrations\Plugins\PluginDomain;
use App\Services\Integrations\Plugins\PluginProviderRegistry;
use App\Services\Integrations\Plugins\PluginScanner;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PluginProviderActivationTest extends TestCase
{
    public function test_addon_provider_registers_its_own_health_route(): void
    {
        app(PluginProviderRegistry::class)->activate(
            app(PluginScanner::class)->requireManifest(PluginDomain::ADDONS, 'zjmf_bridge')
        );

        $route = null;
        foreach (Route::getRoutes() as $candidate) {
            if ($candidate->getName() === 'zjmf.v1.health') {
                $route = $candidate;

                break;
            }
        }

        $this->assertNotNull($route);
        $this->assertSame('zjmf/v1/health', $route->uri());
    }
}
