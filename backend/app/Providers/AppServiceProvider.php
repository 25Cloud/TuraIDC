<?php

namespace App\Providers;

use App\Listeners\HeartbeatTaskTimedOutListener;
use App\Models\Setting;
use App\Services\Auth\LegacyPasswordVerifier;
use App\Services\Automation\Heartbeat\HeartbeatTaskRegistry;
use App\Services\System\UploadedAssetReferenceService;
use Carbon\CarbonInterface;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * 收敛数据库连接并注册若干单例。
     *
     * 连接部分把 database.connections 收敛到 mysql，防止误用其他驱动；
     * 但队列若被配置到独立连接（DB_QUEUE_CONNECTION），必须把该连接一并保留，
     * 否则 Schema::connection() 找不到连接，QueueDrainService 会误判为
     * jobs 表缺失并跳过消费，导致队列静默停摆。
     */
    public function register(): void
    {
        $connections = (array) config('database.connections', []);
        $keptConnections = ['mysql' => (array) ($connections['mysql'] ?? [])];

        // 队列被配置到独立连接时（.env.example 里公开的 DB_QUEUE_CONNECTION），必须保留该连接定义。
        // 否则连接名在 config 中已被抹掉，Schema::connection() 抛异常，QueueDrainService 判为
        // jobs 表缺失并跳过消费；而本部署没有常驻 worker、queue:drain 是唯一消费者，
        // 结果是"填了一个文档里公开的合法配置项 → 队列整体静默停摆"。
        $queueConnection = trim((string) config('queue.connections.database.connection', ''));
        if ($queueConnection !== '' && $queueConnection !== 'mysql' && isset($connections[$queueConnection])) {
            $keptConnections[$queueConnection] = (array) $connections[$queueConnection];
        }

        config([
            'database.default' => 'mysql',
            'database.connections' => $keptConnections,
        ]);

        $this->app->singleton(UploadedAssetReferenceService::class);
        $this->app->singleton(
            LegacyPasswordVerifier::class,
            fn (): LegacyPasswordVerifier => new LegacyPasswordVerifier($this->app->tagged('auth.legacy_password_verifiers'))
        );
        // 任务注册表跨请求/跨 Job 复用：避免每个心跳 Job 重复扫描全部 Provider（插件清单、任务类实例化、契约校验）。
        $this->app->singleton(HeartbeatTaskRegistry::class);
    }

    public function boot(): void
    {
        $this->loadSiteNameFromSettings();

        // 心跳任务超时被杀时，Worker 在 SIGKILL 前同步派发 JobTimedOut；
        // 监听器把运行台账收敛为 retrying/failed，避免队列重试被状态 CAS 永久拒绝。
        Event::listen(JobTimedOut::class, HeartbeatTaskTimedOutListener::class);

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
