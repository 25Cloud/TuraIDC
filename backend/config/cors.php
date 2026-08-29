<?php

$normalizeOrigin = static function (?string $url): ?string {
    $parts = parse_url(trim((string) $url));
    if (! is_array($parts)) {
        return null;
    }

    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
        return null;
    }

    $port = (int) ($parts['port'] ?? 0);
    $suffix = $port > 0 && ! (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443))
        ? ':'.$port
        : '';

    return $scheme.'://'.$host.$suffix;
};

$allowedOrigins = array_values(array_unique(array_filter([
    $normalizeOrigin(env('FRONTEND_URL')),
    $normalizeOrigin(env('CLIENT_CONSOLE_URL')),
    $normalizeOrigin(env('ADMIN_URL')),
])));

// 额外允许来源（逗号分隔，如官网备用域名）：CORS_ALLOWED_ORIGINS=https://idc.example.com
$extraOrigins = array_values(array_unique(array_filter(array_map(
    static fn (string $raw) => $normalizeOrigin($raw),
    array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', '')))
))));

$allowedOrigins = array_values(array_unique(array_filter(array_merge($allowedOrigins, $extraOrigins))));

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'X-Requested-With',
        'X-CSRF-TOKEN',
        'X-Request-Id',
        'X-Idempotency-Key',
    ],
    'exposed_headers' => ['Content-Disposition', 'Retry-After', 'X-Request-Id'],
    // 三端与 API 分域部署，浏览器对每个跨源 API 请求都会先发一次 OPTIONS 预检。
    // max_age=0 表示预检结果一次都不能复用：线上实测 851 条 API 请求里有 294 条（34.5%）
    // 是纯预检，且每个 OPTIONS 都要完整启动一次 Laravel（实测 48-67ms），跨国链路上还要多付一趟往返。
    // 7200 是 Chrome 对预检缓存的上限，填更大也会被截断。
    // 代价：改动上面的 allowed_methods / allowed_headers 后，浏览器可能最多沿用 2 小时的旧预检结果。
    'max_age' => 7200,
    'supports_credentials' => true,
];
