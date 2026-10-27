<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Services\Integrations\Data;

/**
 * 一次模型响应：正文、思考过程、工具调用、用量与结束原因。
 *
 * reasoning 是推理模型的思考内容（DeepSeek 的 reasoning_content、
 * Anthropic 的 thinking 块、Responses 的 reasoning 摘要），只用于展示，
 * 不参与后续工具决策，也不回灌给下一轮。
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
        public readonly string $reasoning = '',
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }

    public function hasReasoning(): bool
    {
        return trim($this->reasoning) !== '';
    }
}
