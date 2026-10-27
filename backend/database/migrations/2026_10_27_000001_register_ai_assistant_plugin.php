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
 * 内置插件登记：AI 助手（addons/ai_assistant）。
 *
 * 背景：AI 对话、会话审计与管理端治理接口已从核心 routes 搬进
 * plugins/addons/ai_assistant，路由由插件 Provider 在“已启用”状态下注册。
 * 老实例升级后如果只 `php artisan migrate` 而不去后台点安装/启用，
 * /api/v2/{client,admin}/ai/* 会全部 404 —— 前端约定路径不能变，
 * 所以登记动作必须随迁移一次性完成。
 *
 * 做法（全部幂等，可重复执行）：
 * 1. PluginInstaller::install() —— 写/更新 integration_plugins 登记行（updateOrCreate）；
 * 2. 首次登记时调 PluginLifecycleRunner::install()（插件五个生命周期方法都幂等）；
 * 3. 尚未启用时 PluginInstaller::enable() —— 写入启用状态并激活插件 Provider；
 * 4. 版本升高时调 upgrade() 钩子，把老实例从旧版本带上当前版本。
 *
 * 任一步失败只记日志、不阻断 migrate：插件目录缺失或清单损坏时，
 * 站长仍可在「插件管理」里手动安装，不能让整个升级卡死。
 */
return new class extends Migration
{
    private const DOMAIN = PluginDomain::ADDONS;

    private const SLUG = 'ai_assistant';

    public function up(): void
    {
        // 安装向导早期可能还没有插件表；补跑 migrate 时会重新执行本迁移。
        if (! Schema::hasTable('integration_plugins')) {
            return;
        }

        try {
            $scanner = app(PluginScanner::class);
            if ($scanner->find(self::DOMAIN, self::SLUG) === null) {
                Log::warning('[ai_assistant] 插件目录缺失，跳过内置登记', [
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

            if ((int) $plugin->status !== IntegrationPlugin::STATUS_ENABLED) {
                $plugin = app(PluginInstaller::class)->enable($plugin);
                $lifecycle->enable($manifest, $context);
            }

            Log::info('[ai_assistant] 内置插件已登记', [
                'plugin_id' => (int) $plugin->id,
                'version' => (string) $plugin->version,
                'status' => (int) $plugin->status,
            ]);
        } catch (\Throwable $exception) {
            Log::error('[ai_assistant] 内置插件登记失败，请在插件管理里手动安装/启用', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * 回滚不注销插件：注销会让老实例的 AI 接口立刻 404，
     * 而表与业务数据本来就要保留（插件标准 v2 §3）。
     */
    public function down(): void {}
};
