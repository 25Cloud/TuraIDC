<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Automation\Heartbeat\Contracts\ScheduledTask;
use App\Services\Automation\Heartbeat\Data\TaskContext;
use App\Services\Automation\Heartbeat\ScheduledTaskValidator;
use App\Services\Automation\Heartbeat\ScheduleRule;
use InvalidArgumentException;
use Tests\TestCase;

final class ScheduledTaskValidatorTest extends TestCase
{
    public function test_queue_name_must_match_the_value_used_for_dispatch(): void
    {
        $task = new ValidatorFixtureTask(queue: ' automation ');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('可消费队列');

        app(ScheduledTaskValidator::class)->validate($task);
    }

    public function test_task_key_cannot_contain_hidden_leading_or_trailing_whitespace(): void
    {
        $task = new ValidatorFixtureTask(key: ' task-key ');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('定时任务 key');

        app(ScheduledTaskValidator::class)->validate($task);
    }

    public function test_lock_ttl_cannot_be_unbounded(): void
    {
        $task = new ValidatorFixtureTask(lockTtlSeconds: 100000);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('lock TTL 超出允许上限');

        app(ScheduledTaskValidator::class)->validate($task);
    }
}

final class ValidatorFixtureTask implements ScheduledTask
{
    public function __construct(
        private readonly string $key = 'validator-fixture-task',
        private readonly string $queue = 'automation',
        private readonly int $lockTtlSeconds = 120,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function title(): string
    {
        return '校验测试任务';
    }

    public function description(): string
    {
        return '用于验证调度任务契约。';
    }

    public function category(): string
    {
        return '测试';
    }

    public function triggers(): array
    {
        return [ScheduleRule::everyTicks(1)];
    }

    public function handle(TaskContext $context): array
    {
        return [];
    }

    public function queue(): string
    {
        return $this->queue;
    }

    public function timeout(): int
    {
        return 60;
    }

    public function lockTtlSeconds(): int
    {
        return $this->lockTtlSeconds;
    }

    public function manualTriggerable(): bool
    {
        return false;
    }
}
