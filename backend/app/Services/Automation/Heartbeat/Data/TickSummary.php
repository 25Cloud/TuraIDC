<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat\Data;

final readonly class TickSummary
{
    /**
     * @param  list<string>  $queuedTasks
     * @param  list<string>  $skippedTasks
     * @param  list<string>  $duplicateTasks
     * @param  array<string, mixed>  $queueDrain
     */
    public function __construct(
        public TickContext $tick,
        public array $queuedTasks,
        public array $skippedTasks,
        public array $duplicateTasks,
        public array $queueDrain,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'tick' => [
                'id' => $this->tick->id,
                'slot_started_at' => $this->tick->slotStartedAt->toDateTimeString(),
                'global_number' => $this->tick->globalNumber,
                'daily_index' => $this->tick->dailyIndex,
            ],
            'queued' => count($this->queuedTasks),
            'skipped' => count($this->skippedTasks),
            'duplicates' => count($this->duplicateTasks),
            'queued_tasks' => $this->queuedTasks,
            'duplicate_tasks' => $this->duplicateTasks,
            'queue_drain' => $this->queueDrain,
        ];
    }
}
