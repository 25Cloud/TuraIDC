<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Services\Integrations\Data;

/**
 * 模型用量：按上游返回的 usage 字段换算，缺失时按 0 计。
 */
final class AiUsage
{
    public function __construct(
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
    ) {}

    public function total(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    public function plus(self $other): self
    {
        return new self(
            $this->promptTokens + $other->promptTokens,
            $this->completionTokens + $other->completionTokens,
        );
    }
}
