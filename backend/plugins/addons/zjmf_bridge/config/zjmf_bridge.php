<?php

declare(strict_types=1);

$allowedIps = array_values(array_filter(array_map(
    static fn (string $ip): string => trim($ip),
    explode(',', (string) env('ZJMF_BRIDGE_ALLOWED_IPS', ''))
)));

$systemScopes = array_values(array_filter(array_map(
    static fn (string $scope): string => trim($scope),
    explode(',', (string) env('ZJMF_BRIDGE_SYSTEM_SCOPES', 'system.health,system.reconcile,auth.login,product.read'))
)));

return [
    'enabled' => (bool) env('ZJMF_BRIDGE_ENABLED', false),
    'base_path' => trim((string) env('ZJMF_BRIDGE_BASE_PATH', '/zjmf/v1'), '/'),
    'catalog_currency' => trim((string) env('ZJMF_BRIDGE_CATALOG_CURRENCY', 'CNY')),
    'mode' => (string) env('ZJMF_BRIDGE_MODE', 'strict'),
    'app_id' => (string) env('ZJMF_BRIDGE_APP_ID', 'zjmf'),
    'secret' => (string) env('ZJMF_BRIDGE_SECRET', ''),
    'token_ttl' => (int) env('ZJMF_BRIDGE_TOKEN_TTL', 7200),
    'allowed_ips' => $allowedIps,
    'signature_tolerance' => (int) env('ZJMF_BRIDGE_SIGNATURE_TOLERANCE', 300),
    'log_channel' => (string) env('ZJMF_BRIDGE_LOG_CHANNEL', 'zjmf_bridge'),
    'write_enabled' => (bool) env('ZJMF_BRIDGE_WRITE_ENABLED', false),
    'admin_scope_enabled' => (bool) env('ZJMF_BRIDGE_ADMIN_SCOPE_ENABLED', false),
    'system_scopes' => $systemScopes,
];
