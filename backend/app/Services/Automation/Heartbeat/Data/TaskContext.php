<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat\Data;

use Carbon\CarbonImmutable;

final readonly class TaskContext
{
    public function __construct(
        public string $taskKey,
        public string $source,
        public ?TickContext $tick = null,
        public ?int $taskRunId = null,
        public ?int $adminUserId = null,
        public int $attempt = 1,
        public ?CarbonImmutable $triggeredAt = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toLogContext(): array
    {
        return array_filter([
            'task_key' => $this->taskKey,
            'source' => $this->source,
            'tick_id' => $this->tick?->id,
            'tick_slot' => $this->tick?->slotStartedAt->toDateTimeString(),
            'tick_number' => $this->tick?->globalNumber,
            'daily_tick_index' => $this->tick?->dailyIndex,
            'task_run_id' => $this->taskRunId,
            'admin_user_id' => $this->adminUserId,
            'attempt' => $this->attempt,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
