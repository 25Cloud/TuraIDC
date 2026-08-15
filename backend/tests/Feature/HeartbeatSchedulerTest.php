<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunHeartbeatTaskJob;
use App\Models\ScheduleTaskRun;
use App\Models\ScheduleTick;
use App\Services\Automation\Heartbeat\CallbackScheduledTask;
use App\Services\Automation\Heartbeat\Contracts\ScheduledTask;
use App\Services\Automation\Heartbeat\Data\TaskContext;
use App\Services\Automation\Heartbeat\HeartbeatScheduler;
use App\Services\Automation\Heartbeat\HeartbeatTaskRegistry;
use App\Services\Automation\Heartbeat\HeartbeatTaskRunner;
use App\Services\Automation\Heartbeat\ScheduleRule;
use App\Services\Automation\Heartbeat\ScheduleTaskRunRepository;
use App\Services\Automation\Heartbeat\ScheduleTickRepository;
use App\Services\Automation\Heartbeat\TickSlot;
use App\Services\Automation\Heartbeat\TriggerRuleMatcher;
use App\Services\Automation\ScheduleTaskTriggerService;
use App\Services\System\ProductionReadinessService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class HeartbeatSchedulerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureHeartbeatTables();

        ScheduleTaskRun::query()
            ->whereIn('task_key', ['heartbeat-test-due', 'heartbeat-test-skip', 'product-upstream-config-sync', 'heartbeat-test-stale', 'heartbeat-test-dispatch-retry'])
            ->delete();
        ScheduleTick::query()
            ->whereBetween('slot_started_at', ['2026-07-05 12:00:00', '2026-07-05 12:15:00'])
            ->delete();
    }

    public function test_laravel_schedule_only_registers_heartbeat_liveness_and_queue_drain_events(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertSame(1, substr_count($output, 'scheduler:heartbeat'));
        $this->assertSame(1, substr_count($output, 'scheduler:liveness'));
        $this->assertSame(1, substr_count($output, 'queue:drain'));
        $this->assertSame(3, substr_count($output, '* * * * *'));
        $this->assertStringNotContainsString('接口认证刷新', $output);
    }

    public function test_all_registered_heartbeat_tasks_use_the_automation_queue(): void
    {
        foreach (app(HeartbeatTaskRegistry::class)->enabledTasks() as $task) {
            $this->assertSame('automation', $task->queue(), $task->key().' must use the automation queue.');
        }

        $this->assertSame('automation', (new RunHeartbeatTaskJob('log-archive'))->queue);
    }

    public function test_all_registered_heartbeat_tasks_use_the_configured_schedule_queue(): void
    {
        config()->set('queue.turaidc_schedule_queue', 'scheduled-tasks');

        foreach (app(HeartbeatTaskRegistry::class)->enabledTasks() as $task) {
            $this->assertSame('scheduled-tasks', $task->queue(), $task->key().' must use the configured schedule queue.');
        }

        $this->assertSame('scheduled-tasks', (new RunHeartbeatTaskJob('log-archive'))->queue);
    }

    public function test_log_archive_task_is_dispatched_at_two_am_through_heartbeat(): void
    {
        Queue::fake();

        $task = app(HeartbeatTaskRegistry::class)->get('log-archive');

        $this->assertSame('日志归档', $task->title());
        $this->assertSame('0 2 * * *', $task->triggers()[0]->describe());
        $this->assertSame(3600, $task->timeout());
        $this->assertSame(3660, $task->lockTtlSeconds());
        $this->assertFalse($task->manualTriggerable());

        ScheduleTaskRun::query()->where('task_key', 'log-archive')->delete();
        ScheduleTick::query()
            ->whereBetween('slot_started_at', ['2026-07-06 02:00:00', '2026-07-06 02:15:00'])
            ->delete();

        $registry = new class(app()) extends HeartbeatTaskRegistry
        {
            /** @var list<ScheduledTask> */
            public array $tasks = [];

            public function enabledTasks(): array
            {
                return $this->tasks;
            }
        };
        $registry->tasks = [$task];

        $scheduler = new HeartbeatScheduler(
            app(ScheduleTickRepository::class),
            app(ScheduleTaskRunRepository::class),
            $registry,
            app(TriggerRuleMatcher::class),
        );

        $summary = $scheduler->tick(CarbonImmutable::parse('2026-07-06 02:00:00', 'Asia/Shanghai'));

        $this->assertSame(['log-archive'], $summary->queuedTasks);
        Queue::assertPushed(RunHeartbeatTaskJob::class, fn (RunHeartbeatTaskJob $job): bool => $job->taskKey === 'log-archive'
            && $job->timeout === 3600);
        $this->assertDatabaseHas('schedule_task_runs', [
            'task_key' => 'log-archive',
            'status' => 'queued',
            'source' => 'heartbeat',
        ]);
    }

    public function test_log_archive_task_invokes_archive_command_with_execute_enabled(): void
    {
        $task = app(HeartbeatTaskRegistry::class)->get('log-archive');

        Artisan::shouldReceive('call')
            ->once()
            ->with('db:archive-logs', ['--execute' => true, '--json' => true])
            ->andReturn(0);
        Artisan::shouldReceive('output')
            ->once()
            ->andReturn('{"status":"completed"}');

        $summary = $task->handle(new TaskContext(
            taskKey: 'log-archive',
            source: 'heartbeat',
        ));

        $this->assertSame('db:archive-logs', $summary['command']);
        $this->assertSame(0, $summary['exit_code']);
        $this->assertSame(['--execute' => true, '--json' => true], $summary['parameters']);
    }

    public function test_heartbeat_dispatches_due_tasks_once_per_tick(): void
    {
        Queue::fake();
        config()->set('queue.turaidc_schedule_queue', 'scheduled-tasks');

        $registry = new class(app()) extends HeartbeatTaskRegistry
        {
            /** @var list<ScheduledTask> */
            public array $tasks = [];

            public function enabledTasks(): array
            {
                return $this->tasks;
            }

            public function get(string $taskKey): ScheduledTask
            {
                foreach ($this->tasks as $task) {
                    if ($task->key() === $taskKey) {
                        return $task;
                    }
                }

                return parent::get($taskKey);
            }
        };

        $registry->tasks = [
            new CallbackScheduledTask(
                key: 'heartbeat-test-due',
                title: 'Heartbeat Test Due',
                description: 'Test due task',
                category: 'Test',
                triggers: [ScheduleRule::everyTicks(1)],
                handler: fn (): array => ['ok' => true],
            ),
            new CallbackScheduledTask(
                key: 'heartbeat-test-skip',
                title: 'Heartbeat Test Skip',
                description: 'Test skipped task',
                category: 'Test',
                triggers: [ScheduleRule::cron('5 5 5 5 *')],
                handler: fn (): array => ['ok' => true],
            ),
        ];

        $scheduler = new HeartbeatScheduler(
            app(ScheduleTickRepository::class),
            app(ScheduleTaskRunRepository::class),
            $registry,
            app(TriggerRuleMatcher::class),
        );

        $summary = $scheduler->tick(CarbonImmutable::parse('2026-07-05 12:00:00', 'Asia/Shanghai'));

        $this->assertSame(['heartbeat-test-due'], $summary->queuedTasks);
        Queue::assertPushed(RunHeartbeatTaskJob::class, 1);
        Queue::assertPushed(RunHeartbeatTaskJob::class, fn (RunHeartbeatTaskJob $job): bool => $job->taskKey === 'heartbeat-test-due'
            && $job->timeout === 900
            && $job->queue === 'scheduled-tasks');
        Queue::assertNotPushed(RunHeartbeatTaskJob::class, fn (RunHeartbeatTaskJob $job): bool => $job->taskKey === 'heartbeat-test-skip');
        $this->assertDatabaseHas('schedule_task_runs', [
            'task_key' => 'heartbeat-test-due',
            'status' => 'queued',
            'source' => 'heartbeat',
        ]);

        $secondSummary = $scheduler->tick(CarbonImmutable::parse('2026-07-05 12:01:00', 'Asia/Shanghai'));

        $this->assertSame([], $secondSummary->queuedTasks);
        $this->assertSame(['heartbeat-test-due'], $secondSummary->duplicateTasks);
        Queue::assertPushed(RunHeartbeatTaskJob::class, 1);
    }

    public function test_repeated_heartbeat_updates_trigger_time_and_keeps_readiness_fresh_within_same_slot(): void
    {
        Queue::fake();

        $registry = new class(app()) extends HeartbeatTaskRegistry
        {
            public function enabledTasks(): array
            {
                return [];
            }
        };

        $scheduler = new HeartbeatScheduler(
            app(ScheduleTickRepository::class),
            app(ScheduleTaskRunRepository::class),
            $registry,
            app(TriggerRuleMatcher::class),
        );

        $scheduler->tick(CarbonImmutable::parse('2026-07-05 12:00:00', 'Asia/Shanghai'));
        $scheduler->tick(CarbonImmutable::parse('2026-07-05 12:04:00', 'Asia/Shanghai'));

        $tick = ScheduleTick::query()
            ->where('slot_started_at', '2026-07-05 12:00:00')
            ->sole();

        $this->assertSame('2026-07-05 12:04:00', $tick->triggered_at?->format('Y-m-d H:i:s'));
        $this->assertSame(1, ScheduleTick::query()
            ->where('slot_started_at', '2026-07-05 12:00:00')
            ->count());

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-05 12:04:30', 'Asia/Shanghai'));

        try {
            config(['health.scheduler_max_age_seconds' => 180]);

            $this->assertTrue(app(ProductionReadinessService::class)->check()['checks']['scheduler']);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_manual_trigger_uses_heartbeat_job_and_registry(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);
        config()->set('queue.turaidc_schedule_queue', 'scheduled-tasks');
        $this->ensureJobsTable();

        $result = app(ScheduleTaskTriggerService::class)->dispatch('product-upstream-config-sync', 123);

        $this->assertSame('product-upstream-config-sync', $result['task']);
        $this->assertSame('queue', $result['execution_mode']);
        Queue::assertPushed(RunHeartbeatTaskJob::class, function (RunHeartbeatTaskJob $job): bool {
            return $job->taskKey === 'product-upstream-config-sync'
                && $job->source === 'manual_trigger'
                && $job->adminUserId === 123
                && $job->taskRunId !== null
                && $job->timeout === 3600
                && $job->queue === 'scheduled-tasks';
        });
        $this->assertDatabaseHas('schedule_task_runs', [
            'task_key' => 'product-upstream-config-sync',
            'source' => 'manual_trigger',
            'status' => 'queued',
        ]);
    }

    public function test_heartbeat_coalesces_a_due_task_while_its_previous_run_is_active(): void
    {
        Queue::fake();

        $registry = new class(app()) extends HeartbeatTaskRegistry
        {
            /** @var list<ScheduledTask> */
            public array $tasks = [];

            public function enabledTasks(): array
            {
                return $this->tasks;
            }
        };

        $registry->tasks = [
            new CallbackScheduledTask(
                key: 'heartbeat-test-due',
                title: 'Heartbeat Test Due',
                description: 'Test due task',
                category: 'Test',
                triggers: [ScheduleRule::everyTicks(1)],
                handler: fn (): array => ['ok' => true],
            ),
        ];

        ScheduleTaskRun::query()->create([
            'task_key' => 'heartbeat-test-due',
            'task_name' => 'Heartbeat Test Due',
            'rule_description' => '每次心跳',
            'source' => 'heartbeat',
            'queue' => 'default',
            'status' => ScheduleTaskRun::STATUS_QUEUED,
            'queued_at' => now()->subMinute(),
        ]);

        $scheduler = new HeartbeatScheduler(
            app(ScheduleTickRepository::class),
            app(ScheduleTaskRunRepository::class),
            $registry,
            app(TriggerRuleMatcher::class),
        );

        $summary = $scheduler->tick(CarbonImmutable::parse('2026-07-05 12:15:00', 'Asia/Shanghai'));

        $this->assertSame([], $summary->queuedTasks);
        $this->assertSame(['heartbeat-test-due'], $summary->duplicateTasks);
        Queue::assertNothingPushed();
        $this->assertSame(1, ScheduleTaskRun::query()->where('task_key', 'heartbeat-test-due')->count());
    }

    public function test_manual_trigger_reuses_an_active_run_instead_of_enqueueing_a_duplicate(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);
        $this->ensureJobsTable();

        $first = app(ScheduleTaskTriggerService::class)->dispatch('product-upstream-config-sync', 123);
        $second = app(ScheduleTaskTriggerService::class)->dispatch('product-upstream-config-sync', 456);

        $this->assertSame('queue', $first['execution_mode']);
        $this->assertSame('already_queued', $second['execution_mode']);
        $this->assertArrayHasKey('task_run_id', $second);
        Queue::assertPushed(RunHeartbeatTaskJob::class, 1);
        $this->assertSame(1, ScheduleTaskRun::query()
            ->where('task_key', 'product-upstream-config-sync')
            ->where('status', ScheduleTaskRun::STATUS_QUEUED)
            ->count());
    }

    public function test_failed_job_marks_its_schedule_run_as_failed(): void
    {
        $run = ScheduleTaskRun::query()->create([
            'task_key' => 'heartbeat-test-due',
            'task_name' => 'Heartbeat Test Due',
            'rule_description' => '每次心跳',
            'source' => 'manual_trigger',
            'queue' => 'default',
            'status' => ScheduleTaskRun::STATUS_RUNNING,
            'queued_at' => now()->subMinute(),
            'started_at' => now()->subSeconds(30),
        ]);

        (new RunHeartbeatTaskJob('heartbeat-test-due', null, (int) $run->id, null, 'manual_trigger', 900))
            ->failed(new \RuntimeException('simulated queue failure'));

        $this->assertDatabaseHas('schedule_task_runs', [
            'id' => $run->id,
            'status' => ScheduleTaskRun::STATUS_FAILED,
            'error_msg' => 'simulated queue failure',
        ]);
    }

    public function test_retries_keep_the_original_schedule_run_start_time(): void
    {
        $run = ScheduleTaskRun::query()->create([
            'task_key' => 'heartbeat-test-due',
            'task_name' => 'Heartbeat Test Due',
            'rule_description' => '每次心跳',
            'source' => 'manual_trigger',
            'queue' => 'default',
            'status' => ScheduleTaskRun::STATUS_QUEUED,
            'queued_at' => now()->subMinute(),
        ]);
        $repository = app(ScheduleTaskRunRepository::class);

        $repository->markRunning((int) $run->id);
        $firstStartedAt = $run->fresh()->started_at?->toDateTimeString();
        $repository->markFailed((int) $run->id, 'retry me', 10);
        $repository->markRunning((int) $run->id);

        $this->assertSame($firstStartedAt, $run->fresh()->started_at?->toDateTimeString());
        $this->assertSame(ScheduleTaskRun::STATUS_RUNNING, $run->fresh()->status);
    }

    public function test_late_job_cannot_move_a_terminal_run_back_to_running_or_failed(): void
    {
        $run = ScheduleTaskRun::query()->create([
            'task_key' => 'heartbeat-test-due',
            'task_name' => 'Heartbeat Test Due',
            'rule_description' => '每次心跳',
            'source' => 'heartbeat',
            'queue' => 'automation',
            'status' => ScheduleTaskRun::STATUS_QUEUED,
            'queued_at' => now(),
        ]);
        $repository = app(ScheduleTaskRunRepository::class);

        $this->assertTrue($repository->markRunning((int) $run->id));
        $this->assertTrue($repository->markSucceeded((int) $run->id, ['ok' => true], 12));
        $this->assertFalse($repository->markRunning((int) $run->id));
        $this->assertFalse($repository->markTerminalFailed((int) $run->id, 'late failure', 20));
        $this->assertSame(ScheduleTaskRun::STATUS_SUCCESS, $run->fresh()->status);
    }

    public function test_same_slot_requeues_a_run_that_failed_before_dispatch(): void
    {
        Queue::fake();

        $task = new CallbackScheduledTask(
            key: 'heartbeat-test-dispatch-retry',
            title: '派发重试测试',
            description: '验证同槽位派发失败后的重派。',
            category: '测试',
            triggers: [ScheduleRule::everyTicks(1)],
            handler: static fn (): array => ['ok' => true],
        );
        $registry = new class(app()) extends HeartbeatTaskRegistry
        {
            /** @var list<ScheduledTask> */
            public array $tasks = [];

            public function enabledTasks(): array
            {
                return $this->tasks;
            }
        };
        $registry->tasks = [$task];

        $slot = CarbonImmutable::parse('2026-07-05 12:30:00', 'Asia/Shanghai');
        ScheduleTick::query()->where('slot_started_at', $slot)->delete();
        $tick = ScheduleTick::query()->create([
            'slot_started_at' => $slot,
            'global_number' => TickSlot::globalNumber($slot),
            'daily_index' => 3,
            'triggered_at' => $slot,
        ]);
        $run = ScheduleTaskRun::query()->create([
            'schedule_tick_id' => $tick->id,
            'task_key' => $task->key(),
            'task_name' => $task->title(),
            'rule_description' => '每次心跳',
            'source' => 'heartbeat',
            'queue' => $task->queue(),
            'status' => ScheduleTaskRun::STATUS_DISPATCH_FAILED,
            'error_msg' => '模拟派发失败',
            'finished_at' => now()->subMinute(),
        ]);

        $scheduler = new HeartbeatScheduler(
            app(ScheduleTickRepository::class),
            app(ScheduleTaskRunRepository::class),
            $registry,
            app(TriggerRuleMatcher::class),
        );

        $summary = $scheduler->tick($slot);

        $this->assertSame([$task->key()], $summary->queuedTasks);
        $requeuedRun = $run->fresh();
        $this->assertSame(ScheduleTaskRun::STATUS_QUEUED, $requeuedRun->status);
        $this->assertSame('模拟派发失败', $requeuedRun->summary['dispatch_failures'][0]['message'] ?? null);
        Queue::assertPushed(RunHeartbeatTaskJob::class, fn (RunHeartbeatTaskJob $job): bool => $job->taskRunId === (int) $run->id);
    }

    public function test_stale_run_with_missing_lease_timestamps_is_reclaimed_from_created_at(): void
    {
        $createdAt = now()->subHours(2);
        DB::table('schedule_task_runs')->insert([
            'task_key' => 'heartbeat-test-stale',
            'task_name' => '陈旧运行测试',
            'source' => 'heartbeat',
            'status' => ScheduleTaskRun::STATUS_RUNNING,
            'created_at' => $createdAt,
            'updated_at' => null,
            'queued_at' => null,
            'started_at' => null,
            'finished_at' => null,
        ]);

        $reclaimed = app(ScheduleTaskRunRepository::class)
            ->reclaimStaleRunsForTask('heartbeat-test-stale', 60);

        $this->assertSame(1, $reclaimed);
        $this->assertDatabaseHas('schedule_task_runs', [
            'task_key' => 'heartbeat-test-stale',
            'status' => ScheduleTaskRun::STATUS_FAILED,
        ]);
    }

    public function test_late_job_rejection_is_recorded_in_run_summary(): void
    {
        $run = ScheduleTaskRun::query()->create([
            'task_key' => 'heartbeat-test-due',
            'task_name' => 'Heartbeat Test Due',
            'rule_description' => '每次心跳',
            'source' => 'heartbeat',
            'queue' => 'automation',
            'status' => ScheduleTaskRun::STATUS_QUEUED,
            'queued_at' => now(),
        ]);
        $repository = app(ScheduleTaskRunRepository::class);

        $this->assertTrue($repository->markRunning((int) $run->id));
        $this->assertTrue($repository->markSucceeded((int) $run->id, ['ok' => true], 12));

        $result = app(HeartbeatTaskRunner::class)->run(
            new CallbackScheduledTask(
                key: 'heartbeat-test-due',
                title: 'Heartbeat Test Due',
                description: 'Test due task',
                category: 'Test',
                triggers: [ScheduleRule::everyTicks(1)],
                handler: fn (): array => ['ok' => true],
            ),
            new TaskContext(
                taskKey: 'heartbeat-test-due',
                source: 'heartbeat',
                taskRunId: (int) $run->id,
                attempt: 2,
            ),
        );

        $this->assertSame('skipped', $result['status']);
        $this->assertSame('schedule_run_not_active', $result['reason']);
        $fresh = $run->fresh();
        $this->assertSame(ScheduleTaskRun::STATUS_SUCCESS, $fresh->status);
        $rejections = $fresh->summary['late_job_rejections'] ?? [];
        $this->assertCount(1, $rejections);
        $this->assertSame(2, $rejections[0]['attempt']);
        $this->assertSame('schedule_run_not_active', $rejections[0]['reason']);
    }

    public function test_late_job_rejection_history_is_limited_to_five(): void
    {
        $run = ScheduleTaskRun::query()->create([
            'task_key' => 'heartbeat-test-due',
            'task_name' => 'Heartbeat Test Due',
            'rule_description' => '每次心跳',
            'source' => 'heartbeat',
            'queue' => 'automation',
            'status' => ScheduleTaskRun::STATUS_QUEUED,
            'queued_at' => now(),
        ]);
        $repository = app(ScheduleTaskRunRepository::class);

        for ($attempt = 1; $attempt <= 7; $attempt++) {
            $this->assertTrue($repository->recordLateJobRejection((int) $run->id, $attempt, 'schedule_run_not_active'));
        }

        $rejections = $run->fresh()->summary['late_job_rejections'];
        $this->assertCount(5, $rejections);
        $this->assertSame(3, $rejections[0]['attempt']);
        $this->assertSame(7, $rejections[4]['attempt']);
    }

    public function test_heartbeat_reports_health_warning_when_stale_runs_are_reclaimed(): void
    {
        Queue::fake();

        Log::shouldReceive('warning')
            ->once()
            ->with('[调度] 心跳槽位健康告警', Mockery::on(
                fn (array $context): bool => ($context['reclaimed'] ?? 0) === 1
                    && ($context['dispatch_failed'] ?? 0) === 0
                    && ($context['queued'] ?? 0) === 1
            ));

        DB::table('schedule_task_runs')->insert([
            'task_key' => 'heartbeat-test-stale',
            'task_name' => '陈旧运行测试',
            'source' => 'heartbeat',
            'status' => ScheduleTaskRun::STATUS_RUNNING,
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHours(2),
            'queued_at' => now()->subHours(2),
        ]);

        $registry = new class(app()) extends HeartbeatTaskRegistry
        {
            /** @var list<ScheduledTask> */
            public array $tasks = [];

            public function enabledTasks(): array
            {
                return $this->tasks;
            }
        };
        $registry->tasks = [
            new CallbackScheduledTask(
                key: 'heartbeat-test-stale',
                title: '陈旧运行测试',
                description: 'Test stale task',
                category: 'Test',
                triggers: [ScheduleRule::everyTicks(1)],
                handler: fn (): array => ['ok' => true],
            ),
        ];

        $scheduler = new HeartbeatScheduler(
            app(ScheduleTickRepository::class),
            app(ScheduleTaskRunRepository::class),
            $registry,
            app(TriggerRuleMatcher::class),
        );

        $summary = $scheduler->tick(CarbonImmutable::parse('2026-07-05 13:00:00', 'Asia/Shanghai'));

        $this->assertSame(['heartbeat-test-stale'], $summary->queuedTasks);
        $this->assertDatabaseHas('schedule_task_runs', [
            'task_key' => 'heartbeat-test-stale',
            'status' => ScheduleTaskRun::STATUS_FAILED,
        ]);
    }

    public function test_heartbeat_logs_warning_when_slot_lock_is_unavailable(): void
    {
        Queue::fake();
        config(['queue.default' => 'sync']);

        Log::shouldReceive('warning')
            ->once()
            ->with('[调度] 心跳槽位锁被占用，本槽位跳过派发', Mockery::on(
                fn (array $context): bool => ($context['slot'] ?? '') === '202607051300'
                    && ($context['lock'] ?? '') === 'scheduler:heartbeat:202607051300'
            ));

        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('get')->once()->andReturn(false);
        Cache::shouldReceive('lock')
            ->once()
            ->with('scheduler:heartbeat:202607051300', 900)
            ->andReturn($lock);

        $registry = new class(app()) extends HeartbeatTaskRegistry
        {
            public function enabledTasks(): array
            {
                return [];
            }
        };

        $scheduler = new HeartbeatScheduler(
            app(ScheduleTickRepository::class),
            app(ScheduleTaskRunRepository::class),
            $registry,
            app(TriggerRuleMatcher::class),
        );

        $summary = $scheduler->tick(CarbonImmutable::parse('2026-07-05 13:00:00', 'Asia/Shanghai'));

        $this->assertSame([], $summary->queuedTasks);
        $this->assertSame([], $summary->duplicateTasks);
    }

    public function test_liveness_command_fails_when_heartbeat_is_stale(): void
    {
        Log::shouldReceive('error')
            ->once()
            ->with('[调度] 心跳停滞告警', Mockery::on(
                fn (array $context): bool => ($context['latest_heartbeat'] ?? '') === '2026-07-05 12:20:00'
                    && ($context['max_age_seconds'] ?? 0) === 60
            ));

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-05 12:30:00', 'Asia/Shanghai'));

        try {
            ScheduleTick::query()->delete();
            ScheduleTick::query()->create([
                'slot_started_at' => '2026-07-05 12:15:00',
                'global_number' => 1000,
                'daily_index' => 50,
                'triggered_at' => '2026-07-05 12:20:00',
            ]);

            $exit = Artisan::call('scheduler:liveness', ['--max-age' => '60']);
            $this->assertSame(1, $exit);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_liveness_command_passes_when_heartbeat_is_fresh(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-05 12:30:00', 'Asia/Shanghai'));

        try {
            ScheduleTick::query()->delete();
            ScheduleTick::query()->create([
                'slot_started_at' => '2026-07-05 12:15:00',
                'global_number' => 1000,
                'daily_index' => 50,
                'triggered_at' => '2026-07-05 12:29:00',
            ]);

            $exit = Artisan::call('scheduler:liveness', ['--max-age' => '60']);
            $this->assertSame(0, $exit);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_unregistered_task_job_converges_to_terminal_failed_without_retry(): void
    {
        $run = ScheduleTaskRun::query()->create([
            'task_key' => 'legacy-unknown-plugin-task',
            'task_name' => '旧插件任务',
            'rule_description' => '每次心跳',
            'source' => 'heartbeat',
            'queue' => 'automation',
            'status' => ScheduleTaskRun::STATUS_QUEUED,
            'queued_at' => now(),
        ]);

        $job = new RunHeartbeatTaskJob('legacy-unknown-plugin-task', null, (int) $run->id, null, 'heartbeat', 900);

        $this->assertSame([], $job->middleware());
        $job->handle(
            app(HeartbeatTaskRegistry::class),
            app(HeartbeatTaskRunner::class),
            app(ScheduleTickRepository::class),
        );

        $this->assertDatabaseHas('schedule_task_runs', [
            'id' => $run->id,
            'status' => ScheduleTaskRun::STATUS_FAILED,
        ]);
        $this->assertStringContainsString('任务未注册', (string) $run->fresh()->error_msg);
    }

    public function test_queue_visibility_timeout_covers_the_longest_registered_task(): void
    {
        $longestTaskTimeout = collect(app(HeartbeatTaskRegistry::class)->enabledTasks())
            ->map(fn (ScheduledTask $task): int => $task->timeout())
            ->max();

        $this->assertGreaterThan((int) $longestTaskTimeout, (int) config('queue.connections.database.retry_after'));
        $this->assertGreaterThanOrEqual(
            (int) config('queue.connections.database.retry_after') + 60,
            (int) config('queue.turaidc_worker_drain_lock_ttl'),
        );
    }

    public function test_app_serve_with_schedule_relies_on_queue_drain_instead_of_a_second_worker(): void
    {
        $source = file_get_contents(app_path('Console/Commands/ServeBackendStackCommand.php'));

        $this->assertIsString($source);
        $this->assertStringContainsString("&& ! (bool) \$this->option('with-schedule')", $source);
        $this->assertStringContainsString('{--without-vnc', $source);
        $this->assertStringContainsString('队列由 queue:drain 后台消费', $source);
    }

    private function ensureHeartbeatTables(): void
    {
        if (! Schema::hasTable('schedule_ticks')) {
            Schema::create('schedule_ticks', function (Blueprint $table) {
                $table->id();
                $table->timestamp('slot_started_at')->unique();
                $table->unsignedBigInteger('global_number')->unique();
                $table->unsignedTinyInteger('daily_index')->index();
                $table->timestamp('triggered_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('schedule_task_runs')) {
            Schema::create('schedule_task_runs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('schedule_tick_id')->nullable()->index();
                $table->string('task_key', 120);
                $table->string('task_name', 160);
                $table->string('rule_description', 160)->nullable();
                $table->string('source', 40)->default('heartbeat');
                $table->string('queue', 80)->nullable();
                $table->string('status', 30)->default('queued');
                $table->unsignedInteger('duration_ms')->nullable();
                $table->json('summary')->nullable();
                $table->text('error_msg')->nullable();
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->unique(['schedule_tick_id', 'task_key', 'source'], 'schedule_task_runs_tick_task_source_unique');
                $table->index(['task_key', 'created_at']);
                $table->index(['status', 'created_at']);
                $table->index(['source', 'created_at']);
            });
        }
    }

    private function ensureJobsTable(): void
    {
        if (Schema::hasTable('jobs')) {
            return;
        }

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }
}
