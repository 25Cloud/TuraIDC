<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Integrations\Plugins\PluginDomain;
use App\Services\Integrations\Plugins\PluginRuntimeRegistry;
use App\Services\Upstream\Contracts\UpstreamDriver;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use App\Services\Upstream\Support\WebSessionCookieParser;
use Illuminate\Support\ServiceProvider;

class UpstreamServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HostingPanelApiTransport::class);
        $this->app->singleton(
            WebSessionCookieParser::class,
            fn (): WebSessionCookieParser => new WebSessionCookieParser(
                $this->app->tagged('upstream.web_session_credential_parsers')
            )
        );

        $this->app->singleton(ProviderRegistry::class, function ($app): ProviderRegistry {
            return new ProviderRegistry(
                $app->make(PluginRuntimeRegistry::class)->resolveEntries(
                    PluginDomain::UPSTREAM,
                    UpstreamDriver::class,
                )
            );
        });

        $this->app->singleton(ProviderResolver::class);
    }
}
