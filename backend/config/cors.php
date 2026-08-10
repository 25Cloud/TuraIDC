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
    'max_age' => 0,
    'supports_credentials' => true,
];
