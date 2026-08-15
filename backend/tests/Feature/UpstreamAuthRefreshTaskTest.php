<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Automation\ScheduleTaskTriggerService;
use Tests\TestCase;

class UpstreamAuthRefreshTaskTest extends TestCase
{
    public function test_trigger_service_supports_new_auth_refresh_key(): void
    {
        $service = app(ScheduleTaskTriggerService::class);

        $this->assertTrue($service->supports('refresh-hosting-panel-auth'));
        $this->assertFalse($service->supports('refresh-zjmf-jwt'));
    }
}
