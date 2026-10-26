<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiToolInvocation;
use App\Models\AiUsageDaily;
use App\Services\Ai\AiConfig;
use App\Services\Ai\Tools\AiToolRegistry;
use App\Services\Integrations\Ai\AiProviderManager;
use App\Services\Integrations\Ai\Data\AiChatMessage;
use App\Services\Integrations\Ai\Data\AiChatRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * AI 网关自检：配置完整性 + 数据表 + 工具注册表，--remote 时再打一次真实网关。
 *
 * 部署后先跑它，比等用户点开浮窗再报错省事。
 */
final class AiDoctorCommand extends Command
{
    protected $signature = 'ai:doctor {--remote : 额外执行一次真实连通性测试（会消耗少量 token）}';

    protected $description = '自检 AI 助手配置、数据表与工具注册表';

    public function handle(AiConfig $config, AiToolRegistry $registry, AiProviderManager $providers): int
    {
        $this->info('TuraIDC AI 助手自检');
        $failures = 0;

        $failures += $this->check('开关 enabled', $config->enabled() ? '已开启' : '未开启（入口不会显示）', false);
        $failures += $this->check('协议 protocol', $config->protocol() . '（' . $config->protocolLabel() . '）', array_key_exists($config->protocol(), $config->protocols()));
        $failures += $this->check('网关地址 base_url', $config->baseUrl() !== '' ? $config->baseUrl() : '未配置', $config->baseUrl() !== '');
        $failures += $this->check('API 密钥', $config->hasApiKey() ? '已配置（加密存储）' : '未配置', $config->hasApiKey());
        $failures += $this->check('模型 model', $config->model() !== '' ? $config->model() : '未配置', $config->model() !== '');

        foreach (['ai_conversations', 'ai_messages', 'ai_tool_invocations', 'ai_usage_daily'] as $table) {
            $failures += $this->check('数据表 ' . $table, Schema::hasTable($table) ? '存在' : '缺失，请先执行 migrate', Schema::hasTable($table));
        }

        $tools = $registry->all();
        $failures += $this->check('工具注册表', count($tools) . ' 个：' . implode('、', array_keys($tools)), count($tools) >= 10);

        $failures += $this->check('每日额度', sprintf(
            '用户 %d 条 / %d token；全站 %d token / %d 分',
            $config->userDailyMessages(),
            $config->userDailyTokens(),
            $config->globalDailyTokens(),
            $config->globalDailyCostCents(),
        ), true);

        $failures += $this->check('权限档位', '默认 ' . $config->defaultPermissionMode() . '，上限 ' . $config->maxPermissionMode(), true);

        if ($this->option('remote')) {
            if (! $config->isConfigured()) {
                $this->error('网关地址或模型未配置，跳过远程测试');
                $failures++;
            } else {
                $this->line('正在测试真实网关连通性…');
                try {
                    $driver = $providers->driver();
                    $startedAt = microtime(true);
                    $result = $driver->chat(new AiChatRequest(
                        model: $config->model(),
                        messages: [AiChatMessage::user('请回复四个字：连接正常')],
                        tools: [],
                        temperature: 0.1,
                        maxTokens: 32,
                        timeoutSeconds: 15,
                    ));
                    $latency = (int) round((microtime(true) - $startedAt) * 1000);
                    $this->info(sprintf(
                        '远程连通正常：%s，耗时 %dms，回复「%s」，用量 %d+%d',
                        $driver->key(),
                        $latency,
                        mb_substr(trim($result->content), 0, 40),
                        $result->usage->promptTokens,
                        $result->usage->completionTokens,
                    ));
                } catch (Throwable $exception) {
                    $this->error('远程连通失败：' . $exception->getMessage());
                    $failures++;
                }
            }
        }

        $this->line('');
        $this->line(sprintf(
            '会话 %d 个 / 消息 %d 条 / 工具调用 %d 次 / 用量行 %d 条',
            AiConversation::query()->count(),
            AiMessage::query()->count(),
            AiToolInvocation::query()->count(),
            AiUsageDaily::query()->count(),
        ));

        if ($failures > 0) {
            $this->error('自检发现 ' . $failures . ' 项问题');

            return self::FAILURE;
        }

        $this->info('自检通过');

        return self::SUCCESS;
    }

    private function check(string $label, string $value, bool $ok): int
    {
        $prefix = $ok ? '<info>✔</info>' : '<comment>!</comment>';
        $this->line($prefix . ' ' . $label . '：' . $value);

        return $ok ? 0 : 1;
    }
}
