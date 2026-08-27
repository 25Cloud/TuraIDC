<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat;

use App\Models\ScheduleTaskRun;

/**
 * 本槽位登记运行记录的结果。
 *
 * 拆成两个字段是因为调用方要区分三种情况，而单个 ?ScheduleTaskRun 表达不了：
 * - 需要派发（新建，或派发失败后重派）：$dispatchable 非 null；
 * - 本槽已终态处理（success/failed）：不派发，也不算重复投递；
 * - 本槽仍活跃或状态未知：算重复投递。
 */
final readonly class QueuedRunOutcome
{
    public function __construct(
        public ?ScheduleTaskRun $dispatchable,
        public ?string $existingStatus,
    ) {}

    /**
     * 同槽已有记录是否已进入终态（成功或失败）。
     *
     * 终态不算「重复投递」：任务本槽已经跑过了，剩余心跳只是路过，
     * 刷 duplicates 告警会把真正的重复投递淹掉。
     */
    public function settledInThisSlot(): bool
    {
        return in_array(
            $this->existingStatus,
            [ScheduleTaskRun::STATUS_SUCCESS, ScheduleTaskRun::STATUS_FAILED],
            true,
        );
    }
}
