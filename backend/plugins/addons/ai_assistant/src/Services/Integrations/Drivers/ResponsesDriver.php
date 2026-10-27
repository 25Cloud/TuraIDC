<?php

declare(strict_types=1);

namespace TuraIDC\Plugins\Addons\AiAssistant\Services\Integrations\Drivers;

use TuraIDC\Plugins\Addons\AiAssistant\Contracts\AiProviderInterface;
use App\Exceptions\BusinessException;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Integrations\Data\AiChatMessage;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Integrations\Data\AiChatRequest;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Integrations\Data\AiChatResult;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Integrations\Data\AiToolCall;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Integrations\Data\AiUsage;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Integrations\Drivers\Concerns\TalksToGateway;

/**
 * OpenAI Responses 协议：POST /v1/responses。
 *
 * 与对话补全的差别：输入是 items 列表，工具结果走 function_call_output，
 * 流式事件名是 response.* 前缀；工具调用参数在 output_item 上而不是 delta.tool_calls。
 */
final class ResponsesDriver implements AiProviderInterface
{
    use TalksToGateway;

    private const PATH = '/v1/responses';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $connectTimeout = 15,
    ) {}

    public function key(): string
    {
        return 'responses';
    }

    public function label(): string
    {
        return 'OpenAI Responses（/v1/responses）';
    }

    public function chat(AiChatRequest $request, ?callable $onText = null, ?callable $onReasoning = null): AiChatResult
    {
        $url = $this->endpoint($this->baseUrl, self::PATH);
        $payload = $this->buildPayload($request, $onText !== null);
        $headers = $this->jsonHeaders($this->apiKey);

        if ($onText === null) {
            $response = $this->sendJson($url, $headers, $payload, $this->connectTimeout, $request->timeoutSeconds);
            $decoded = json_decode($response['body'], true);
            if (! is_array($decoded)) {
                throw new BusinessException('AI 网关返回了无法解析的内容', 50000, 500);
            }

            return $this->parseResponse($decoded);
        }

        $content = '';
        $reasoning = '';
        $usage = new AiUsage;
        /** @var array<string, array{id: string, name: string, arguments: string}> $byItem */
        $byItem = [];
        /** @var array<int, string> $order */
        $order = [];

        $this->streamSse(
            $url,
            array_merge($headers, ['Accept: text/event-stream']),
            $payload,
            $request->timeoutSeconds,
            function (string $event, string $data) use (&$content, &$reasoning, &$usage, &$byItem, &$order, $onText, $onReasoning): void {
                $chunk = json_decode($data, true);
                if (! is_array($chunk)) {
                    return;
                }

                if ($event === 'response.reasoning_summary_text.delta' || $event === 'response.reasoning_text.delta') {
                    $delta = is_string($chunk['delta'] ?? null) ? $chunk['delta'] : '';
                    if ($delta !== '') {
                        $reasoning .= $delta;
                        if ($onReasoning !== null) {
                            $onReasoning($delta);
                        }
                    }

                    return;
                }

                if ($event === 'response.output_text.delta') {
                    $delta = is_string($chunk['delta'] ?? null) ? $chunk['delta'] : '';
                    if ($delta !== '') {
                        $content .= $delta;
                        $onText($delta);
                    }

                    return;
                }

                if ($event === 'response.output_item.added') {
                    $item = $chunk['item'] ?? [];
                    if (is_array($item) && ($item['type'] ?? '') === 'function_call') {
                        $itemId = (string) ($item['id'] ?? ('item-' . count($order)));
                        $byItem[$itemId] = [
                            'id' => (string) ($item['call_id'] ?? $itemId),
                            'name' => (string) ($item['name'] ?? ''),
                            'arguments' => is_string($item['arguments'] ?? null) ? $item['arguments'] : '',
                        ];
                        $order[] = $itemId;
                    }

                    return;
                }

                if ($event === 'response.function_call_arguments.delta') {
                    $itemId = (string) ($chunk['item_id'] ?? '');
                    $delta = is_string($chunk['delta'] ?? null) ? $chunk['delta'] : '';
                    if ($itemId !== '' && isset($byItem[$itemId])) {
                        $byItem[$itemId]['arguments'] .= $delta;
                    }

                    return;
                }

                if ($event === 'response.output_item.done') {
                    $item = $chunk['item'] ?? [];
                    if (is_array($item) && ($item['type'] ?? '') === 'function_call') {
                        $itemId = (string) ($item['id'] ?? '');
                        if ($itemId !== '' && isset($byItem[$itemId])) {
                            if (is_string($item['arguments'] ?? null) && $item['arguments'] !== '') {
                                $byItem[$itemId]['arguments'] = $item['arguments'];
                            }
                            if (! empty($item['call_id'])) {
                                $byItem[$itemId]['id'] = (string) $item['call_id'];
                            }
                        }
                    }

                    return;
                }

                if ($event === 'response.completed') {
                    $responseUsage = $chunk['response']['usage'] ?? [];
                    if (is_array($responseUsage)) {
                        $usage = new AiUsage(
                            (int) ($responseUsage['input_tokens'] ?? 0),
                            (int) ($responseUsage['output_tokens'] ?? 0),
                        );
                    }
                }
            },
        );

        $calls = [];
        foreach ($order as $itemId) {
            $entry = $byItem[$itemId];
            if ($entry['name'] === '') {
                continue;
            }
            $calls[] = new AiToolCall($entry['id'], $entry['name'], $entry['arguments'] !== '' ? $entry['arguments'] : '{}');
        }

        return new AiChatResult($content, $calls, $usage, '', $reasoning);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(AiChatRequest $request, bool $stream): array
    {
        $input = [];
        foreach ($request->messages as $message) {
            foreach ($this->mapMessage($message) as $item) {
                $input[] = $item;
            }
        }

        $payload = [
            'model' => $request->model,
            'input' => $input,
            'temperature' => $request->temperature,
            'max_output_tokens' => $request->maxTokens,
            'stream' => $stream,
        ];

        if ($request->hasTools()) {
            $payload['tools'] = array_map(
                static fn (array $tool): array => [
                    'type' => 'function',
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'parameters' => $tool['parameters'],
                ],
                $request->tools,
            );
            $payload['tool_choice'] = 'auto';
        }

        return $payload;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapMessage(AiChatMessage $message): array
    {
        if ($message->role === 'tool') {
            return [[
                'type' => 'function_call_output',
                'call_id' => (string) $message->toolCallId,
                'output' => $message->content,
            ]];
        }

        if ($message->role === 'assistant') {
            $items = [];
            if ($message->content !== '') {
                $items[] = ['role' => 'assistant', 'content' => $message->content];
            }
            foreach ($message->toolCalls as $call) {
                $items[] = [
                    'type' => 'function_call',
                    'call_id' => $call->id,
                    'name' => $call->name,
                    'arguments' => $call->argumentsJson !== '' ? $call->argumentsJson : '{}',
                ];
            }

            return $items !== [] ? $items : [['role' => 'assistant', 'content' => '']];
        }

        return [['role' => $message->role, 'content' => $message->content]];
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function parseResponse(array $decoded): AiChatResult
    {
        $text = '';
        $reasoning = '';
        $calls = [];
        foreach ((array) ($decoded['output'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $type = (string) ($item['type'] ?? '');
            if ($type === 'reasoning') {
                foreach ((array) ($item['summary'] ?? []) as $summary) {
                    if (is_array($summary)) {
                        $reasoning .= (string) ($summary['text'] ?? '');
                    }
                }
                continue;
            }
            if ($type === 'message') {
                foreach ((array) ($item['content'] ?? []) as $block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'output_text') {
                        $text .= (string) ($block['text'] ?? '');
                    }
                }
                continue;
            }
            if ($type === 'function_call') {
                $calls[] = new AiToolCall(
                    (string) ($item['call_id'] ?? $item['id'] ?? ''),
                    (string) ($item['name'] ?? ''),
                    is_string($item['arguments'] ?? null) ? $item['arguments'] : '{}',
                );
            }
        }

        $usage = $decoded['usage'] ?? [];

        return new AiChatResult(
            $text,
            $calls,
            new AiUsage((int) ($usage['input_tokens'] ?? 0), (int) ($usage['output_tokens'] ?? 0)),
            (string) ($decoded['status'] ?? ''),
            $reasoning,
        );
    }
}
