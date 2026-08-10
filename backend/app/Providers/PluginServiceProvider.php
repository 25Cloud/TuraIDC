<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Integrations\Plugins\IntegrationPluginService;
use App\Services\Integrations\Plugins\PluginConfigRepository;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginInstaller;
use App\Services\Integrations\Plugins\PluginProviderRegistry;
use App\Services\Integrations\Plugins\PluginRuntimeRegistry;
use App\Services\Integrations\Plugins\PluginScanner;
use Illuminate\Support\ServiceProvider;

class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PluginScanner::class);
        $this->app->singleton(PluginFileLoader::class);
        $this->app->singleton(PluginConfigRepository::class);
        $this->app->singleton(PluginProviderRegistry::class);
        $this->app->singleton(PluginRuntimeRegistry::class);
        $this->app->singleton(PluginInstaller::class);
        $this->app->singleton(IntegrationPluginService::class);
    }

    public function boot(PluginProviderRegistry $providers): void
    {
        $providers->bootEnabledProviders();
    }
}
