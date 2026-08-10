<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat\Data;

use Carbon\CarbonImmutable;

final readonly class TickContext
{
    public function __construct(
        public ?int $id,
        public CarbonImmutable $slotStartedAt,
        public int $globalNumber,
        public int $dailyIndex,
    ) {}
}
