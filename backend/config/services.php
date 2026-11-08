<?php

declare(strict_types=1);

return [
    // 上游服务商 API（本系统被魔方财务作为上游对接）。
    'zjmf_upstream' => [
        'enabled' => (bool) env('ZJMF_UPSTREAM_ENABLED', true),
        // JWT 校验口径必须在签发与鉴权两端一致；issuer 为空时回退到 APP_URL。
        'jwt_issuer' => trim((string) env('ZJMF_UPSTREAM_JWT_ISSUER', '')),
        'jwt_audience' => trim((string) env('ZJMF_UPSTREAM_JWT_AUDIENCE', 'zjmf-upstream')),
        'jwt_ttl' => max(1, (int) env('ZJMF_UPSTREAM_JWT_TTL', 7200)),
        'jwt_clock_skew' => max(0, (int) env('ZJMF_UPSTREAM_JWT_CLOCK_SKEW', 60)),
    ],
];
