<?php

namespace App\Jobs;

class RunScheduleTaskJob extends RunHeartbeatTaskJob
{
    public function __construct(
        string $taskKey,
        ?int $adminUserId = null,
        ?int $taskTimeout = null,
    ) {
        parent::__construct(
            taskKey: $taskKey,
            tickId: null,
            taskRunId: null,
            adminUserId: $adminUserId,
            source: 'manual_trigger',
            taskTimeout: $taskTimeout,
        );
    }
}
