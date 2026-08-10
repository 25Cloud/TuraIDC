<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\Integrations\Payments\PaymentGatewayInterface;
use App\Services\Integrations\Payments\PaymentGatewayManager;
use App\Services\Integrations\Payments\PaymentGatewayRegistry;
use App\Services\Integrations\Plugins\PluginDomain;
use App\Services\Integrations\Plugins\PluginRuntimeRegistry;
use App\Services\Mail\Contracts\MailDriver;
use App\Services\Mail\MailDriverManager;
use App\Services\Sms\Contracts\SmsDriver;
use App\Services\Sms\SmsDriverManager;
use App\Services\Verification\Contracts\VerificationDriver;
use App\Services\Verification\VerificationDriverManager;
use Illuminate\Support\ServiceProvider;

class IntegrationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PaymentGatewayRegistry::class, function ($app): PaymentGatewayRegistry {
            return new PaymentGatewayRegistry(
                $app->make(PluginRuntimeRegistry::class)->resolveEntries(
                    PluginDomain::PAYMENT,
                    PaymentGatewayInterface::class,
                )
            );
        });

        $this->app->singleton(PaymentGatewayManager::class);

        $this->app->singleton(VerificationDriverManager::class, function ($app): VerificationDriverManager {
            return new VerificationDriverManager(
                $app->make(PluginRuntimeRegistry::class)->resolveEntries(
                    PluginDomain::VERIFICATION,
                    VerificationDriver::class,
                )
            );
        });

        $this->app->singleton(SmsDriverManager::class, function ($app): SmsDriverManager {
            return new SmsDriverManager(
                $app->make(PluginRuntimeRegistry::class)->resolveEntries(
                    PluginDomain::SMS,
                    SmsDriver::class,
                )
            );
        });

        $this->app->singleton(MailDriverManager::class, function ($app): MailDriverManager {
            return new MailDriverManager(
                $app->make(PluginRuntimeRegistry::class)->resolveEntries(
                    PluginDomain::MAIL,
                    MailDriver::class,
                )
            );
        });
    }
}
