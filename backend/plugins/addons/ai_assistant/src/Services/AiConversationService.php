<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Services;

use TuraIDC\Plugins\Addons\AiAssistant\Contracts\AiProviderInterface;
use App\Exceptions\BusinessException;
use TuraIDC\Plugins\Addons\AiAssistant\Models\AiConversation;
use TuraIDC\Plugins\Addons\AiAssistant\Models\AiMessage;
use TuraIDC\Plugins\Addons\AiAssistant\Models\AiToolInvocation;
use App\Models\User;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Integrations\Data\AiChatMessage;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Integrations\Data\AiToolCall;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Integrations\Data\AiUsage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * 会话与消息持久化：归属校验、上下文装配、待确认动作读取都在这里。
 */
final class AiConversationService
{
    private const MODE_ORDER = [
        AiConversation::MODE_READ_ONLY => 0,
        AiConversation::MODE_CONFIRM => 1,
        AiConversation::MODE_AUTO_APPROVE => 2,
    ];

    public function __construct(
        private readonly AiConfig $config,
        private readonly AiConfirmToken $tokens,
    ) {}

    public function listForUser(User $user, int $page, int $perPage): LengthAwarePaginator
    {
        return AiConversation::query()
            ->where('user_id', (int) $user->id)
            ->where('status', AiConversation::STATUS_ACTIVE)
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->paginate(perPage: min(max($perPage, 1), 50), page: max($page, 1));
    }

    public function createForUser(
        User $user,
        string $channel,
        ?string $subjectType,
        ?int $subjectId,
        ?string $requestedMode,
        string $title = '',
    ): AiConversation {
        return AiConversation::query()->create([
            'user_id' => (int) $user->id,
            'channel' => in_array($channel, ['console', 'site', 'admin'], true) ? $channel : 'console',
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'title' => mb_substr(trim($title), 0, 120),
            'permission_mode' => $this->resolvePermissionMode($requestedMode),
            'status' => AiConversation::STATUS_ACTIVE,
        ]);
    }

    public function findForUser(User $user, int $id): AiConversation
    {
        $conversation = AiConversation::query()
            ->where('user_id', (int) $user->id)
            ->where('status', AiConversation::STATUS_ACTIVE)
            ->find($id);

        if (! $conversation instanceof AiConversation) {
            // 他人会话与不存在一律 404，避免探测
            throw new BusinessException('对话不存在', 40400, 404);
        }

        return $conversation;
    }

    public function messages(AiConversation $conversation, int $page = 1, int $perPage = 50): LengthAwarePaginator
    {
        return AiMessage::query()
            ->where('conversation_id', (int) $conversation->id)
            ->orderBy('id')
            ->paginate(perPage: min(max($perPage, 1), 100), page: max($page, 1));
    }

    /**
     * @param  array<int, array<string, mixed>>  $toolRuns
     */
    public function appendMessage(
        AiConversation $conversation,
        string $role,
        string $content,
        array $toolRuns = [],
        ?string $toolCallId = null,
        AiUsage $usage = new AiUsage,
        string $reasoning = '',
    ): AiMessage {
        $message = AiMessage::query()->create([
            'conversation_id' => (int) $conversation->id,
            'role' => $role,
            'content' => $content,
            'reasoning' => $reasoning !== '' ? $reasoning : null,
            'tool_calls' => $toolRuns !== [] ? $toolRuns : null,
            'tool_call_id' => $toolCallId,
            'prompt_tokens' => $usage->promptTokens,
            'completion_tokens' => $usage->completionTokens,
        ]);

        $conversation->message_count = (int) $conversation->message_count + 1;
        $conversation->last_message_at = now();
        if (trim((string) $conversation->title) === '' && $role === AiMessage::ROLE_USER) {
            $conversation->title = mb_substr(trim($content), 0, 60);
        }
        $conversation->save();

        return $message;
    }

    /**
     * 装配给模型的上下文：只带最近的用户/助手文本，工具结果不重放（避免历史工具结果污染当前判断）。
     *
     * @return array<int, AiChatMessage>
     */
    public function history(AiConversation $conversation, ?int $limit = null): array
    {
        $limit = $limit ?? $this->config->historyLimit();

        $rows = AiMessage::query()
            ->where('conversation_id', (int) $conversation->id)
            ->whereIn('role', [AiMessage::ROLE_USER, AiMessage::ROLE_ASSISTANT])
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse();

        $messages = [];
        foreach ($rows as $row) {
            $content = (string) ($row->content ?? '');
            if ($content === '') {
                continue;
            }
            $messages[] = $row->role === AiMessage::ROLE_USER
                ? AiChatMessage::user($content)
                : AiChatMessage::assistant($content);
        }

        return $messages;
    }

