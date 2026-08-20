<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;

define('LARAVEL_START', microtime(true));

$basePath = dirname(__DIR__);

require $basePath.'/vendor/autoload.php';

$app = require $basePath.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$routes = collect(RouteFacade::getRoutes()->getRoutes())
    ->filter(fn (Route $route) => str_starts_with($route->uri(), 'api/'))
    ->map(function (Route $route): array {
        $methods = array_values(array_filter($route->methods(), fn (string $method) => $method !== 'HEAD'));
        $middleware = array_values(array_unique($route->middleware()));
        $uri = '/'.ltrim($route->uri(), '/');

        return [
            'group' => resolveGroup($uri),
            'methods' => implode('|', $methods),
            'path' => $uri,
            'action' => resolveAction($route),
            'auth' => resolveAuth($middleware),
            'middleware' => implode(', ', $middleware),
        ];
    })
    ->sortBy([
        ['group', 'asc'],
        ['path', 'asc'],
        ['methods', 'asc'],
    ])
    ->values();

$groupCounts = $routes
    ->countBy('group')
    ->map(fn (int $count, string $group): string => $group.'='.$count)
    ->implode(', ');

$lines = [
    '# 后端 API 清单',
    '',
    '- 生成时间: `'.date('Y-m-d H:i:s').'`',
    '- API 总数: `'.$routes->count().'`',
    '- 分组统计: `'.$groupCounts.'`',
    '',
    '> **自动生成**，由 `backend/scripts/export_api_inventory.php` 扫描 Laravel 路由表导出，**不要手工编辑**。',
    '>',
    '> 需要更新本文件时，直接在项目根目录执行：`php backend/scripts/export_api_inventory.php`。',
    '> 需要看业务分组、核心业务流程映射等人类可读导航，请查看 `docs/参考资料/接口/API清单导航.md`。',
    '',
    '| 分组 | 方法 | 路径 | 控制器动作 | 鉴权 | 中间件 |',
    '| --- | --- | --- | --- | --- | --- |',
];

foreach ($routes as $route) {
    $lines[] = sprintf(
        '| %s | `%s` | `%s` | `%s` | `%s` | `%s` |',
        escapeTableCell($route['group']),
        escapeTableCell($route['methods']),
        escapeTableCell($route['path']),
        escapeTableCell($route['action']),
        escapeTableCell($route['auth']),
        escapeTableCell($route['middleware'])
    );
}

$target = dirname($basePath).'/docs/自动生成/接口/后端API清单.md';
// Windows 下 PHP 文件系统 API 按系统 ANSI(GBK) 编码解析路径，
// UTF-8 中文路径需转 GBK 才能正确打开，否则报 "Failed to open stream: No such file or directory"。
$writeTarget = PHP_OS_FAMILY === 'Windows'
    ? mb_convert_encoding($target, 'GBK', 'UTF-8')
    : $target;
file_put_contents($writeTarget, implode("\n", $lines)."\n");

fwrite(STDOUT, sprintf(
    '已生成 API 清单: %s，接口数: %d%s',
    $target,
    $routes->count(),
    PHP_EOL
));

