<?php

return [
    'scheduler_max_age_seconds' => (int) env('HEALTH_SCHEDULER_MAX_AGE_SECONDS', 180),
    'scheduler_task_failure_window_seconds' => (int) env('HEALTH_SCHEDULER_TASK_FAILURE_WINDOW_SECONDS', 900),
    'queue_max_pending_jobs' => (int) env('HEALTH_QUEUE_MAX_PENDING_JOBS', 10000),
];
