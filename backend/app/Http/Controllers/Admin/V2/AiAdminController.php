<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\Ai\ListAiConversationsRequest;
use App\Http\Requests\Admin\V2\Ai\UpdateAiSettingsRequest;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiToolInvocation;
use App\Models\AiUsageDaily;
use App\Models\Setting;
use App\Services\Ai\AiConfig;
use App\Services\Ai\AiConversationService;
use App\Services\Ai\Tools\AiToolRegistry;
use App\Services\Integrations\Ai\AiProviderManager;
use App\Services\Integrations\Ai\Data\AiChatMessage;
use App\Services\Integrations\Ai\Data\AiChatRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * 管理端 AI 治理：设置、连通性测试、用量、会话审计。
 *
 * 管理端按项目口径不做脱敏展示，但 API 密钥永远只回掩码。
 */
class AiAdminController extends Controller
{
    /** 可写设置项：api_key 单独处理（空值保留、非空替换） */
    private const SETTING_KEYS = [
        'enabled',
        'protocol',
        'base_url',
        'model',
        'temperature',
        'max_tokens',
        'console_enabled',
        'site_enabled',
        'admin_enabled',
        'allow_balance_pay',
        'user_daily_messages',
        'user_daily_tokens',
        'global_daily_tokens',
        'global_daily_cost_cents',
        'cost_per_1k_tokens_cents',
        'max_tool_rounds',
        'request_timeout_seconds',
        'history_message_limit',
        'redact_pii',
        'retention_days',
        'default_permission_mode',
        'max_permission_mode',
    ];

    public function __construct(
        private readonly AiConfig $config,
        private readonly AiConversationService $conversations,
        private readonly AiToolRegistry $registry,
        private readonly AiProviderManager $providers,
    ) {}

    public function settings(): JsonResponse
    {
        $values = [];
        foreach (self::SETTING_KEYS as $key) {
            $values[$key] = Setting::getValue(AiConfig::GROUP, $key);
        }
        $values['enabled'] = (int) Setting::getValue(AiConfig::GROUP, 'enabled', 0);
        $values['api_key'] = [
            'has_value' => $this->config->hasApiKey(),
            'masked_value' => $this->config->hasApiKey() ? '******' : '',
        ];
        $values['protocols'] = array_map(
            static fn (string $key, array $meta): array => ['key' => $key, 'label' => (string) ($meta['label'] ?? $key)],
            array_keys($this->config->protocols()),
            array_values($this->config->protocols()),
        );
        $values['permission_modes'] = [
            ['key' => 'read_only', 'label' => '仅可查看'],
            ['key' => 'confirm', 'label' => '完全权限（写操作需确认）'],
            ['key' => 'auto_approve', 'label' => '自动审批（beta）'],
        ];
        $values['tools'] = $this->registry->catalog(AiConversation::MODE_AUTO_APPROVE);
        $values['enabled_tools'] = $this->config->enabledTools();
        $values['protocol_label'] = $this->config->protocolLabel();

        return $this->success($values);
    }

    public function updateSettings(UpdateAiSettingsRequest $request): JsonResponse
    {
        $data = $request->validated();

        foreach (self::SETTING_KEYS as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (is_bool($value)) {
                $value = $value ? 1 : 0;
            }
            Setting::setValue(AiConfig::GROUP, $key, $value);
        }

        // 密钥：空值保留旧值，非空才替换；提交掩码视为未修改
        $apiKey = trim((string) ($data['api_key'] ?? ''));
        if ($apiKey !== '' && $apiKey !== '******') {
            Setting::setValue(AiConfig::GROUP, 'provider_api_key', $apiKey);
        }

        // 工具白名单：空数组表示放开全部工具，存空串
        if (array_key_exists('enabled_tools', $data)) {
            $tools = array_values(array_filter((array) $data['enabled_tools'], static fn ($item): bool => is_string($item) && $item !== ''));
            Setting::setValue(
                AiConfig::GROUP,
                'enabled_tools',
                $tools === [] ? '' : (string) json_encode($tools, JSON_UNESCAPED_UNICODE),
            );
        }

        return $this->success(['saved' => true], 'AI 设置已保存');
    }

    public function test(): JsonResponse
    {
        if (! $this->config->isConfigured()) {
            throw new BusinessException('请先填写网关地址与模型名称', 42200, 422);
        }

        $driver = $this->providers->driver();
        $startedAt = microtime(true);

        try {
            $result = $driver->chat(new AiChatRequest(
                model: $this->config->model(),
                messages: [AiChatMessage::user('请回复四个字：连接正常')],
                tools: [],
                temperature: 0.1,
                maxTokens: 32,
                timeoutSeconds: 15,
                stream: false,
            ));
        } catch (BusinessException $exception) {
            throw new BusinessException('连通性测试失败：' . $exception->getMessage(), 42200, 422);
        } catch (\Throwable $exception) {
            Log::warning('ai.settings_test_failed', ['error' => $exception->getMessage()]);

            throw new BusinessException('连通性测试失败：无法连接网关，请检查地址与网络', 42200, 422);
        }

        return $this->success([
            'ok' => true,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'protocol' => $driver->key(),
            'model' => $this->config->model(),
            'reply' => mb_substr(trim($result->content), 0, 80),
            'usage' => [
                'prompt_tokens' => $result->usage->promptTokens,
                'completion_tokens' => $result->usage->completionTokens,
            ],
        ], '连通正常');
    }

