<?php

namespace App\Providers;

use App\Models\Setting;
use App\Services\Auth\LegacyPasswordVerifier;
use App\Services\System\UploadedAssetReferenceService;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $mysqlConnection = config('database.connections.mysql', []);

        config([
            'database.default' => 'mysql',
            'database.connections' => [
                'mysql' => $mysqlConnection,
            ],
        ]);

        $this->app->singleton(UploadedAssetReferenceService::class);
        $this->app->singleton(
            LegacyPasswordVerifier::class,
            fn (): LegacyPasswordVerifier => new LegacyPasswordVerifier($this->app->tagged('auth.legacy_password_verifiers'))
        );
    }

    public function boot(): void
    {
        $this->loadSiteNameFromSettings();

        Sanctum::authenticateAccessTokensUsing(function (PersonalAccessToken $accessToken, bool $isValid): bool {
            if (! $isValid) {
                return false;
            }

            $idleTimeout = max((int) config('sanctum.idle_timeout', 0), 0);
            if ($idleTimeout <= 0) {
                return true;
            }

            $lastActiveAt = $accessToken->last_used_at ?? $accessToken->created_at;
            if (! $lastActiveAt instanceof CarbonInterface) {
                return true;
            }

            if ($lastActiveAt->lt(now()->subSeconds($idleTimeout))) {
                $accessToken->delete();

                return false;
            }

            return true;
        });
    }

    /**
     * 从数据库 settings 表加载管理员设置的站点名称，覆盖 config('app.name')。
     * settings 表不存在时（首次迁移前）静默跳过。
     */
    private function loadSiteNameFromSettings(): void
    {
        if (! Schema::hasTable('settings')) {
            return;
        }

        $siteName = trim((string) Setting::getValue('basic', 'site_name', ''));
        if ($siteName === '') {
            return;
        }

        config(['app.name' => $siteName]);
    }
}
