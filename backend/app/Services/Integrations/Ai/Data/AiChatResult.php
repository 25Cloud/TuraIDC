<?php

declare(strict_types=1);

namespace App\Services\Integrations\Ai\Data;

/**
 * 一次模型响应：正文、工具调用、用量与结束原因。
 */
final class AiChatResult
{
    /**
     * @param  array<int, AiToolCall>  $toolCalls
     */
    public function __construct(
        public readonly string $content = '',
        public readonly array $toolCalls = [],
        public readonly AiUsage $usage = new AiUsage,
        public readonly string $finishReason = '',
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
