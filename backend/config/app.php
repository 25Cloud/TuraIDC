<?php

$normalizePublicUrl = static function (?string $url): string {
    $normalized = trim((string) $url);
    if ($normalized === '') {
        return '';
    }

    return rtrim($normalized, '/');
};

return [
    'name' => env('APP_NAME', '图拉云'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => $normalizePublicUrl(env('APP_URL', 'http://127.0.0.1:8000')),
    'frontend_url' => $normalizePublicUrl(env('FRONTEND_URL', '')),
    'client_console_url' => $normalizePublicUrl(env('CLIENT_CONSOLE_URL', '')),
    'admin_url' => $normalizePublicUrl(env('ADMIN_URL', '')),
    'timezone' => 'Asia/Shanghai',
    'locale' => env('APP_LOCALE', 'zh_CN'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'zh_CN'),
    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],
    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],
    // 单次请求在 PHP 侧耗时超过该值（毫秒）时记录一条 [慢请求] 日志，
    // 响应头 X-Response-Time-Ms 始终返回真实耗时。设为 0 关闭日志，只保留响应头。
    'slow_request_threshold_ms' => (int) env('SLOW_REQUEST_THRESHOLD_MS', 1000),
];
