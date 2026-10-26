<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools;

use App\Models\AiConversation;
use App\Models\User;

/**
 * 工具执行上下文：用户与权限一律来自认证与落库的会话，模型无权指定。
 */
final class AiToolContext
{
    public function __construct(
        public readonly User $user,
        public readonly AiConversation $conversation,
        public readonly string $channel,
        public readonly string $permissionMode,
        public readonly string $traceId = '',
        /** 写操作的幂等键：确认执行时取 ai_tool_invocations.id，重复确认不会重复建单 */
        public readonly string $idempotencyKey = '',
    ) {}

    public function userId(): int
    {
        return (int) $this->user->id;
    }

    public function canWrite(): bool
    {
        return $this->permissionMode !== AiConversation::MODE_READ_ONLY;
    }

    /**
     * 传给业务 Service 的上下文：与既有控制台动作同构，便于审计串联。
     *
     * @return array<string, mixed>
     */
    public function actionContext(string $suffix = ''): array
    {
        return [
            'trace_id' => $this->traceId !== '' ? $this->traceId : 'ai:' . $this->conversation->id . $suffix,
            'source' => 'ai_assistant',
            'channel' => $this->channel,
            'conversation_id' => (int) $this->conversation->id,
        ];
    }
}
