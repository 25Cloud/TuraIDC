<?php

return [
    'default' => env('QUEUE_CONNECTION', 'database'),

    // provision 单独成组：上游开通任务的 timeout 可达 1200s，而 queue:work 对逗号
    // 队列列表是严格优先级消费，把它和通知/优惠券/推荐奖励串在同一个 worker 里，
    // 一个卡在上游 API 的开通任务会让其余业务全部排在后面（队头阻塞）。
    'turaidc_provision_queues' => env('TURAIDC_PROVISION_QUEUES', 'provision'),
    'turaidc_business_queues' => env('TURAIDC_BUSINESS_QUEUES', 'referral,notification,coupon,default'),
    'turaidc_schedule_queue' => env('TURAIDC_SCHEDULE_QUEUE', 'automation'),
    'turaidc_worker_timeout' => 1200,
    'turaidc_worker_max_timeout' => 3600,
    'turaidc_worker_tries' => 3,
    'turaidc_worker_drain_lock_ttl' => 3960,
    // drain 锁的存活心跳刷新间隔与判定过期阈值（秒）。
    // 锁本身的 TTL 必须覆盖最长任务（见 drainLockTtl()），进程被 SIGKILL 时
    // 无法靠 TTL 及时释放；改由这个短周期心跳判定持有者是否已死。
    'turaidc_worker_drain_heartbeat_ttl' => 90,

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
            'retry_after' => max(240, (int) env('REDIS_QUEUE_RETRY_AFTER', 240)),
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
