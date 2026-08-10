<?php

return [
    'default' => env('CACHE_STORE', 'redis'),

    'stores' => [
        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
        ],

        'database' => [
            'driver' => 'database',
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'connection' => env('DB_CACHE_CONNECTION'),
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],

        // 高频短 TTL 缓存：用于节流、临时 Token 等场景，使用 DB 2 隔离
        'redis_volatile' => [
            'driver' => 'redis',
            'connection' => 'volatile',
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],
    ],

    // 缓存键前缀，留空则不加前缀
    'prefix' => env('CACHE_PREFIX', ''),
];
