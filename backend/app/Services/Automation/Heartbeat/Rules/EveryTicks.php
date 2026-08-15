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
        if ($this->offset < 0) {
            throw new InvalidArgumentException('心跳偏移不能为负');
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

    public function interval(): int
    {
        return $this->interval;
    }

    public function nextDueAfter(CarbonInterface $time): CarbonImmutable
    {
        $slot = TickSlot::floorToFifteenMinutes($time);
        $globalNumber = TickSlot::context(null, $slot)->globalNumber;

        // 下一个严格晚于当前槽、且满足 (gn - offset) % interval === 0 的命中槽序号。
        // interval >= 1 保证结果始终严格在未来，O(1) 数学计算，无需逐槽枚举。
        $remainder = ($globalNumber + 1 - $this->offset) % $this->interval;
        $steps = (($this->interval - $remainder) % $this->interval) + 1;

        return $slot->addMinutes(15 * $steps);
    }
}
