<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat\Contracts;

/**
 * 可选契约：任务声明自身名义频率（如 every_minute / hourly）。
 * 不实现该接口的任务按未声明处理，序列化时 declared_cadence 输出 null；
 * effective_cadence 由调度规则按 15 分钟槽位真实推断，不受声明影响。
 */
interface ScheduledTaskCadence
{
    public function declaredCadence(): ?string;
}
