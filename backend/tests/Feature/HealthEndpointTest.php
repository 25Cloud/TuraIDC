<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\System\ProductionReadinessService;
use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_liveness_endpoint_does_not_depend_on_downstream_services(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', 'alive');
    }

    public function test_readiness_endpoint_returns_healthy_component_projection(): void
    {
        $this->app->instance(ProductionReadinessService::class, $this->fakeReadiness(true));

        $this->getJson('/api/ready')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.checks.scheduler', true);
    }

    public function test_readiness_endpoint_returns_503_without_exposing_internal_errors(): void
    {
        $this->app->instance(ProductionReadinessService::class, $this->fakeReadiness(false));

        $this->getJson('/api/ready')
            ->assertStatus(503)
            ->assertJsonPath('code', 50300)
            ->assertJsonPath('data.status', 'not_ready')
            ->assertJsonMissingPath('data.error');
    }

    private function fakeReadiness(bool $ready): ProductionReadinessService
    {
        return new class($ready) extends ProductionReadinessService
        {
            public function __construct(private readonly bool $ready) {}

            public function check(): array
            {
                return [
                    'ready' => $this->ready,
                    'checks' => [
                        'database' => $this->ready,
                        'cache' => $this->ready,
                        'storage' => $this->ready,
                        'scheduler' => $this->ready,
                    ],
                ];
            }
        };
    }
}
