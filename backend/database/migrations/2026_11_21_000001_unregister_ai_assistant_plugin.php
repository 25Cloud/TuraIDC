<?php

declare(strict_types=1);

use App\Models\IntegrationPlugin;
use App\Services\Integrations\Plugins\PluginDomain;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 注销内置插件 AI 助手（addons/ai_assistant）。
 *
 * 背景：网页端的 AI 助手已整体退役，改由「用户自己的 harness/agent + AI Skill 说明书
 * + 开放接口密钥」这套方案承接（见 resources/ai-skill/turaidc/SKILL.md）。插件目录、
 * 会话表使用与三端界面都随本次改动移除，这里把 integration_plugins 的登记行一并清掉，
 * 否则插件管理页会留一条指向不存在目录的记录、登录接口下发的 features 也会一直多一个键。
 *
 * 为什么是硬删而不是停用：卸载语义本来就是硬删登记行（见 PluginInstaller::uninstall），
 * 而停用会留下一条「装了但停用」的假记录，全新安装与老实例升级后的状态就不再一致。
 *
 * 数据面不动：ai_conversations / ai_messages / ai_tool_invocations / ai_usage_daily 四张表
 * 按「迁移只增不删表」的基线保留，不再读写；saas 侧如需归档请自行导出后处理。
 *
 * 幂等且绝不阻断升级：表不存在、登记行不存在、删除失败一律只记日志后继续。
 */
return new class extends Migration
{
    private const DOMAIN = PluginDomain::ADDONS;

    private const SLUG = 'ai_assistant';

    public function up(): void
    {
        if (! Schema::hasTable('integration_plugins')) {
            return;
        }

        try {
            $deleted = IntegrationPlugin::query()
                ->where('domain', self::DOMAIN)
                ->where('slug', self::SLUG)
                ->delete();

            if ($deleted > 0) {
                Log::info('[ai_assistant] 插件登记行已注销（网页端 AI 助手改由 AI Skill 接入承接）', [
                    'domain' => self::DOMAIN,
                    'slug' => self::SLUG,
                    'deleted' => $deleted,
                ]);
            }
        } catch (Throwable $exception) {
            Log::error('[ai_assistant] 插件登记行注销失败，可在「插件管理」里手动卸载', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * 不回滚：恢复一条指向已删除目录的登记行没有任何意义。
     */
    public function down(): void {}
};