function resolveGroup(string $uri): string
{
    $adminGroups = [
        '/api/v2/admin/login' => '管理端 / 认证',
        '/api/v2/admin/auth' => '管理端 / 认证',
        '/api/v2/admin/dashboard' => '管理端 / 仪表盘',
        '/api/v2/admin/users' => '管理端 / 用户',
        '/api/v2/admin/invoices' => '管理端 / 账单',
        '/api/v2/admin/orders' => '管理端 / 订单',
        '/api/v2/admin/services' => '管理端 / 服务',
        '/api/v2/admin/os-options' => '管理端 / 服务',
        '/api/v2/admin/suppliers' => '管理端 / 供应商',
        '/api/v2/admin/products' => '管理端 / 产品',
        '/api/v2/admin/product-groups' => '管理端 / 产品分组',
        '/api/v2/admin/product-types' => '管理端 / 产品类型',
        '/api/v2/admin/coupons' => '管理端 / 优惠券',
        '/api/v2/admin/coupon-campaigns' => '管理端 / 优惠券',
        '/api/v2/admin/coupon-product-groups' => '管理端 / 优惠券',
        '/api/v2/admin/content' => '管理端 / 内容',
        '/api/v2/admin/media-files' => '管理端 / 媒体',
        '/api/v2/admin/media-file-reindexes' => '管理端 / 媒体',
        '/api/v2/admin/tickets' => '管理端 / 工单',
        '/api/v2/admin/ticket-delivery' => '管理端 / 工单',
        '/api/v2/admin/verifications' => '管理端 / 实名认证',
        '/api/v2/admin/integration-plugins' => '管理端 / Integration Plugins',
        '/api/v2/admin/integration-plugin-scans' => '管理端 / Integration Plugins',
        '/api/v2/admin/finance' => '管理端 / 财务',
        '/api/v2/admin/referral-withdrawals' => '管理端 / 分销',
        '/api/v2/admin/referral' => '管理端 / 分销',
        '/api/v2/admin/roles' => '管理端 / 角色权限',
        '/api/v2/admin/permissions' => '管理端 / 角色权限',
        '/api/v2/admin/staff' => '管理端 / 员工',
        '/api/v2/admin/settings' => '管理端 / 设置',
        '/api/v2/admin/notification-templates' => '管理端 / 设置',
        '/api/v2/admin/site' => '管理端 / 站点',
        '/api/v2/admin/log-cleanups' => '管理端 / 日志',
        '/api/v2/admin/log-summaries' => '管理端 / 日志',
        '/api/v2/admin/logs' => '管理端 / 日志',
        '/api/v2/admin/member-levels' => '管理端 / 会员等级',
        '/api/v2/admin/cpu-model-catalog' => '管理端 / 规格目录',
        '/api/v2/admin/instance-spec-catalog' => '管理端 / 规格目录',
        '/api/v2/admin/schedules' => '管理端 / 调度',
        '/api/v2/admin/schedule-triggers' => '管理端 / 调度',
    ];

    $clientGroups = [
        '/api/v2/client/auth' => '客户端 / 认证',
        '/api/v2/client/login' => '客户端 / 认证入口',
        '/api/v2/client/register' => '客户端 / 认证入口',
        '/api/v2/client/password' => '客户端 / 认证入口',
        '/api/v2/client/services' => '客户端 / 服务',
        '/api/v2/client/vnc-tokens' => '客户端 / VNC Token',
        '/api/v2/client/invoices' => '客户端 / 账单',
        '/api/v2/client/orders' => '客户端 / 订单',
        '/api/v2/client/tickets' => '客户端 / 工单',
        '/api/v2/client/verification' => '客户端 / 实名认证',
        '/api/v2/client/content' => '客户端 / 内容',
        '/api/v2/client/notices' => '客户端 / 内容',
        '/api/v2/client/help-articles' => '客户端 / 内容',
        '/api/v2/client/notifications' => '客户端 / 通知',
        '/api/v2/client/coupons' => '客户端 / 优惠券',
        '/api/v2/client/referral' => '客户端 / 分销',
        '/api/v2/client/finance' => '客户端 / 财务',
        '/api/v2/client/ledger' => '客户端 / 财务',
        '/api/v2/client/balance-logs' => '客户端 / 财务',
        '/api/v2/client/recharge' => '客户端 / 充值',
        '/api/v2/client/payments' => '客户端 / 支付记录',
        '/api/v2/client/payment' => '客户端 / 支付回调',
    ];

    $siteGroups = [
        '/api/v2/site/config' => '站点 / 首页',
        '/api/v2/site/home' => '站点 / 首页',
        '/api/v2/site/home-hero' => '站点 / 首页',
        '/api/v2/site/content' => '站点 / 内容',
        '/api/v2/site/notices' => '站点 / 内容',
        '/api/v2/site/help-articles' => '站点 / 内容',
        '/api/v2/site/products' => '站点 / 产品',
        '/api/v2/site/product-groups' => '站点 / 产品',
        '/api/v2/site/product-types' => '站点 / 产品',
        '/api/v2/site/product-purchase-context' => '站点 / 产品',
        '/api/health' => '公共 / 健康检查',
        '/api/secure-assets/view' => '公共 / 安全资源',
    ];

    return matchApiGroup($uri, $adminGroups)
        ?? matchApiGroup($uri, $clientGroups)
        ?? matchApiGroup($uri, $siteGroups)
        ?? match (true) {
            str_starts_with($uri, '/api/v2/admin/') => '管理端 / 其他',
            str_starts_with($uri, '/api/v2/client/') => '客户端 / 其他',
            str_starts_with($uri, '/api/v2/site/') => '站点 / 其他',
            default => '公共 / 其他',
        };
}

function matchApiGroup(string $uri, array $groups): ?string
{
    foreach ($groups as $prefix => $group) {
        if ($uri === $prefix || str_starts_with($uri, $prefix.'/')) {
            return $group;
        }
    }

    return null;
}

function resolveAction(Route $route): string
{
    $action = $route->getActionName();

    if ($action === 'Closure') {
        return 'Closure';
    }

    return $action;
}

/**
 * 中间件只从路由定义读取，不触发控制器实例化，避免导出文档时连接数据库。
 */
function resolveAuth(array $middleware): string
{
    $middlewareText = implode(',', $middleware);

    if (str_contains($middlewareText, 'ensure.admin')) {
        return 'admin';
    }

    if (str_contains($middlewareText, 'ensure.client')) {
        return 'client';
    }

    if (str_contains($middlewareText, 'auth:sanctum')) {
        return 'auth';
    }

    return 'public';
}

function escapeTableCell(string $value): string
{
    return str_replace('|', '&#124;', $value);
}
