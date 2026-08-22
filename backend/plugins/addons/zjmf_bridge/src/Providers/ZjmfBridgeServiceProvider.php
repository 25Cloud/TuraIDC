<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\ZjmfBridge\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use TuraIDC\Plugins\Addons\ZjmfBridge\Http\Middleware\AuthenticateZjmfClient;
use TuraIDC\Plugins\Addons\ZjmfBridge\Http\Middleware\LogZjmfBridgeRequest;
use TuraIDC\Plugins\Addons\ZjmfBridge\Http\Middleware\ResolveZjmfActor;
use TuraIDC\Plugins\Addons\ZjmfBridge\Http\Middleware\VerifyZjmfSignature;
use TuraIDC\Plugins\Addons\ZjmfBridge\Http\Middleware\ZjmfBridgeEnabled;

class ZjmfBridgeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/zjmf_bridge.php', 'zjmf_bridge');
    }

    public function boot(Router $router): void
    {
        $router->aliasMiddleware('zjmf.enabled', ZjmfBridgeEnabled::class);
        $router->aliasMiddleware('zjmf.signature', VerifyZjmfSignature::class);
        $router->aliasMiddleware('zjmf.client', AuthenticateZjmfClient::class);
        $router->aliasMiddleware('zjmf.actor', ResolveZjmfActor::class);
        $router->aliasMiddleware('zjmf.log', LogZjmfBridgeRequest::class);

        Route::middleware(['api', 'zjmf.enabled', 'zjmf.actor', 'zjmf.log'])
            ->prefix(config('zjmf_bridge.base_path', 'zjmf/v1'))
            ->name('zjmf.v1.')
            ->group(dirname(__DIR__, 2).'/routes/zjmf.php');

        Route::middleware(['api', 'auth:sanctum', 'ensure.client'])
            ->prefix('api/v2/client')
            ->group(dirname(__DIR__, 2).'/routes/client.php');
    }
}
