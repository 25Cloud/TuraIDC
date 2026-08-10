<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat;

use App\Models\ScheduleTick;
use App\Services\Automation\Heartbeat\Data\TickContext;
use Carbon\CarbonImmutable;

class ScheduleTickRepository
{
    public function firstOrCreateSlot(CarbonImmutable $triggeredAt): ScheduleTick
    {
        $resolvedSlot = TickSlot::floorToFifteenMinutes($triggeredAt);

        $tick = ScheduleTick::query()->firstOrCreate(
            ['slot_started_at' => $resolvedSlot],
            [
                'global_number' => TickSlot::globalNumber($resolvedSlot),
                'daily_index' => TickSlot::dailyIndex($resolvedSlot),
                'triggered_at' => $triggeredAt,
            ],
        );

        if (! $tick->wasRecentlyCreated) {
            $tick->forceFill(['triggered_at' => $triggeredAt])->save();
        }

        return $tick;
    }

    public function toContext(ScheduleTick $tick): TickContext
    {
        return new TickContext(
            id: (int) $tick->id,
            slotStartedAt: CarbonImmutable::instance($tick->slot_started_at),
            globalNumber: (int) $tick->global_number,
            dailyIndex: (int) $tick->daily_index,
        );
    }

    public function findContext(?int $tickId): ?TickContext
    {
        if ($tickId === null || $tickId <= 0) {
            return null;
        }

        $tick = ScheduleTick::query()->find($tickId);

        return $tick instanceof ScheduleTick ? $this->toContext($tick) : null;
    }
}
