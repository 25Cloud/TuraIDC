<?php

declare(strict_types=1);

use App\Models\IntegrationPlugin;
use App\Services\Integrations\Plugins\PluginDomain;
use App\Services\Integrations\Plugins\PluginInstaller;
use App\Services\Integrations\Plugins\PluginLifecycleRunner;
use App\Services\Integrations\Plugins\PluginScanner;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 内置插件登记：自助退款（addons/auto_refund）。
 *
 * 为什么登记随迁移做：插件路由由插件 Provider 在「已安装」状态下注册，
 * 老实例升级后只 `php artisan migrate` 而没去后台点安装，控制台「退款中心」
 * 与 `/api/v2/{client,admin}/auto-refund/*` 就会整体不存在。登记一次省掉这一步。
 *
 * **只安装、不启用**（与 `addons/ai_assistant` 的登记不同，这是刻意的）：
 * 这是一个会动钱的插件，启用前必须先填上游地址与 API KEY，否则站长一升级
 * 就在侧栏看到「退款中心」、点进去只有「暂未开放」。因此开关留给站长：
 * 后台「插件管理」填完配置再启用，前端随之出现入口（`OptionalFeature::map()`）。
 *
 * 做法（全部幂等，可重复执行）：
 * 1. PluginInstaller::install() —— 写/更新 integration_plugins 登记行；
 * 2. 首次登记时调 PluginLifecycleRunner::install()（本插件没有 lifecycle 类时是 no-op）；
 * 3. 版本升高时调 upgrade() 钩子。
 *
 * 任一步失败只记日志、不阻断 migrate：插件目录缺失或清单损坏时，站长仍可在
 * 「插件管理」里手动安装，不能让整个升级卡死。
 */
return new class extends Migration
{
    private const DOMAIN = PluginDomain::ADDONS;

    private const SLUG = 'auto_refund';

    public function up(): void
    {
        // 安装向导早期可能还没有插件表；补跑 migrate 时会重新执行本迁移。
        if (! Schema::hasTable('integration_plugins')) {
            return;
        }

        try {
            $scanner = app(PluginScanner::class);
            if ($scanner->find(self::DOMAIN, self::SLUG) === null) {
                Log::warning('[auto_refund] 插件目录缺失，跳过内置登记', [
                    'domain' => self::DOMAIN,
                    'slug' => self::SLUG,
                ]);

                return;
            }

            $manifest = $scanner->requireManifest(self::DOMAIN, self::SLUG);

            $existing = IntegrationPlugin::query()
                ->where('domain', self::DOMAIN)
                ->where('slug', self::SLUG)
                ->first(['id', 'version', 'status']);

            $plugin = app(PluginInstaller::class)->install(self::DOMAIN, self::SLUG);
            $lifecycle = app(PluginLifecycleRunner::class);
            $context = ['plugin_id' => (int) $plugin->id];

            if (! $existing instanceof IntegrationPlugin) {
                $lifecycle->install($manifest, $context);
            } elseif (version_compare((string) $existing->version, $manifest->version, '<')) {
                $lifecycle->upgrade($manifest, (string) $existing->version, $manifest->version, $context);
            }

            Log::info('[auto_refund] 内置插件已登记（未启用，等待站长配置上游后启用）', [
                'plugin_id' => (int) $plugin->id,
                'version' => (string) $plugin->version,
                'status' => (int) $plugin->status,
            ]);
        } catch (Throwable $exception) {
            Log::error('[auto_refund] 内置插件登记失败，请在插件管理里手动安装', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * 回滚不注销插件：注销会让台账与配置立刻失去入口，而表与业务数据本来就要保留。
     */
    public function down(): void {}
};
