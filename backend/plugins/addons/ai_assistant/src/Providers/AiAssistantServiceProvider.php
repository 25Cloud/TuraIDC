<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use TuraIDC\Plugins\Addons\AiAssistant\Console\AiDoctorCommand;
use TuraIDC\Plugins\Addons\AiAssistant\Console\AiPruneConversationsCommand;

/**
 * AI 助手插件的唯一注册入口。
 *
 * 只做三件事，全部落在插件自己的边界内：
 * 1. 把 config/ai.php 合并进 `ai_gateway` 配置键（沿用拆分前的键名，站长 .env 里的
 *    AI_GATEWAY_* 与既有文档继续有效）；
 * 2. 注册 AI 自己的两个运维命令；
 * 3. 注册 /api/v2/{client,admin}/ai/* 路由，中间件与拆分前逐条一致。
 *
 * 不注册全局中间件、不注册调度、不改核心路由文件。
 */
class AiAssistantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2) . '/config/ai.php', 'ai_gateway');
    }

    public function boot(): void
    {
        // 只在控制台注册命令：Artisan::starting() 会解析 console kernel，
        // 在 HTTP 请求里没必要为两个运维命令多拉一次内核。
        if ($this->app->runningInConsole()) {
            $this->commands([
                AiDoctorCommand::class,
                AiPruneConversationsCommand::class,
            ]);
        }

        if ($this->app->routesAreCached()) {
            return;
        }

        // 客户端：登录用户 + 用户态校验，与拆分前 v2-client.php 里的组完全一致
        Route::middleware(['api', 'auth:sanctum', 'ensure.client'])
            ->prefix('api/v2/client')
            ->group(dirname(__DIR__, 2) . '/routes/client.php');

        // 管理端：登录管理员 + 管理端校验，权限分组写在 routes/admin.php 内
        Route::middleware(['api', 'auth:sanctum', 'ensure.admin'])
            ->prefix('api/v2/admin')
            ->group(dirname(__DIR__, 2) . '/routes/admin.php');
    }
}
