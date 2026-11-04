<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AutoRefund\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * 自助退款插件的唯一注册入口。
 *
 * 只做一件事：注册本插件自己前缀下的两组路由。
 * - 不注册全局中间件、不注册调度、不改核心路由文件、不建日志表；
 * - 鉴权完全复用核心中间件（`auth:sanctum` + `ensure.client` / `ensure.admin`
 *   + 管理端 `permission:*`），不新建鉴权入口。
 *
 * 路由只在插件「已启用」时注册（PluginProviderRegistry 在启用与框架 boot 阶段调用），
 * 所以插件停用后 `/api/v2/{client,admin}/auto-refund/*` 整体消失并返回 404，
 * 而不是留在那里报 500。
 */
final class AutoRefundServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        // 客户端：登录用户 + 用户态校验
        Route::middleware(['api', 'auth:sanctum', 'ensure.client'])
            ->prefix('api/v2/client')
            ->group(dirname(__DIR__, 2).'/routes/client.php');

        // 管理端：登录管理员 + 管理端校验，权限分组写在 routes/admin.php 内
        Route::middleware(['api', 'auth:sanctum', 'ensure.admin'])
            ->prefix('api/v2/admin')
            ->group(dirname(__DIR__, 2).'/routes/admin.php');
    }
}
