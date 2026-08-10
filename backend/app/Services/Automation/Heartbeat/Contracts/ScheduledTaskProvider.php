<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat\Contracts;

interface ScheduledTaskProvider
{
    /**
     * @return list<ScheduledTask>
     */
    public function tasks(): array;
}
