<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins;

use App\Exceptions\BusinessException;
use App\Models\IntegrationPlugin;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class PluginProviderRegistry
{
    /**
     * @var array<string, bool>
     */
    private array $bootedProviders = [];

    public function __construct(
        private readonly Container $container,
        private readonly PluginScanner $scanner,
        private readonly PluginFileLoader $fileLoader,
    ) {}

    public function bootEnabledProviders(): void
    {
        try {
            if (! Schema::hasTable('integration_plugins')) {
                return;
            }

            // 只有声明了 provider 的插件需要在框架 boot 阶段读清单并注册。
            // 不加这个过滤，每个请求都会为每个启用插件做一次 realpath + require config.php，
            // 而绝大多数插件根本没有 provider。
            $plugins = IntegrationPlugin::query()
                ->where('status', IntegrationPlugin::STATUS_ENABLED)
                ->whereNotNull('provider_class')
                ->where('provider_class', '<>', '')
                ->get(['domain', 'slug']);

            if ($plugins->isEmpty()) {
                return;
            }
        } catch (\Throwable $exception) {
            Log::warning('[plugins] enabled provider scan skipped', [
                'message' => $exception->getMessage(),
            ]);

            return;
        }

        $plugins->each(function (IntegrationPlugin $plugin): void {
            try {
                $this->activate($this->scanner->requireManifest((string) $plugin->domain, (string) $plugin->slug));
            } catch (\Throwable $exception) {
                Log::error('[plugins] provider boot failed', [
                    'domain' => $plugin->domain,
                    'slug' => $plugin->slug,
                    'message' => $exception->getMessage(),
                ]);
            }
        });
    }

    public function activate(PluginManifest $manifest): void
    {
        $providerClass = $manifest->providerClass;
        if ($providerClass === null || isset($this->bootedProviders[$providerClass])) {
            return;
        }

        $this->fileLoader->ensureLoaded($manifest);

        if (! is_subclass_of($providerClass, ServiceProvider::class)) {
            throw new BusinessException('插件服务提供者必须继承 Laravel ServiceProvider', 42200);
        }

        /** @var ServiceProvider $provider */
        $provider = new $providerClass($this->container);

        // 基线快照：插件 register/boot 期间不允许注册系统级路由、调度或全局中间件，
        // 违反 AGENTS.md「插件不得注册系统级路由、调度或全局中间件」的边界。
        // 在启用入口（PluginInstaller::enable）调用本方法时，违规会抛异常并回滚插件为禁用。
        $router = $this->container->make(Router::class);
        $schedule = $this->container->make(Schedule::class);
        $baseline = $this->captureSystemLevelRegistrations($router, $schedule);

        $provider->register();
        $this->container->call([$provider, 'boot']);

        $this->assertNoSystemLevelRegistration($manifest, $router, $schedule, $baseline);

        $this->bootedProviders[$providerClass] = true;
    }

    /**
     * @return array{routes:int,schedules:int,middleware:int,middleware_groups:int}
     */
    private function captureSystemLevelRegistrations(Router $router, Schedule $schedule): array
    {
        return [
            'routes' => count($router->getRoutes()->getRoutes()),
            'schedules' => count($schedule->events()),
            'middleware' => count($router->getMiddleware()),
            'middleware_groups' => count($router->getMiddlewareGroups()),
        ];
    }

    /**
     * @param  array{routes:int,schedules:int,middleware:int,middleware_groups:int}  $baseline
     */
    private function assertNoSystemLevelRegistration(PluginManifest $manifest, Router $router, Schedule $schedule, array $baseline): void
    {
        $now = $this->captureSystemLevelRegistrations($router, $schedule);
        $violations = [];

        // addons 域插件（如 ZJMF Bridge）以提供对外扩展接口为核心能力，
        // 注册自己的路由命名空间与中间件别名属于合法边界；但系统级调度仍一律禁止。
        $isAddon = $manifest->domain === PluginDomain::ADDONS;

        if (! $isAddon && $now['routes'] > $baseline['routes']) {
            $violations[] = '系统级路由';
        }
        if ($now['schedules'] > $baseline['schedules']) {
            $violations[] = '系统级调度';
        }
        if (! $isAddon && ($now['middleware'] > $baseline['middleware'] || $now['middleware_groups'] > $baseline['middleware_groups'])) {
            $violations[] = '全局中间件';
        }

        if ($violations === []) {
            return;
        }

        Log::error('[plugins] 插件 Provider 注册了系统级组件，已拒绝激活', [
            'domain' => $manifest->domain,
            'slug' => $manifest->slug,
            'provider' => $manifest->providerClass,
            'violations' => $violations,
        ]);

        throw new BusinessException('插件 Provider 不得注册系统级'.implode('、', $violations).'，已拒绝激活', 42200);
    }
}
