<?php

declare(strict_types=1);

namespace App\Contracts\Integrations\Ai;

use App\Services\Integrations\Ai\Data\AiChatRequest;
use App\Services\Integrations\Ai\Data\AiChatResult;

/**
 * 模型供应商能力域接口。
 *
 * 三种上游协议各一个驱动实现，业务层只依赖本接口，不依赖任何供应商字段：
 * - chat_completions：OpenAI 兼容 /v1/chat/completions（DeepSeek、通义、Moonshot、vLLM、Ollama 等）；
 * - responses：OpenAI /v1/responses；
 * - anthropic_messages：Anthropic /v1/messages。
 *
 * 传入 $onText 时驱动按流式解析并把文本增量回调出去；为 null 时一次性返回。
 * 传入 $onReasoning 时，推理模型的思考过程（reasoning_content / thinking 块）也会增量回调，
 * 只做展示用，不参与工具决策。
 * 无论流式与否，返回的 AiChatResult 都必须是完整结果（含思考过程、工具调用与用量）。
 */
interface AiProviderInterface
{
    public function key(): string;

    public function label(): string;

    public function chat(AiChatRequest $request, ?callable $onText = null, ?callable $onReasoning = null): AiChatResult;
}
