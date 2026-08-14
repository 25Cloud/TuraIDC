<?php

return [
    'default' => env('QUEUE_CONNECTION', 'database'),

    'TuraIDC_worker_queues' => 'provision,referral,notification,coupon,default',
    'TuraIDC_worker_timeout' => 1200,
    'TuraIDC_worker_max_timeout' => 3600,
    'TuraIDC_worker_tries' => 3,
    'TuraIDC_worker_drain_lock_ttl' => 3960,

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