    public function usage(Request $request): JsonResponse
    {
        $days = min(max((int) $request->query('days', '14'), 1), 90);
        $from = now()->subDays($days - 1)->toDateString();

        $daily = AiUsageDaily::query()
            ->where('day', '>=', $from)
            ->selectRaw('day, SUM(messages) AS messages, SUM(prompt_tokens + completion_tokens) AS tokens, SUM(cost_cents) AS cost_cents')
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(static fn ($row): array => [
                'day' => (string) $row->day,
                'messages' => (int) $row->messages,
                'tokens' => (int) $row->tokens,
                'cost_cents' => (int) $row->cost_cents,
            ])->all();

        $topUsers = AiUsageDaily::query()
            ->where('day', '>=', $from)
            ->selectRaw('user_id, SUM(messages) AS messages, SUM(prompt_tokens + completion_tokens) AS tokens, SUM(cost_cents) AS cost_cents')
            ->groupBy('user_id')
            ->orderByDesc('tokens')
            ->limit(10)
            ->get()
            ->map(static fn ($row): array => [
                'user_id' => (int) $row->user_id,
                'email' => (string) (\App\Models\User::query()->whereKey((int) $row->user_id)->value('email') ?? ''),
                'messages' => (int) $row->messages,
                'tokens' => (int) $row->tokens,
                'cost_cents' => (int) $row->cost_cents,
            ])->all();

        $totals = [
            'messages' => array_sum(array_column($daily, 'messages')),
            'tokens' => array_sum(array_column($daily, 'tokens')),
            'cost_cents' => array_sum(array_column($daily, 'cost_cents')),
        ];

        return $this->success([
            'daily' => $daily,
            'top_users' => $topUsers,
            'totals' => $totals,
            'limits' => [
                'user_daily_messages' => $this->config->userDailyMessages(),
                'user_daily_tokens' => $this->config->userDailyTokens(),
                'global_daily_tokens' => $this->config->globalDailyTokens(),
                'global_daily_cost_cents' => $this->config->globalDailyCostCents(),
            ],
        ]);
    }

    public function conversations(ListAiConversationsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $query = AiConversation::query()->with('user')->orderByDesc('last_message_at')->orderByDesc('id');

        if (! empty($data['user_id'])) {
            $query->where('user_id', (int) $data['user_id']);
        }
        if (! empty($data['channel'])) {
            $query->where('channel', (string) $data['channel']);
        }
        if (isset($data['status']) && $data['status'] !== '') {
            $query->where('status', (int) $data['status']);
        }

        $paginator = $query->paginate(perPage: $request->perPage(20), page: (int) ($data['page'] ?? 1));

        return $this->paginate($paginator, null, [
            'list' => array_map(function (AiConversation $conversation): array {
                $row = $this->conversations->presentConversation($conversation);
                $row['user_id'] = (int) $conversation->user_id;
                $row['user_email'] = (string) ($conversation->user?->email ?? '');
                $row['status'] = (int) $conversation->status;

                return $row;
            }, $paginator->items()),
        ]);
    }

    public function showConversation(int $conversation): JsonResponse
    {
        $model = AiConversation::query()->with('user')->find($conversation);
        if (! $model instanceof AiConversation) {
            throw new BusinessException('对话不存在', 40400, 404);
        }

        $messages = AiMessage::query()
            ->where('conversation_id', (int) $model->id)
            ->orderBy('id')
            ->limit(200)
            ->get();

        $invocations = AiToolInvocation::query()
            ->where('conversation_id', (int) $model->id)
            ->orderBy('id')
            ->limit(100)
            ->get();

        return $this->success([
            'conversation' => array_merge($this->conversations->presentConversation($model), [
                'user_id' => (int) $model->user_id,
                'user_email' => (string) ($model->user?->email ?? ''),
                'status' => (int) $model->status,
            ]),
            'messages' => array_map(fn (AiMessage $message): array => $this->conversations->presentMessage($message), $messages->all()),
            'invocations' => array_map(static fn (AiToolInvocation $invocation): array => [
                'id' => (int) $invocation->id,
                'tool' => (string) $invocation->tool_name,
                'risk' => (string) $invocation->risk,
                'status' => (string) $invocation->status,
                'arguments' => $invocation->arguments,
                'result' => $invocation->result,
                'duration_ms' => (int) ($invocation->duration_ms ?? 0),
                'created_at' => $invocation->created_at?->format('Y-m-d H:i:s'),
            ], $invocations->all()),
        ]);
    }
}
