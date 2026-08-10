<?php

$normalizePublicUrl = static function (?string $url): string {
    $normalized = trim((string) $url);
    if ($normalized === '') {
        return '';
    }

    return rtrim($normalized, '/');
};

return [
    'name' => env('APP_NAME', '二五云'),
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
];
