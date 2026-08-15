<?php

return [
    'default' => env('QUEUE_CONNECTION', 'database'),

    'turaidc_business_queues' => env('TURAIDC_BUSINESS_QUEUES', 'provision,referral,notification,coupon,default'),
    'turaidc_schedule_queue' => env('TURAIDC_SCHEDULE_QUEUE', 'automation'),
    'turaidc_worker_timeout' => 1200,
    'turaidc_worker_max_timeout' => 3600,
    'turaidc_worker_tries' => 3,
    'turaidc_worker_drain_lock_ttl' => 3960,

    'connections' => [
        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => 3900,
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => env('REDIS_QUEUE_BLOCK_FOR'),
            'after_commit' => false,
        ],
    ],

    'batching' => [
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => env('DB_QUEUE_BATCHES_TABLE', 'job_batches'),
    ],

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'mysql'),
        'table' => env('DB_QUEUE_FAILED_TABLE', 'failed_jobs'),
    ],
];
