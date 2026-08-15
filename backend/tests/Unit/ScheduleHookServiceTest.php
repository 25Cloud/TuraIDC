<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Automation\Contracts\ScheduleHook;
use App\Services\Automation\Heartbeat\Providers\LegacyScheduleHookTaskProvider;
use App\Services\Automation\ScheduleHookService;
use App\Services\System\ScheduleRunLogService;
use Illuminate\Support\Facades\Log;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ScheduleHookServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ScheduleHookServiceTestListener::$received = [];
        NamedDescriptorScheduleHookServiceTestListener::$received = [];
    }

    public function test_it_runs_configured_hook_listeners(): void
    {
        config()->set('schedule_hooks.listeners.'.ScheduleHookService::HOOK_TASK_BEFORE, [
            ScheduleHookServiceTestListener::class,
        ]);

        $results = app(ScheduleHookService::class)->run(ScheduleHookService::HOOK_TASK_BEFORE, [
            'task_key' => 'service-auto-renew',
        ]);

        $this->assertTrue(app(ScheduleHookService::class)->hasListeners(ScheduleHookService::HOOK_TASK_BEFORE));
        $this->assertSame('success', $results[0]['status']);
        $this->assertSame('service-auto-renew', ScheduleHookServiceTestListener::$received[0]['context']['task_key']);
    }

    public function test_it_reads_literal_config_keys_for_hooks_with_dots(): void
    {
        config()->set('schedule_hooks.listeners', [
            ScheduleHookService::HOOK_TASK_BEFORE => [
                ScheduleHookServiceTestListener::class,
            ],
        ]);

        $results = app(ScheduleHookService::class)->run(ScheduleHookService::HOOK_TASK_BEFORE, [
            'task_key' => 'literal-config-hook',
        ]);

        $this->assertSame('success', $results[0]['status']);
        $this->assertSame('literal-config-hook', ScheduleHookServiceTestListener::$received[0]['context']['task_key']);
    }

    public function test_it_runs_a_direct_callable_descriptor_from_system_config(): void
    {
        config()->set('schedule_hooks.listeners', [
            ScheduleHookService::HOOK_TASK_BEFORE => [
                ScheduleHookServiceTestListener::class,
                'handle',
            ],
        ]);

        $results = app(ScheduleHookService::class)->run(ScheduleHookService::HOOK_TASK_BEFORE, [
            'task_key' => 'callable-descriptor-hook',
        ]);

        $this->assertCount(1, $results);
        $this->assertSame('success', $results[0]['status']);
        $this->assertSame('callable-descriptor-hook', ScheduleHookServiceTestListener::$received[0]['context']['task_key']);
    }

    public function test_it_preserves_named_callable_descriptors_from_system_config(): void
    {
        config()->set('schedule_hooks.listeners', [
            ScheduleHookService::HOOK_TASK_BEFORE => [
                'class' => NamedDescriptorScheduleHookServiceTestListener::class,
                'method' => 'Handle',
            ],
        ]);

        $results = app(ScheduleHookService::class)->run(ScheduleHookService::HOOK_TASK_BEFORE, [
            'task_key' => 'named-callable-descriptor-hook',
        ]);

        $this->assertCount(1, $results);
        $this->assertSame('success', $results[0]['status']);
        $this->assertSame('named-callable-descriptor-hook', NamedDescriptorScheduleHookServiceTestListener::$received[0]['context']['task_key']);
    }

    public function test_listener_failures_are_reported_without_interrupting_scheduler(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with('[调度Hook] 执行失败', Mockery::on(
                fn (array $context): bool => ($context['hook'] ?? null) === ScheduleHookService::HOOK_TASK_AFTER
                    && ($context['listener'] ?? null) === FailingScheduleHookServiceTestListener::class
                    && ($context['message'] ?? null) === 'hook failed for test'
                    && ($context['exception'] ?? null) === RuntimeException::class
            ));

        config()->set('schedule_hooks.listeners.'.ScheduleHookService::HOOK_TASK_AFTER, [
            FailingScheduleHookServiceTestListener::class,
            ScheduleHookServiceTestListener::class,
        ]);

        $results = app(ScheduleHookService::class)->run(ScheduleHookService::HOOK_TASK_AFTER, [
            'task_name' => '账单自动化维护',
        ]);

        $this->assertSame('failed', $results[0]['status']);
        $this->assertSame('success', $results[1]['status']);
        $this->assertSame('账单自动化维护', ScheduleHookServiceTestListener::$received[0]['context']['task_name']);
    }

    public function test_schedule_run_log_record_fires_before_and_after_hooks(): void
    {
        config()->set('schedule_hooks.listeners.'.ScheduleHookService::HOOK_TASK_BEFORE, [
            ScheduleHookServiceTestListener::class,
        ]);
        config()->set('schedule_hooks.listeners.'.ScheduleHookService::HOOK_TASK_AFTER, [
            ScheduleHookServiceTestListener::class,
        ]);

        $result = app(ScheduleRunLogService::class)->record('hook integration task', fn (): array => [
            'handled' => true,
        ], [
            'task_key' => 'hook-integration-task',
            'source' => 'unit-test',
        ]);

        $this->assertSame(['handled' => true], $result);
        $this->assertCount(2, ScheduleHookServiceTestListener::$received);
        $this->assertSame(ScheduleHookService::HOOK_TASK_BEFORE, ScheduleHookServiceTestListener::$received[0]['hook']);
        $this->assertSame(ScheduleHookService::HOOK_TASK_AFTER, ScheduleHookServiceTestListener::$received[1]['hook']);
        $this->assertSame('hook-integration-task', ScheduleHookServiceTestListener::$received[0]['context']['task_key']);
        $this->assertSame(['handled' => true], ScheduleHookServiceTestListener::$received[1]['context']['summary']);
    }

    public function test_hook_context_carries_tick_slot_and_task_run_id_from_runner_context(): void
    {
        config()->set('schedule_hooks.listeners.'.ScheduleHookService::HOOK_TASK_BEFORE, [
            ScheduleHookServiceTestListener::class,
        ]);

        app(ScheduleRunLogService::class)->record('hook tick context task', fn (): array => [
            'ok' => true,
        ], [
            'task_key' => 'hook-tick-context-task',
            'source' => 'heartbeat',
            'tick_id' => 42,
            'tick_slot' => '2026-07-05 12:00:00',
            'tick_number' => 100,
            'daily_tick_index' => 48,
            'task_run_id' => 77,
            'attempt' => 2,
        ]);

        $context = ScheduleHookServiceTestListener::$received[0]['context'];

        $this->assertSame('heartbeat', $context['source'] ?? null);
        $this->assertSame('2026-07-05 12:00:00', $context['tick_slot'] ?? null);
        $this->assertSame(100, $context['tick_number'] ?? null);
        $this->assertSame(48, $context['daily_tick_index'] ?? null);
        $this->assertSame(77, $context['task_run_id'] ?? null);
        $this->assertSame(2, $context['attempt'] ?? null);
    }

    public function test_schedule_run_log_record_fires_failed_hook_and_rethrows(): void
    {
        config()->set('schedule_hooks.listeners.'.ScheduleHookService::HOOK_TASK_FAILED, [
            ScheduleHookServiceTestListener::class,
        ]);

        try {
            app(ScheduleRunLogService::class)->record('hook failure task', function (): void {
                throw new RuntimeException('schedule task failed');
            }, [
                'task_key' => 'hook-failure-task',
                'source' => 'unit-test',
            ]);

            $this->fail('The schedule task exception was not rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('schedule task failed', $exception->getMessage());
        }

        $this->assertCount(1, ScheduleHookServiceTestListener::$received);
        $this->assertSame(ScheduleHookService::HOOK_TASK_FAILED, ScheduleHookServiceTestListener::$received[0]['hook']);
        $this->assertSame('hook-failure-task', ScheduleHookServiceTestListener::$received[0]['context']['task_key']);
        $this->assertSame(RuntimeException::class, ScheduleHookServiceTestListener::$received[0]['context']['exception_class']);
    }

    public function test_schedule_run_log_record_fires_legacy_cron_hooks_and_activity_log(): void
    {
        config()->set('schedule_hooks.listeners.'.ScheduleHookService::HOOK_BEFORE_CRON, [
            ScheduleHookServiceTestListener::class,
        ]);
        config()->set('schedule_hooks.listeners.'.ScheduleHookService::HOOK_AFTER_CRON, [
            ScheduleHookServiceTestListener::class,
        ]);

        $result = app(ScheduleRunLogService::class)->record('账单自动化维护', fn (): array => [
            'renew_orders_created' => 0,
        ], [
            'task_key' => 'billing-maintenance',
            'source' => 'unit-test',
        ]);

        $this->assertSame(['renew_orders_created' => 0], $result);
        $this->assertCount(2, ScheduleHookServiceTestListener::$received);
        $this->assertSame(ScheduleHookService::HOOK_BEFORE_CRON, ScheduleHookServiceTestListener::$received[0]['hook']);
        $this->assertSame(ScheduleHookService::HOOK_AFTER_CRON, ScheduleHookServiceTestListener::$received[1]['hook']);
        $this->assertSame('billing-maintenance', ScheduleHookServiceTestListener::$received[0]['context']['task_key']);
        $this->assertSame(['renew_orders_created' => 0], ScheduleHookServiceTestListener::$received[1]['context']['summary']);

        $this->assertDatabaseHas('activity_logs', [
            'actor_type' => 'system',
            'actor_name' => 'System',
            'module' => 'cron',
            'action' => 'success',
            'description' => 'Cron_账单自动化维护执行完成',
            'subject_type' => 'schedule_task',
        ]);
    }

    public function test_legacy_hook_tasks_declare_original_cadence_but_register_at_fifteen_minute_effective_cadence(): void
    {
        config()->set('schedule_hooks.listeners.'.ScheduleHookService::HOOK_EVERY_MINUTE, [
            ScheduleHookServiceTestListener::class,
        ]);
        config()->set('schedule_hooks.listeners.'.ScheduleHookService::HOOK_EVERY_FIVE_MINUTES, [
            ScheduleHookServiceTestListener::class,
        ]);

        $provider = app(LegacyScheduleHookTaskProvider::class);
        $tasks = collect($provider->tasks())->keyBy(fn ($task): string => $task->key());

        $everyMinute = $tasks->get('schedule-hook-every-minute');
        $everyFiveMinutes = $tasks->get('schedule-hook-every-five-minutes');

        $this->assertNotNull($everyMinute);
        $this->assertSame('every_minute', $everyMinute->declaredCadence());
        $this->assertSame('every_five_minutes', $everyFiveMinutes->declaredCadence());

        // 兼容名称不能代表真实 1/5 分钟执行：有效频率仍由 15 分钟槽位决定。
        $this->assertStringContainsString('15 分钟心跳触发', $everyMinute->description());
        $this->assertStringContainsString('15 分钟心跳触发', $everyFiveMinutes->description());
        $this->assertFalse($everyMinute->manualTriggerable());
    }

    public function test_legacy_hook_tasks_are_not_registered_without_listeners(): void
    {
        $provider = app(LegacyScheduleHookTaskProvider::class);

        $this->assertSame([], $provider->tasks());
    }
}

final class ScheduleHookServiceTestListener implements ScheduleHook
{
    public static array $received = [];

    public function handle(string $hook, array $context = []): array
    {
        self::$received[] = [
            'hook' => $hook,
            'context' => $context,
        ];

        return ['handled' => true];
    }
}

final class FailingScheduleHookServiceTestListener implements ScheduleHook
{
    public function handle(string $hook, array $context = []): mixed
    {
        throw new RuntimeException('hook failed for test');
    }
}

final class NamedDescriptorScheduleHookServiceTestListener
{
    /** @var list<array{hook:string, context:array<string, mixed>}> */
    public static array $received = [];

    /** @param array<string, mixed> $context */
    public function Handle(string $hook, array $context = []): array
    {
        self::$received[] = [
            'hook' => $hook,
            'context' => $context,
        ];

        return ['handled' => true];
    }
}
