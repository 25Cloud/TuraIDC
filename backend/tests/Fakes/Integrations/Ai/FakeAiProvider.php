<?php

declare(strict_types=1);

namespace Tests\Fakes\Integrations\Ai;

use App\Contracts\Integrations\Ai\AiProviderInterface;
use App\Services\Integrations\Ai\Data\AiChatRequest;
use App\Services\Integrations\Ai\Data\AiChatResult;
use App\Services\Integrations\Ai\Data\AiToolCall;
use App\Services\Integrations\Ai\Data\AiUsage;

/**
 * 测试替身：按预置队列依次返回，不产生任何网络请求。
 *
 * 用法：
 *   $fake = new FakeAiProvider;
 *   $fake->pushText('您好');
 *   $fake->pushToolCall('search_products', ['keyword' => '香港']);
 *   app(AiProviderManager::class)->use($fake);
 */
final class FakeAiProvider implements AiProviderInterface
{
    /** @var array<int, AiChatResult> */
    private array $queue = [];

    /** @var array<int, AiChatRequest> */
    public array $requests = [];

    public function __construct(
        private readonly string $driverKey = 'chat_completions',
    ) {}

    public function key(): string
    {
        return $this->driverKey;
    }

    public function label(): string
    {
        return '测试驱动';
    }

    public function push(AiChatResult $result): self
    {
        $this->queue[] = $result;

        return $this;
    }

    public function pushText(string $content, int $promptTokens = 10, int $completionTokens = 20, string $reasoning = ''): self
    {
        return $this->push(new AiChatResult($content, [], new AiUsage($promptTokens, $completionTokens), 'stop', $reasoning));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function pushToolCall(string $name, array $arguments = [], ?string $id = null, int $promptTokens = 10, int $completionTokens = 20): self
    {
        return $this->push(new AiChatResult(
            '',
            [new AiToolCall($id ?? ('call_' . $name), $name, (string) json_encode($arguments, JSON_UNESCAPED_UNICODE))],
            new AiUsage($promptTokens, $completionTokens),
            'tool_calls',
        ));
    }

    public function chat(AiChatRequest $request, ?callable $onText = null, ?callable $onReasoning = null): AiChatResult
    {
        $this->requests[] = $request;
        $result = array_shift($this->queue) ?? new AiChatResult('（测试驱动未预置回复）', [], new AiUsage, 'stop');

        if ($onReasoning !== null && $result->reasoning !== '') {
            $onReasoning($result->reasoning);
        }
        if ($onText !== null && $result->content !== '') {
            $onText($result->content);
        }

        return $result;
    }
}
