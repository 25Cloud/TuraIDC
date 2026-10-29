<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\IntegrationPlugin;
use App\Services\Integrations\Plugins\PluginDomain;
use Throwable;

/**
 * 可选功能（由插件提供、但界面长在核心里的那部分能力）的可用性判定。
 *
 * 为什么需要它：插件规范规定「插件不能自行注册管理端菜单、权限、迁移和任意路由」
 * （docs/develop/plugins/index.md 第 9 节），所以由插件提供的管理端页面必须是**核心页面**，
 * 路由与侧栏菜单都写在核心。可核心并不知道插件在不在：`addons/ai_assistant` 被停用或卸载后，
 * `/api/v2/admin/ai/*` 随插件 Provider 一起消失，而侧栏「AI 助手」还挂着——点进去就是
 * 一串 404。这不是插件违规，是核心少了「按插件状态收敛入口」这一步。
 *
 * 口径：**装且启用**才算可用。停用与卸载都视为不可用（卸载会把登记行硬删掉）。
 * 表不存在（未安装/迁移没跑）一律视为不可用，且绝不抛异常——登录接口不能因此 500。
 */
final class OptionalFeature
{
    /** AI 助手（插件 addons/ai_assistant） */
    public const AI_ASSISTANT = 'ai_assistant';

    /**
     * 全部可选功能及其可用性。
     *
     * 新增一个「插件提供 + 核心托管页面」的能力时，在这里加一行即可；
     * 前端只认这个键名（见 frontend-admin-v3 的 ADMIN_MENU_GROUPS / meta.requiresFeature）。
     *
     * @return array<string, bool>
     */
    public static function map(): array
    {
        return [
            self::AI_ASSISTANT => self::pluginEnabled(PluginDomain::ADDONS, 'ai_assistant'),
        ];
    }

    public static function enabled(string $feature): bool
    {
        return self::map()[$feature] ?? false;
    }

    private static function pluginEnabled(string $domain, string $slug): bool
    {
        try {
            if (! DatabaseSchema::hasTable('integration_plugins')) {
                return false;
            }

            $plugin = IntegrationPlugin::query()
                ->where('domain', $domain)
                ->where('slug', $slug)
                ->first();

            return $plugin instanceof IntegrationPlugin && $plugin->isEnabled();
        } catch (Throwable) {
            // 登录接口上任何查询异常都不该把管理员挡在门外
            return false;
        }
    }
}
