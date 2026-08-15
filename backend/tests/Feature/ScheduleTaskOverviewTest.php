<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ScheduleTaskRun;
use App\Services\Automation\ScheduleTaskService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ScheduleTaskOverviewTest extends TestCase
{
    public function test_schedule_task_overview_exposes_mutex_and_automation_config_state(): void
    {
        $service = app(ScheduleTaskService::class);

        $overview = $service->overview();
        $environment = (array) ($overview['environment'] ?? []);
        $scheduleMutex = (array) ($environment['schedule_mutex'] ?? []);
        $automationConfig = (array) ($environment['automation_config'] ?? []);

        $this->assertArrayHasKey('enabled', $scheduleMutex);
        $this->assertArrayHasKey('degraded', $scheduleMutex);
        $this->assertArrayHasKey('mode', $scheduleMutex);
        $this->assertArrayHasKey('reason', $scheduleMutex);
        $this->assertArrayHasKey('cache_store', $scheduleMutex);
        $this->assertArrayHasKey('os_family', $scheduleMutex);
        $this->assertArrayHasKey('status', $automationConfig);
        $this->assertArrayHasKey('fallback_reason', $automationConfig);

        $this->assertContains(
            $automationConfig['status'],
            ['loaded', 'fallback_default']
        );
        $this->assertIsBool($scheduleMutex['enabled']);
        $this->assertIsBool($scheduleMutex['degraded']);
        $this->assertIsString((string) $scheduleMutex['mode']);
        $this->assertIsString((string) $automationConfig['fallback_reason']);

        $runsSummary = (array) ($overview['runs_summary'] ?? []);
        foreach (['active', 'stale', 'failed_24h', 'success_24h', 'manual_retry_24h'] as $key) {
            $this->assertArrayHasKey($key, $runsSummary, "Missing runs_summary.{$key}");
            $this->assertIsInt($runsSummary[$key]);
        }
    }

    public function test_schedule_task_overview_run_stats_summary_counts_visible_records(): void
    {
        if (! Schema::hasTable('schedule_task_runs')) {
            Schema::create('schedule_task_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('schedule_tick_id')->nullable()->index();
                $table->unsignedBigInteger('parent_run_id')->nullable()->index();
                $table->string('task_key', 120);
                $table->string('task_name', 160);
                $table->string('rule_description', 160)->nullable();
                $table->string('source', 40)->default('heartbeat');
                $table->string('queue', 80)->nullable();
                $table->string('status', 30)->default('queued');
                $table->unsignedSmallInteger('attempt')->default(1);
                $table->unsignedInteger('duration_ms')->nullable();
                $table->json('summary')->nullable();
                $table->text('error_msg')->nullable();
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('manual_retry_at')->nullable();
                $table->unsignedBigInteger('manual_retry_by')->nullable();
                $table->timestamps();
            });
        }

        $taskKey = 'overview-stats-'.bin2hex(random_bytes(4));
        $before = app(ScheduleTaskService::class)->overview()['runs_summary'];

        ScheduleTaskRun::query()->insert([
            ['task_key' => $taskKey, 'task_name' => '统计测试', 'source' => 'heartbeat', 'status' => 'success', 'queued_at' => now()->subHour(), 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()],
            ['task_key' => $taskKey, 'task_name' => '统计测试', 'source' => 'heartbeat', 'status' => 'failed', 'queued_at' => now()->subHour(), 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()],
            ['task_key' => $taskKey, 'task_name' => '统计测试', 'source' => 'manual_retry', 'status' => 'queued', 'queued_at' => now()->subHour(), 'created_at' => now()->subHour(), 'updated_at' => now()->subHour()],
            ['task_key' => $taskKey, 'task_name' => '统计测试', 'source' => 'heartbeat', 'status' => 'success', 'queued_at' => now()->subDays(2), 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)],
        ]);

        $summary = app(ScheduleTaskService::class)->overview()['runs_summary'];

        // 运行台账是共享测试库中的全局统计，按本测试插入前后的增量断言。
        $this->assertSame($before['active'] + 1, $summary['active']);
        $this->assertSame($before['failed_24h'] + 1, $summary['failed_24h']);
        $this->assertSame($before['success_24h'] + 1, $summary['success_24h']);
        $this->assertSame($before['manual_retry_24h'] + 1, $summary['manual_retry_24h']);
        $this->assertSame($before['stale'], $summary['stale']);

        DB::table('schedule_task_runs')->where('task_key', $taskKey)->delete();
    }

    public function test_schedule_task_overview_exposes_isolated_queue_commands(): void
    {
        $overview = app(ScheduleTaskService::class)->overview();
        $environment = (array) ($overview['environment'] ?? []);
        $commands = collect($overview['commands'] ?? []);

        $this->assertSame('provision,referral,notification,coupon,default', $environment['business_queue'] ?? null);
        $this->assertSame('automation', $environment['automation_queue'] ?? null);
        $this->assertStringContainsString('--with-schedule --without-vnc', (string) $commands->firstWhere('key', 'app_serve_schedule_without_vnc')['command']);
        $this->assertStringContainsString('--queue=provision,referral,notification,coupon,default', (string) $commands->firstWhere('key', 'queue_work_business')['command']);
        $this->assertStringContainsString('--queue=automation', (string) $commands->firstWhere('key', 'queue_work_automation')['command']);
        $vncCommand = $commands->firstWhere('key', 'vnc_relay');
        $this->assertIsArray($vncCommand);
        $this->assertStringEndsWith('vnc:relay', (string) ($vncCommand['command'] ?? ''));
    }

    public function test_schedule_task_overview_includes_product_upstream_config_sync_task(): void
    {
        $service = app(ScheduleTaskService::class);

        $overview = $service->overview();
        $tasks = collect($overview['tasks'] ?? []);
        $task = $tasks->firstWhere('key', 'product-upstream-config-sync');

        $this->assertIsArray($task);
        $this->assertSame('上游产品配置同步', $task['title'] ?? null);
        $this->assertSame('system', $task['source_type'] ?? null);
        $this->assertSame('系统任务', $task['source_label'] ?? null);
        $this->assertTrue((bool) ($task['manual_triggerable'] ?? false));
        $this->assertNull($task['declared_cadence'] ?? null);
        $this->assertSame('cron 0 0 * * *', $task['effective_cadence'] ?? null);
    }

    public function test_schedule_task_overview_exposes_declared_and_effective_cadence_for_every_task(): void
    {
        $service = app(ScheduleTaskService::class);

        $overview = $service->overview();
        $tasks = $overview['tasks'] ?? [];

        $this->assertNotEmpty($tasks);

        foreach ($tasks as $task) {
            $this->assertArrayHasKey('declared_cadence', $task, "Missing declared_cadence for {$task['key']}");
            $this->assertArrayHasKey('effective_cadence', $task, "Missing effective_cadence for {$task['key']}");
            $this->assertIsString($task['effective_cadence']);
            $this->assertNotSame('', $task['effective_cadence']);
        }
    }

    public function test_schedule_task_overview_does_not_expose_internal_or_deprecated_tasks_as_business_tasks(): void
    {
        $service = app(ScheduleTaskService::class);

        $overview = $service->overview();
        $tasks = collect($overview['tasks'] ?? []);

        $this->assertNull($tasks->firstWhere('key', 'queue-backlog-drain'));
        $this->assertNull($tasks->firstWhere('key', 'sync-processing-order-status'));
    }

    public function test_high_frequency_schedule_tasks_run_every_fifteen_minutes(): void
    {
        $service = app(ScheduleTaskService::class);

        $overview = $service->overview();
        $tasks = collect($overview['tasks'] ?? []);

        foreach ([
            'referral-release-rewards',
            'coupon-campaign-dispatch',
            'provision-retry-failed',
            'site-cache-warmup',
        ] as $taskKey) {
            $task = $tasks->firstWhere('key', $taskKey);

            $this->assertIsArray($task, 'Missing schedule task key: '.$taskKey);
            $this->assertSame('每次心跳', $task['expression'] ?? null, 'Unexpected schedule expression for '.$taskKey);
            $this->assertSame('15分钟', $task['effective_cadence'] ?? null, 'Unexpected effective cadence for '.$taskKey);
        }

        foreach (['service-auto-renew', 'reconcile-account-balance', 'compensate-recharge-invoices'] as $taskKey) {
            $task = $tasks->firstWhere('key', $taskKey);

            $this->assertIsArray($task, 'Missing schedule task key: '.$taskKey);
            $this->assertSame('60分钟', $task['effective_cadence'] ?? null, 'Unexpected effective cadence for '.$taskKey);
        }
    }
}
