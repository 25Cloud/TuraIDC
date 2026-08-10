<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat\Rules;

use App\Services\Automation\Heartbeat\Contracts\TriggerRule;
use App\Services\Automation\Heartbeat\Data\TickContext;
use App\Services\Automation\Heartbeat\TickSlot;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class DailyTick implements TriggerRule
{
    public function __construct(
        private int $index,
    ) {
        if ($this->index < 1 || $this->index > 96) {
            throw new InvalidArgumentException('每日心跳序号必须在 1 到 96 之间');
        }
    }

    public function isDue(TickContext $tick): bool
    {
        return $tick->dailyIndex === $this->index;
    }

    public function describe(): string
    {
        return "每日第 {$this->index} 个心跳";
    }

    public function nextDueAfter(CarbonInterface $time): ?CarbonImmutable
    {
        $slot = TickSlot::floorToFifteenMinutes($time)->addMinutes(15);

        for ($i = 0; $i < 96 * 8; $i++) {
            $tick = TickSlot::context(null, $slot);
            if ($this->isDue($tick)) {
                return $slot;
            }

            $slot = $slot->addMinutes(15);
        }

        return null;
    }
}
