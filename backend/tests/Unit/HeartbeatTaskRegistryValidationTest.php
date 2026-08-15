<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Automation\Heartbeat\CallbackScheduledTask;
use App\Services\Automation\Heartbeat\Contracts\ScheduledTask;
use App\Services\Automation\Heartbeat\Contracts\ScheduledTaskProvider;
use App\Services\Automation\Heartbeat\HeartbeatTaskRegistry;
use App\Services\Automation\Heartbeat\Providers\CoreScheduledTaskProvider;
use App\Services\Automation\Heartbeat\Providers\LegacyScheduleHookTaskProvider;
use App\Services\Automation\Heartbeat\Providers\PluginScheduledTaskProvider;
use App\Services\Automation\Heartbeat\ScheduleRule;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

final class HeartbeatTaskRegistryValidationTest extends TestCase
{
    public function test_registry_skips_and_logs_core_task_that_violates_the_runtime_contract(): void
    {
        $invalidTask = new CallbackScheduledTask(
            key: 'invalid-core-contract',
            title: '无效核心任务',
            description: '用于验证核心任务注册契约。',
            category: '测试',
            triggers: [ScheduleRule::everyTicks(1)],
            handler: static fn (): array => ['ok' => true],
            timeout: 120,
            lockTtlSeconds: 120,
        );

        $this->app->instance(CoreScheduledTaskProvider::class, new class($invalidTask) implements ScheduledTaskProvider
        {
            public function __construct(private ScheduledTask $task) {}

            public function tasks(): array
            {
                return [$this->task];
            }
        });

        $emptyProvider = new class implements ScheduledTaskProvider
        {
            public function tasks(): array
            {
                return [];
            }
        };
        $this->app->instance(LegacyScheduleHookTaskProvider::class, $emptyProvider);
        $this->app->instance(PluginScheduledTaskProvider::class, $emptyProvider);

        Log::shouldReceive('error')
            ->once()
            ->with('[定时任务] 单个任务注册失败，已跳过', \Mockery::on(
                fn (array $context): bool => ($context['provider'] ?? null) === CoreScheduledTaskProvider::class
                    && str_contains((string) ($context['message'] ?? ''), 'lock TTL'),
            ));

        $registry = new HeartbeatTaskRegistry($this->app);

        $this->assertFalse($registry->supports('invalid-core-contract'));
        $this->assertSame([], $registry->enabledTasks());
    }
}
