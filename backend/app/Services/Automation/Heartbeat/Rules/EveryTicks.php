<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat\Rules;

use App\Services\Automation\Heartbeat\Contracts\TriggerRule;
use App\Services\Automation\Heartbeat\Data\TickContext;
use App\Services\Automation\Heartbeat\TickSlot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class EveryTicks implements TriggerRule
{
    public function __construct(
        private int $interval,
        private int $offset = 0,
    ) {
        if ($this->interval < 1) {
            throw new InvalidArgumentException('心跳间隔必须大于 0');
        }
    }

    public function isDue(TickContext $tick): bool
    {
        return (($tick->globalNumber - $this->offset) % $this->interval) === 0;
    }

    public function describe(): string
    {
        return $this->interval === 1
            ? '每次心跳'
            : "每 {$this->interval} 次心跳";
    }

    public function nextDueAfter(CarbonInterface $time): ?CarbonImmutable
    {
        $slot = TickSlot::floorToFifteenMinutes($time)->addMinutes(15);

        for ($i = 0; $i < 10000; $i++) {
            $tick = TickSlot::context(null, $slot);
            if ($this->isDue($tick)) {
                return $slot;
            }

            $slot = $slot->addMinutes(15);
        }

        return null;
    }
}
