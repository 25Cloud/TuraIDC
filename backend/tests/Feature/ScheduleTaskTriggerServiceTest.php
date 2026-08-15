<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunHeartbeatTaskJob;
use App\Services\Automation\ScheduleTaskTriggerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScheduleTaskTriggerServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->clearServiceStatusSyncRuns();
    }

    protected function tearDown(): void
    {
        $this->clearServiceStatusSyncRuns();

        parent::tearDown();
    }

    public function test_dispatch_uses_heartbeat_job_in_tests(): void
    {
        Queue::fake();
        config()->set('queue.default', 'array');

        $service = app(ScheduleTaskTriggerService::class);

        $result = $service->dispatch('service-status-sync', 1);

        $this->assertSame('service-status-sync', $result['task'] ?? null);
        $this->assertSame('queue', $result['execution_mode'] ?? null);
        $this->assertIsString((string) ($result['title'] ?? ''));
        $this->assertNotSame('', trim((string) ($result['title'] ?? '')));
        Queue::assertPushed(RunHeartbeatTaskJob::class, fn (RunHeartbeatTaskJob $job): bool => $job->taskKey === 'service-status-sync'
            && $job->source === 'manual_trigger'
            && $job->adminUserId === 1);
    }

    public function test_queue_backlog_drain_is_not_a_manual_business_task(): void
    {
        $service = app(ScheduleTaskTriggerService::class);

        $this->assertFalse($service->supports('queue-backlog-drain'));
        $this->assertFalse($service->supports('sync-processing-order-status'));
    }

    private function clearServiceStatusSyncRuns(): void
    {
        if (Schema::hasTable('schedule_task_runs')) {
            DB::table('schedule_task_runs')
                ->where('task_key', 'service-status-sync')
                ->delete();
        }
    }
}
