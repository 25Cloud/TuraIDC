<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat;

use App\Services\Automation\Heartbeat\Contracts\ScheduledTask;
use App\Services\Automation\Heartbeat\Contracts\ScheduledTaskCadence;
use App\Services\Automation\Heartbeat\Contracts\TriggerRule;
use App\Services\Automation\Heartbeat\Data\TaskContext;
use Closure;

final readonly class CallbackScheduledTask implements ScheduledTask, ScheduledTaskCadence
{
    /**
     * @param  list<TriggerRule>  $triggers
     * @param  Closure(TaskContext):mixed  $handler
     */
    public function __construct(
        private string $key,
        private string $title,
        private string $description,
        private string $category,
        private array $triggers,
        private Closure $handler,
        private ?string $queue = null,
        private int $timeout = 900,
        private int $lockTtlSeconds = 1200,
        private bool $manualTriggerable = true,
        private ?string $declaredCadence = null,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function category(): string
    {
        return $this->category;
    }

    public function triggers(): array
    {
        return $this->triggers;
    }

    public function handle(TaskContext $context): array
    {
        $result = ($this->handler)($context);

        if (is_array($result)) {
            return $result;
        }

        return ['result' => $result];
    }

    public function queue(): string
    {
        $queue = trim((string) $this->queue);

        return $queue !== '' ? $queue : (string) config('queue.turaidc_schedule_queue', 'automation');
    }

    public function timeout(): int
    {
        return $this->timeout;
    }

    public function lockTtlSeconds(): int
    {
        return $this->lockTtlSeconds;
    }

    public function manualTriggerable(): bool
    {
        return $this->manualTriggerable;
    }

    public function declaredCadence(): ?string
    {
        return $this->declaredCadence;
    }
}
