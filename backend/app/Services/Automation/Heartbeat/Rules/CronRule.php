<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat\Rules;

use App\Services\Automation\Heartbeat\Contracts\TriggerRule;
use App\Services\Automation\Heartbeat\Data\TickContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Cron\CronExpression;

final readonly class CronRule implements TriggerRule
{
    public function __construct(
        private string $expression,
    ) {}

    public function isDue(TickContext $tick): bool
    {
        return CronExpression::factory($this->expression)
            ->isDue($tick->slotStartedAt, (string) config('app.timezone', date_default_timezone_get()));
    }

    public function describe(): string
    {
        return $this->expression;
    }

    public function nextDueAfter(CarbonInterface $time): ?CarbonImmutable
    {
        $next = CronExpression::factory($this->expression)
            ->getNextRunDate($time, 0, false, (string) config('app.timezone', date_default_timezone_get()));

        return CarbonImmutable::instance($next);
    }
}
