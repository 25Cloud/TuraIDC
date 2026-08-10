<?php

declare(strict_types=1);

namespace EwyFinance\Plugins\Servers\ZjmfFinance\Providers;

use App\Services\Upstream\Contracts\UpstreamBillingRestoreProfile;
use EwyFinance\Plugins\Servers\ZjmfFinance\Commands\RestoreZjmfBillingCommand;
use EwyFinance\Plugins\Servers\ZjmfFinance\Lib\ZjmfBillingRestoreProfile;
use EwyFinance\Plugins\Servers\ZjmfFinance\Lib\ZjmfBillingRestoreService;
use EwyFinance\Plugins\Servers\ZjmfFinance\Lib\ZjmfCredentialParser;
use EwyFinance\Plugins\Servers\ZjmfFinance\Lib\ZjmfLegacyPasswordVerifier;
use Illuminate\Support\ServiceProvider;

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
