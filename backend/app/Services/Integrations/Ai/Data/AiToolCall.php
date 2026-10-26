<?php

declare(strict_types=1);

namespace App\Services\Integrations\Ai\Data;

/**
 * 工具调用：模型要求执行的函数。
 *
 * argumentsJson 保留上游原始字符串，便于按原样回灌给下一次请求；
 * arguments() 只在本地执行工具时解码。
 */
final class AiToolCall
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $argumentsJson = '{}',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function arguments(): array
    {
        $decoded = json_decode($this->argumentsJson !== '' ? $this->argumentsJson : '{}', true);

        return is_array($decoded) ? $decoded : [];
    }

    public function isValid(): bool
    {
        return $this->id !== '' && $this->name !== '';
    }
}
