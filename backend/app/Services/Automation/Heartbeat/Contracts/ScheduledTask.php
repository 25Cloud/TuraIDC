<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat\Contracts;

use App\Services\Automation\Heartbeat\Data\TaskContext;

interface ScheduledTask
{
    public function key(): string;

    public function title(): string;

    public function description(): string;

    public function category(): string;

    /**
     * @return list<TriggerRule>
     */
    public function triggers(): array;

    /**
     * @return array<string, mixed>
     */
    public function handle(TaskContext $context): array;

    public function queue(): string;

    public function timeout(): int;

    public function lockTtlSeconds(): int;

    public function manualTriggerable(): bool;
}
