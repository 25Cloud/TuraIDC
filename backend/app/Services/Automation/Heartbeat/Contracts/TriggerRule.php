<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat\Contracts;

use App\Services\Automation\Heartbeat\Data\TickContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

interface TriggerRule
{
    public function isDue(TickContext $tick): bool;

    public function describe(): string;

    public function nextDueAfter(CarbonInterface $time): ?CarbonImmutable;
}
