<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant;

use App\Contracts\Integrations\Plugins\PluginLifecycleInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use TuraIDC\Plugins\Addons\AiAssistant\Services\AiConfig;

/**
 * AI 助手插件生命周期（插件标准 v2）。
 *
 * 五个方法全部幂等：
 * - install / upgrade / enable 只做只读自检与日志，重复调用不会写业务数据；
 * - disable 不清理任何业务数据（会话与用量要留给人审计）；
 * - uninstall 同样不删表：ai_* 四张表属于核心迁移，老实例里已经有生产数据。
 *
 * 为什么不在这里建表 / 写默认值：
 * 本插件是“把既有核心能力搬进插件”，四张 ai_* 表由核心迁移
 * 2026_10_26_000001_create_ai_assistant_tables 创建并已在生产有数据；
 * 网关地址、模型、密钥与额度全部由 settings 组 ai 承载（管理端「AI 设置」页唯一入口），
 * 默认值来自 config/ai.php，因此无需在安装时落一份可能过期的默认值副本。
 */
final class AiAssistantLifecycle implements PluginLifecycleInterface
{
    /** 由核心迁移创建、本插件只读写的四张表 */
    private const TABLES = [
        'ai_conversations',
        'ai_messages',
        'ai_tool_invocations',
        'ai_usage_daily',
    ];

    public function __construct(
        private readonly AiConfig $config,
    ) {}

    public function install(array $context): void
    {
        $this->probeTables('install', $context);

        Log::info('[ai_assistant] plugin installed', $this->summary($context));
    }

    public function enable(array $context): void
    {
        $this->probeTables('enable', $context);

        if (! $this->config->isConfigured()) {
            Log::warning('[ai_assistant] 插件已启用但网关地址或模型名称为空，对话接口会提示未配置', $this->summary($context));
        }

        Log::info('[ai_assistant] plugin enabled', $this->summary($context));
    }

    public function disable(array $context): void
    {
        // 停用只影响路由注册与运行时，不清理会话：审计数据必须留给站长。
        Log::info('[ai_assistant] plugin disabled, 会话与用量数据保留', $this->summary($context));
    }

    public function uninstall(array $context): void
    {
        // 插件标准 v2 §3：卸载默认不删业务表。ai_* 表在核心迁移里且已有生产数据，
        // 需要清理时由站长按 README「数据表」一节的独立命令手动执行。
        Log::warning('[ai_assistant] plugin uninstalled, ai_* 表按约定保留', $this->summary($context));
    }

    public function upgrade(string $fromVersion, string $toVersion, array $context): void
    {
        $this->probeTables('upgrade', $context);

        Log::info('[ai_assistant] plugin upgraded', $this->summary($context) + [
            'from_version' => $fromVersion,
            'to_version' => $toVersion,
        ]);
    }

    /**
     * 缺表只告警不抛异常：插件安装不应该因为核心迁移尚未执行而整体失败，
     * 站长补跑 `php artisan migrate` 后下一次启用会重新自检。
     *
     * @param  array<string, mixed>  $stage
     */
    private function probeTables(string $stage, array $context): void
    {
        $missing = [];

        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $missing[] = $table;
            }
        }

        if ($missing === []) {
            return;
        }

        Log::warning('[ai_assistant] 核心 AI 表缺失，请先执行 php artisan migrate', $this->summary($context) + [
            'stage' => $stage,
            'missing_tables' => $missing,
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function summary(array $context): array
    {
        return [
            'plugin_id' => $context['plugin_id'] ?? null,
            'version' => $context['version'] ?? '1.0.0',
            'protocol' => $this->config->protocol(),
            'model' => $this->config->model(),
            'enabled' => $this->config->enabled(),
            'configured' => $this->config->isConfigured(),
        ];
    }
}
