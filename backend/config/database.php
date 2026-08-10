<?php

return [
    'default' => env('DB_CONNECTION', 'mysql'),
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'idc'),
            'username' => env('DB_USERNAME', 'idc'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'timezone' => env('DB_TIMEZONE', '+08:00'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                PDO::MYSQL_ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],
    ],
    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            // Redis 前缀用于队列和 Redis 直接操作，缓存使用 cache.php 的前缀
            'prefix' => env('REDIS_PREFIX', ''),
            'persistent' => (bool) env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'timeout' => (float) env('REDIS_TIMEOUT', 2),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 10),
        ],

        'cache' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'timeout' => (float) env('REDIS_TIMEOUT', 2),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 10),
        ],

        'volatile' => [
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_VOLATILE_DB', '2'),
            'timeout' => (float) env('REDIS_TIMEOUT', 1),
            'read_timeout' => (float) env('REDIS_READ_TIMEOUT', 5),
        ],
    ],
];