    public function recordUsage(AiConversation $conversation, AiUsage $usage, int $costCents): void
    {
        $conversation->prompt_tokens = (int) $conversation->prompt_tokens + $usage->promptTokens;
        $conversation->completion_tokens = (int) $conversation->completion_tokens + $usage->completionTokens;
        $conversation->cost_cents = (int) $conversation->cost_cents + $costCents;
        $conversation->last_message_at = now();
        $conversation->save();
    }

    /**
     * 待确认动作（未过期）。令牌是确定性的，这里重新签发，刷新页面后卡片仍然可点。
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingActions(AiConversation $conversation): array
    {
        $rows = AiToolInvocation::query()
            ->where('conversation_id', (int) $conversation->id)
            ->where('status', AiToolInvocation::STATUS_PENDING)
            ->where('created_at', '>=', now()->subMinutes($this->config->confirmTtlMinutes()))
            ->orderBy('id')
            ->get();

        return array_values(array_map(fn (AiToolInvocation $row): array => $this->presentInvocation($row), $rows->all()));
    }

    /**
     * @return array<string, mixed>
     */
    public function presentInvocation(AiToolInvocation $invocation): array
    {
        $arguments = is_array($invocation->arguments) ? $invocation->arguments : [];
        $token = $this->tokens->issue($invocation);

        return [
            'id' => (int) $invocation->id,
            'tool' => (string) $invocation->tool_name,
            'risk' => (string) $invocation->risk,
            'title' => (string) ($arguments['__title'] ?? $invocation->tool_name),
            'description' => (string) ($arguments['__description'] ?? ''),
            'arguments_summary' => (string) ($arguments['__summary'] ?? ''),
            'impact' => (string) ($arguments['__impact'] ?? ''),
            'amount_cents' => is_numeric($arguments['__amount_cents'] ?? null) ? (int) $arguments['__amount_cents'] : null,
            'expires_at' => (string) ($invocation->created_at?->copy()->addMinutes($this->config->confirmTtlMinutes())->format('Y-m-d H:i:s') ?? ''),
            'confirm_token' => $token,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentConversation(AiConversation $conversation): array
    {
        return [
            'id' => (int) $conversation->id,
            'title' => (string) $conversation->title,
            'channel' => (string) $conversation->channel,
            'permission_mode' => (string) $conversation->permission_mode,
            'message_count' => (int) $conversation->message_count,
            'last_message_at' => $conversation->last_message_at?->format('Y-m-d H:i:s'),
            'created_at' => $conversation->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $conversation->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentMessage(AiMessage $message): array
    {
        return [
            'id' => (int) $message->id,
            'role' => (string) $message->role,
            'content' => (string) ($message->content ?? ''),
            'reasoning' => (string) ($message->reasoning ?? ''),
            'tool_calls' => is_array($message->tool_calls) ? $message->tool_calls : null,
            'created_at' => $message->created_at?->format('Y-m-d H:i:s'),
        ];
    }

    /** 权限档位收敛：不允许超过站点配置的最高档位 */
    public function resolvePermissionMode(?string $requested): string
    {
        $max = $this->config->maxPermissionMode();
        $candidate = $requested !== null && in_array($requested, AiConversation::PERMISSION_MODES, true)
            ? $requested
            : $this->config->defaultPermissionMode();

        $maxOrder = self::MODE_ORDER[$max] ?? 2;
        $candidateOrder = self::MODE_ORDER[$candidate] ?? 1;

        return $candidateOrder <= $maxOrder
            ? $candidate
            : $max;
    }

    /**
     * @return array<int, string>
     */
    public function allowedPermissionModes(): array
    {
        $maxOrder = self::MODE_ORDER[$this->config->maxPermissionMode()] ?? 2;

        return array_values(array_filter(
            AiConversation::PERMISSION_MODES,
            static fn (string $mode): bool => (self::MODE_ORDER[$mode] ?? 0) <= $maxOrder,
        ));
    }

    /** 供 AiChatService 记录工具摘要 */
    public function toolRunSummary(AiToolCall $call, string $status, string $summary): array
    {
        return [
            'name' => $call->name,
            'risk' => '',
            'status' => $status,
            'summary' => $summary,
        ];
    }

    public function providerMissing(): BusinessException
    {
        return new BusinessException('AI 网关尚未配置，请联系管理员', 42200, 422);
    }

    /** 供外部判断驱动是否就绪（避免在无驱动时反复抛错） */
    public function driverReady(AiProviderInterface $driver): bool
    {
        return $driver->key() !== '';
    }
}
