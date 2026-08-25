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
            // 更新时显式带上 slot_started_at 原值：该列是表内第一顺位、无显式 DEFAULT 的
            // timestamp NOT NULL，MySQL 5.7 默认 explicit_defaults_for_timestamp=OFF 时会
            // 自动附加隐式 ON UPDATE CURRENT_TIMESTAMP——只更新 triggered_at 的 UPDATE 会把
            // slot_started_at 悄悄改成当前时间，槽唯一键语义崩坏，下一次 tick 撞
            // global_number 唯一键（5.7 全量套件实测复现；8.0 默认 ON 无此行为）。
            // 显式赋值可覆盖隐式 ON UPDATE，两版行为一致。
            $tick->newQuery()->whereKey($tick->id)->update([
                'triggered_at' => $triggeredAt,
                'slot_started_at' => $tick->slot_started_at,
            ]);
            $tick->forceFill(['triggered_at' => $triggeredAt])->syncOriginal();
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
