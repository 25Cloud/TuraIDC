<?php

declare(strict_types=1);

$backendDir = dirname(__DIR__);
$rootDir = dirname($backendDir);

chdir($backendDir);

$routeJson = shell_exec('php artisan route:list --json');
$routes = json_decode($routeJson ?: '[]', true);

if (! is_array($routes)) {
    fwrite(STDERR, "Failed to read Laravel route list.\n");
    exit(1);
}

$scopeLabels = [
    'admin' => '管理端',
    'client' => '客户端',
    'site' => '站点',
    'system' => '系统',
];

$sourceAppLabels = [
    'frontend-admin-v3' => '管理端前端',
    'frontend-user-v3-www' => '官网/用户入口',
    'frontend-user-v4-console' => '用户控制台',
];

$accessLabels = [
    'public' => '公开',
    'auth' => '仅登录',
    'permission' => '登录 + 权限',
];

$items = [];

foreach ($routes as $route) {
    $uri = '/'.ltrim((string) ($route['uri'] ?? ''), '/');
    if (preg_match('#^/api/(v2/(admin|client|site)|health)(/|$)#', $uri) !== 1) {
        continue;
    }

    $methods = array_values(array_filter(
        explode('|', str_replace('HEAD', '', (string) ($route['method'] ?? ''))),
        static fn (string $method): bool => $method !== ''
    ));
    if ($methods === []) {
        $methods = [(string) ($route['method'] ?? '')];
    }

    $scope = resolveScope($uri);
    $module = resolveModule($uri, $scope);
    $middleware = (array) ($route['middleware'] ?? []);
    $permission = resolvePermission($middleware);
    $access = $permission !== '' ? 'permission' : (isAuthenticatedRoute($middleware) ? 'auth' : 'public');

    $items[] = [
        'id' => implode('|', $methods).':'.$uri,
        'scope' => $scope,
        'scopeLabel' => $scopeLabels[$scope] ?? $scope,
        'module' => $module,
        'moduleLabel' => moduleLabel($module),
        'method' => implode(' / ', $methods),
        'methods' => $methods,
        'callPath' => preg_replace('#^/api/v2#', '', $uri),
        'backendPath' => $uri,
        'normalizedPath' => preg_replace(
            '#\{[^}/]+\}#',
            '{param}',
            (string) preg_replace('#^/api/v2/(admin|client|site)#', '/$1', $uri)
        ),
        'access' => $access,
        'accessLabel' => $accessLabels[$access] ?? $access,
        'permission' => $permission,
        'throttle' => resolveThrottle($middleware),
        'guards' => resolveGuards($middleware),
        'handler' => (string) ($route['action'] ?? ''),
        'sourceApps' => [],
        'sourceAppLabels' => [],
        'sourceFiles' => [],
    ];
}

usort(
    $items,
    static fn (array $left, array $right): int => [
        $left['scope'],
        $left['module'],
        $left['backendPath'],
        $left['method'],
    ] <=> [
        $right['scope'],
        $right['module'],
        $right['backendPath'],
        $right['method'],
    ]
);

$scopeCounts = [];
$accessCounts = [];
$moduleKeys = [];

foreach ($items as $item) {
    $scopeCounts[$item['scope']] = ($scopeCounts[$item['scope']] ?? 0) + 1;
    $accessCounts[$item['access']] = ($accessCounts[$item['access']] ?? 0) + 1;
    $moduleKeys[$item['scope'].':'.$item['module']] = true;
}

$payload = [
    'meta' => [
        'generatedAt' => date('Y-m-d H:i:s'),
        'total' => count($items),
        'baseURL' => '/api/v2',
        'dataSource' => 'php artisan route:list --json v2-only',
        'scopeLabels' => $scopeLabels,
        'sourceAppLabels' => $sourceAppLabels,
        'accessLabels' => $accessLabels,
        'scopeCounts' => $scopeCounts,
        'accessCounts' => $accessCounts,
        'moduleCount' => count($moduleKeys),
    ],
    'items' => $items,
];

$target = $rootDir.'/frontend-admin-v3/src/data/apiCatalog.generated.json';
file_put_contents(
    $target,
    json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL
);

echo 'Generated '.count($items).' API catalog entries at '.$target.PHP_EOL;

function resolveScope(string $uri): string
{
    if (str_starts_with($uri, '/api/v2/admin')) {
        return 'admin';
    }

    if (str_starts_with($uri, '/api/v2/client')) {
        return 'client';
    }

    if (str_starts_with($uri, '/api/v2/site')) {
        return 'site';
    }

    return 'system';
}

function resolveModule(string $uri, string $scope): string
{
    if ($scope === 'system') {
        return 'health';
    }

    $path = trim((string) preg_replace('#^/api/v2/(admin|client|site)/?#', '', $uri), '/');
    $module = explode('/', $path)[0] ?? 'root';

    return $module !== '' ? $module : 'root';
}

function moduleLabel(string $module): string
{
    $labels = [
        'auth' => '认证',
        'balance-logs' => '余额日志',
        'content' => '内容',
        'coupons' => '优惠券',
        'dashboard' => '仪表盘',
        'finance' => '财务',
        'health' => '健康检查',
        'invoices' => '账单',
        'logs' => '日志',
        'media-files' => '媒体文件',
        'notifications' => '通知',
        'orders' => '订单',
        'payments' => '支付',
        'product-groups' => '产品分组',
        'product-types' => '产品类型',
        'products' => '产品',
        'recharge' => '充值',
        'referral' => '分销',
        'services' => '服务',
        'settings' => '设置',
        'site' => '站点',
        'staff' => '员工',
        'suppliers' => '供应商',
        'tickets' => '工单',
        'users' => '用户',
        'verification' => '实名',
    ];

    return $labels[$module] ?? ucwords(str_replace(['-', '_'], ' ', $module));
}

/**
 * @param  list<string>  $middleware
 */
function resolvePermission(array $middleware): string
{
    foreach ($middleware as $item) {
        $middlewareName = (string) $item;
        if (str_starts_with($middlewareName, 'permission:')) {
            return substr($middlewareName, strlen('permission:'));
        }
    }

    return '';
}

/**
 * @param  list<string>  $middleware
 */
function isAuthenticatedRoute(array $middleware): bool
{
    foreach ($middleware as $item) {
        $middlewareName = (string) $item;
        if (
            $middlewareName === 'auth:sanctum'
            || $middlewareName === 'admin.auth'
            || $middlewareName === 'client.auth'
        ) {
            return true;
        }
    }

    return false;
}

/**
 * @param  list<string>  $middleware
 */
function resolveThrottle(array $middleware): string
{
    foreach ($middleware as $item) {
        $middlewareName = (string) $item;
        if (str_starts_with($middlewareName, 'throttle:')) {
            return substr($middlewareName, strlen('throttle:'));
        }
    }

    return '';
}

/**
 * @param  list<string>  $middleware
 * @return list<string>
 */
function resolveGuards(array $middleware): array
{
    $guards = [];
    foreach ($middleware as $item) {
        $middlewareName = (string) $item;
        if (str_starts_with($middlewareName, 'throttle:')) {
            $guards[] = '限流 '.substr($middlewareName, strlen('throttle:'));
        }
        if ($middlewareName === 'verify.callback') {
            $guards[] = '签名校验';
        }
    }

    return $guards;
}
