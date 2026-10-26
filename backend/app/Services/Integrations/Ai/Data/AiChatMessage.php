<?php

declare(strict_types=1);

namespace App\Services\Integrations\Ai\Data;

/**
 * 中间层消息：驱动各自映射成自家的协议结构。
 *
 * role 取值：
 * - system：系统提示（Anthropic 走独立 system 字段）；
 * - user：用户输入；
 * - assistant：模型输出，可带 toolCalls；
 * - tool：工具结果，必须带 toolCallId。
 */
final class AiChatMessage
{
    /**
     * @param  array<int, AiToolCall>  $toolCalls
     */
    public function __construct(
        public readonly string $role,
        public readonly string $content = '',
        public readonly array $toolCalls = [],
        public readonly ?string $toolCallId = null,
        public readonly string $toolName = '',
    ) {}

    public static function system(string $content): self
    {
        return new self('system', $content);
    }

    public static function user(string $content): self
    {
        return new self('user', $content);
    }

    /**
     * @param  array<int, AiToolCall>  $toolCalls
     */
    public static function assistant(string $content, array $toolCalls = []): self
    {
        return new self('assistant', $content, $toolCalls);
    }

    public static function toolResult(string $toolCallId, string $toolName, string $content): self
    {
        return new self('tool', $content, [], $toolCallId, $toolName);
    }
}
