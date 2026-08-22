<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Servers\ZjmfFinance\Providers;

use App\Services\Upstream\Contracts\UpstreamBillingRestoreProfile;
use Illuminate\Support\ServiceProvider;
use TuraIDC\Plugins\Servers\ZjmfFinance\Commands\RestoreZjmfBillingCommand;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfBillingRestoreProfile;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfBillingRestoreService;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfCredentialParser;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfLegacyPasswordVerifier;

final class ZjmfFinanceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ZjmfCredentialParser::class);
        $this->app->singleton(ZjmfLegacyPasswordVerifier::class);
        $this->app->singleton(ZjmfBillingRestoreProfile::class);
        $this->app->singleton(UpstreamBillingRestoreProfile::class, ZjmfBillingRestoreProfile::class);
        $this->app->singleton(ZjmfBillingRestoreService::class);

        $this->app->tag([ZjmfCredentialParser::class], 'upstream.web_session_credential_parsers');
        $this->app->tag([ZjmfLegacyPasswordVerifier::class], 'auth.legacy_password_verifiers');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                RestoreZjmfBillingCommand::class,
            ]);
        }
    }
}
