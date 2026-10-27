<?php

declare(strict_types=1);

namespace Tests\Support;

// 插件类不走 composer autoload：测试文件在 app 启动前就 require 本文件，
// 这里显式把要 implements 的接口与父类声明先拉进来，避免声明期 fatal。
require_once __DIR__.'/../../src/Contracts/AiProviderInterface.php';
require_once __DIR__.'/../../src/Services/Integrations/Data/AiChatRequest.php';
require_once __DIR__.'/../../src/Services/Integrations/Data/AiChatResult.php';
require_once __DIR__.'/../../src/Services/Integrations/Data/AiToolCall.php';
require_once __DIR__.'/../../src/Services/Integrations/Data/AiUsage.php';

use TuraIDC\Plugins\Addons\AiAssistant\Contracts\AiProviderInterface;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Integrations\Data\AiChatRequest;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Integrations\Data\AiChatResult;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Integrations\Data\AiToolCall;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Integrations\Data\AiUsage;

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
