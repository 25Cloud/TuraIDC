<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Services\Integrations\Data;

/**
 * 一次模型请求：消息、工具定义、采样参数与超时。
 */
final class AiChatRequest
{
    /**
     * @param  array<int, AiChatMessage>  $messages
     * @param  array<int, array{name: string, description: string, parameters: array<string, mixed>}>  $tools
     */
    public function __construct(
        public readonly string $model,
        public readonly array $messages,
        public readonly array $tools = [],
        public readonly float $temperature = 0.3,
        public readonly int $maxTokens = 1200,
        public readonly int $timeoutSeconds = 60,
        public readonly bool $stream = false,
    ) {}

    public function hasTools(): bool
    {
        return $this->tools !== [];
    }
}
