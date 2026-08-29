<?php

namespace App\Providers;

use App\Listeners\HeartbeatTaskTimedOutListener;
use App\Models\FirstProductGroup;
use App\Models\Product;
use App\Models\ProductDiscountGroup;
use App\Models\SecondProductGroup;
use App\Models\Setting;
use App\Models\ThirdProductGroup;
use App\Services\Auth\LegacyPasswordVerifier;
use App\Services\Automation\Heartbeat\HeartbeatTaskRegistry;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\OpenApi\OpenApiConfig;
use App\Services\ProductCatalog\ProductDisplayNameResolver;
use App\Services\ProductCatalog\ProductSpecHighlightService;
use App\Services\System\UploadedAssetReferenceService;
use Carbon\CarbonInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Throwable;

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

        // 绑定投影与商品显示名解析器都自带行级记忆化，但此前每个调用点都 app()/new 出新实例，
        // 缓存从未跨行生效。实测客户端服务列表（12 行/页）因此产生 508 次查询，
        // 其中 service_upstream_bindings 96 次、products.custom_display_name 60 次（仅 1 个不同商品）。
        // 注册为 singleton 后缓存才真正生效；写入侧已在 ServiceUpstreamBindingWriter 内做失效。
        $this->app->singleton(PluginBindingResolver::class);
        $this->app->singleton(ProductDisplayNameResolver::class);
        $this->app->singleton(ProductSpecHighlightService::class);
    }

    public function boot(): void
    {
        $this->loadSiteNameFromSettings();
        $this->registerOpenApiRateLimiter();

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

        $this->invalidateCatalogCacheOnCatalogChanges();
    }

    /**
     * 注册开放接口的限流器，落实后台可配的 open_api.rate_limit（默认 60/分钟）。
     *
     * 此前该值只在后台读写、没有任何中间件执行它 —— 等于设了限速却不生效。
     * 按来源 IP 计数：限流跑在 api.key 认证之前，认证前拿不到密钥；而攻击面正是
     * "任意 Bearer 头即可触发认证入口"，IP 恰是未认证攻击者唯一的可计量维度。
     * 阈值从 OpenApiConfig 每次读取（settings 表），管理员改动即时生效。
     * 超限统一走 bootstrap/app.php 里 ThrottleRequestsException 的中文 429 渲染。
     */
    private function registerOpenApiRateLimiter(): void
    {
        RateLimiter::for('open-api', function (Request $request) {
            $perMinute = app(OpenApiConfig::class)->rateLimitPerMinute();

            return Limit::perMinute($perMinute)->by((string) $request->ip());
        });
    }

    /**
     * 从数据库 settings 表加载管理员设置的站点名称，覆盖 config('app.name')。
     *
     * 全新部署时 .env 还没有数据库配置，Schema::hasTable() 会直接抛 QueryException，
     * provider boot 失败 → 整个应用起不来 → /install 安装向导也就永远进不去。
     * 因此这里必须吞掉全部异常（不只是「表不存在」），与 InstallService::isInstalled()
     * 的处理口径保持一致：站点名读不到就退回 config 默认值，不影响应用启动。
     */
    private function loadSiteNameFromSettings(): void
    {
        try {
            if (! Schema::hasTable('settings')) {
                return;
            }

            $siteName = trim((string) Setting::getValue('basic', 'site_name', ''));
        } catch (Throwable) {
            return;
        }

        if ($siteName === '') {
            return;
        }

        config(['app.name' => $siteName]);
    }

    /**
     * 商品/分组/折扣组的任何增删改都主动失效商品目录缓存：
     * 官网商品卡（tags: site-products）与管理端整树缓存均实时刷新，不依赖 TTL 过期。
     * 批量写入（如状态同步批量更新商品）会在 2s 内合并为一次失效，避免反复清缓存。
     */
    private function invalidateCatalogCacheOnCatalogChanges(): void
    {
        $lastFlushAt = null;
        $flush = function () use (&$lastFlushAt): void {
            $now = microtime(true);
            if ($lastFlushAt !== null && $now - $lastFlushAt < 2) {
                return;
            }
            $lastFlushAt = $now;

            Cache::tags(['site-products'])->flush();
            Cache::forget('coupon_product_group_tree_v1');
        };

        foreach ([
            Product::class,
            FirstProductGroup::class,
            SecondProductGroup::class,
            ThirdProductGroup::class,
            ProductDiscountGroup::class,
        ] as $model) {
            $model::saved($flush);
            $model::deleted($flush);
        }
    }
}
