<?php

declare(strict_types=1);

return [
    // 上游服务商 API（本系统被魔方财务作为上游对接）。
    'zjmf_upstream' => [
        'enabled' => (bool) env('ZJMF_UPSTREAM_ENABLED', true),
    ],
];
