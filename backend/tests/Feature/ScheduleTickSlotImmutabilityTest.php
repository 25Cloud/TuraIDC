<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\ScheduleTick;
use App\Services\Automation\Heartbeat\ScheduleTickRepository;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * 槽起点在同槽重复心跳下必须保持不变。
 *
 * 背景：schedule_ticks.slot_started_at 是表内第一顺位、无显式 DEFAULT 的
 * timestamp NOT NULL 列。MySQL 5.7 默认 explicit_defaults_for_timestamp=OFF 时，
 * 该列会被隐式附加 ON UPDATE CURRENT_TIMESTAMP——只更新 triggered_at 的 UPDATE 会把
 * 槽起点悄悄改写成当前时间，槽唯一键语义随之崩坏，下一次 tick 撞 global_number 唯一键
 * （5.7.44 实测复现；8.0 默认 ON 无此行为）。仓库层改为显式回写该列来中和。
 *
 * 本用例在 8.0 上同样有意义：它锁住"重复心跳不得改写槽标识"这条不变量，
 * 防止后续有人把显式赋值当冗余删掉。
 */
class ScheduleTickSlotImmutabilityTest extends TestCase
{
    private const SLOT = '2026-09-14 08:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        ScheduleTick::query()->where('slot_started_at', self::SLOT)->delete();
    }

    protected function tearDown(): void
    {
        ScheduleTick::query()->where('slot_started_at', self::SLOT)->delete();

        parent::tearDown();
    }

    public function test_repeated_slot_resolution_keeps_slot_started_at_untouched(): void
    {
        $repository = app(ScheduleTickRepository::class);

        $first = $repository->firstOrCreateSlot(
            CarbonImmutable::parse('2026-09-14 08:00:00', config('app.timezone'))
        );
        $originalSlot = $first->slot_started_at?->format('Y-m-d H:i:s');

        $second = $repository->firstOrCreateSlot(
            CarbonImmutable::parse('2026-09-14 08:09:00', config('app.timezone'))
        );

        $this->assertSame($first->id, $second->id, '同一 15 分钟槽内应复用同一条记录');
        $this->assertSame(self::SLOT, $originalSlot);

        // 关键断言：走数据库读回，确认 UPDATE 没有改写槽起点（5.7 隐式 ON UPDATE 会在此暴露）。
        $persisted = ScheduleTick::query()->findOrFail($first->id);
        $this->assertSame(
            self::SLOT,
            $persisted->slot_started_at?->format('Y-m-d H:i:s'),
            'slot_started_at 在重复心跳后被改写——5.7 隐式 ON UPDATE CURRENT_TIMESTAMP 未被中和'
        );
        $this->assertSame(
            '2026-09-14 08:09:00',
            $persisted->triggered_at?->format('Y-m-d H:i:s'),
            'triggered_at 应刷新为最后一次心跳时间'
        );

        // 槽唯一键仍成立：不会因槽起点漂移而生出第二条记录。
        $this->assertSame(1, ScheduleTick::query()->where('slot_started_at', self::SLOT)->count());
    }

    public function test_returned_model_carries_the_persisted_updated_at(): void
    {
        $repository = app(ScheduleTickRepository::class);

        try {
            // 两次调用各自钉死"当前时间"：只断言库内与内存一致是不够的——若实现把旧值
            // 同时写回两处，那种断言照样通过。必须同时验证 updated_at 确实被刷新了。
            CarbonImmutable::setTestNow(
                CarbonImmutable::parse('2026-09-14 08:00:05', config('app.timezone'))
            );
            $created = $repository->firstOrCreateSlot(
                CarbonImmutable::parse('2026-09-14 08:00:00', config('app.timezone'))
            );
            $createdUpdatedAt = $created->updated_at?->format('Y-m-d H:i:s');
            $this->assertSame('2026-09-14 08:00:05', $createdUpdatedAt);

            CarbonImmutable::setTestNow(
                CarbonImmutable::parse('2026-09-14 08:09:05', config('app.timezone'))
            );
            $tick = $repository->firstOrCreateSlot(
                CarbonImmutable::parse('2026-09-14 08:09:00', config('app.timezone'))
            );
        } finally {
            CarbonImmutable::setTestNow();
        }

        $persisted = ScheduleTick::query()->findOrFail($tick->id);

        $this->assertNotSame(
            $createdUpdatedAt,
            $tick->updated_at?->format('Y-m-d H:i:s'),
            'updated_at 应随本次更新刷新，而不是停留在创建时的值'
        );
        $this->assertSame(
            '2026-09-14 08:09:05',
            $tick->updated_at?->format('Y-m-d H:i:s'),
            'updated_at 应取本次更新时刻'
        );
        $this->assertSame(
            $persisted->updated_at?->format('Y-m-d H:i:s'),
            $tick->updated_at?->format('Y-m-d H:i:s'),
            '返回实例的 updated_at 应与落库值一致，而不是更新前的旧值'
        );
        $this->assertFalse($tick->isDirty(), '返回实例不应残留未同步的脏属性');
    }
}
