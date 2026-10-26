<?php

declare(strict_types=1);

namespace App\Services\Integrations\Ai\Drivers;

use App\Contracts\Integrations\Ai\AiProviderInterface;
use App\Exceptions\BusinessException;
use App\Services\Integrations\Ai\Data\AiChatMessage;
use App\Services\Integrations\Ai\Data\AiChatRequest;
use App\Services\Integrations\Ai\Data\AiChatResult;
use App\Services\Integrations\Ai\Data\AiToolCall;
use App\Services\Integrations\Ai\Data\AiUsage;
use App\Services\Integrations\Ai\Drivers\Concerns\TalksToGateway;

/**
 * Anthropic Messages 协议：POST /v1/messages。
 *
 * 与 OpenAI 的差别：system 是顶层字段、工具结果必须放在 user 消息的 tool_result 块里、
 * 相邻同角色消息需要合并（Anthropic 要求 user/assistant 交替）。
 */
final class AnthropicMessagesDriver implements AiProviderInterface
{
    use TalksToGateway;

    private const PATH = '/v1/messages';

    private const API_VERSION = '2023-06-01';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $connectTimeout = 15,
    ) {}

    public function key(): string
    {
        return 'anthropic_messages';
    }

    public function label(): string
    {
        return 'Anthropic Messages（/v1/messages）';
    }

    public function chat(AiChatRequest $request, ?callable $onText = null, ?callable $onReasoning = null): AiChatResult
    {
        $url = $this->endpoint($this->baseUrl, self::PATH);
        $payload = $this->buildPayload($request, $onText !== null);
        $headers = $this->anthropicHeaders($onText !== null);

        if ($onText === null) {
            $response = $this->sendJson($url, $headers, $payload, $this->connectTimeout, $request->timeoutSeconds);
            $decoded = json_decode($response['body'], true);
            if (! is_array($decoded)) {
                throw new BusinessException('AI 网关返回了无法解析的内容', 50000, 500);
            }

            return $this->parseMessage($decoded);
        }

        $content = '';
        $reasoning = '';
        $usage = new AiUsage;
        /** @var array<int, array{id: string, name: string, arguments: string}> $blocks */
        $blocks = [];

        $this->streamSse(
            $url,
            $headers,
            $payload,
            $request->timeoutSeconds,
            function (string $event, string $data) use (&$content, &$reasoning, &$usage, &$blocks, $onText, $onReasoning): void {
                $chunk = json_decode($data, true);
                if (! is_array($chunk)) {
                    return;
                }

                if ($event === 'message_start') {
                    $messageUsage = $chunk['message']['usage'] ?? [];
                    if (is_array($messageUsage)) {
                        $usage = new AiUsage((int) ($messageUsage['input_tokens'] ?? 0), $usage->completionTokens);
                    }

                    return;
                }

                if ($event === 'content_block_start') {
                    $index = (int) ($chunk['index'] ?? 0);
                    $block = $chunk['content_block'] ?? [];
                    if (is_array($block) && ($block['type'] ?? '') === 'tool_use') {
                        $blocks[$index] = [
                            'id' => (string) ($block['id'] ?? ''),
                            'name' => (string) ($block['name'] ?? ''),
                            'arguments' => '',
                        ];
                    }

                    return;
                }

                if ($event === 'content_block_delta') {
                    $index = (int) ($chunk['index'] ?? 0);
                    $delta = $chunk['delta'] ?? [];
                    if (! is_array($delta)) {
                        return;
                    }
                    if (($delta['type'] ?? '') === 'text_delta') {
                        $text = is_string($delta['text'] ?? null) ? $delta['text'] : '';
                        if ($text !== '') {
                            $content .= $text;
                            $onText($text);
                        }
                    }
                    if (($delta['type'] ?? '') === 'thinking_delta') {
                        $thinking = is_string($delta['thinking'] ?? null) ? $delta['thinking'] : '';
                        if ($thinking !== '') {
                            $reasoning .= $thinking;
                            if ($onReasoning !== null) {
                                $onReasoning($thinking);
                            }
                        }
                    }
                    if (($delta['type'] ?? '') === 'input_json_delta' && isset($blocks[$index])) {
                        $blocks[$index]['arguments'] .= is_string($delta['partial_json'] ?? null) ? $delta['partial_json'] : '';
                    }

                    return;
                }

                if ($event === 'message_delta') {
                    $deltaUsage = $chunk['usage'] ?? [];
                    if (is_array($deltaUsage)) {
                        $usage = new AiUsage($usage->promptTokens, (int) ($deltaUsage['output_tokens'] ?? $usage->completionTokens));
                    }
                }
            },
        );

        $calls = [];
        foreach ($blocks as $index => $block) {
            if ($block['name'] === '') {
                continue;
            }
            $calls[] = new AiToolCall(
                $block['id'] !== '' ? $block['id'] : 'toolu_' . $index,
                $block['name'],
                $block['arguments'] !== '' ? $block['arguments'] : '{}',
            );
        }

        return new AiChatResult($content, $calls, $usage, '', $reasoning);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(AiChatRequest $request, bool $stream): array
    {
        $system = [];
        $messages = [];

        foreach ($request->messages as $message) {
            if ($message->role === 'system') {
                if ($message->content !== '') {
                    $system[] = $message->content;
                }
                continue;
            }

            [$role, $blocks] = $this->mapMessage($message);
            $lastIndex = array_key_last($messages);
            if ($lastIndex !== null && $messages[$lastIndex]['role'] === $role) {
                $messages[$lastIndex]['content'] = array_merge($messages[$lastIndex]['content'], $blocks);
                continue;
            }
            $messages[] = ['role' => $role, 'content' => $blocks];
        }

        $payload = [
            'model' => $request->model,
            'messages' => $messages,
            'max_tokens' => $request->maxTokens,
            'temperature' => $request->temperature,
            'stream' => $stream,
        ];

        if ($system !== []) {
            $payload['system'] = implode("\n\n", $system);
        }

        if ($request->hasTools()) {
            $payload['tools'] = array_map(
                static fn (array $tool): array => [
                    'name' => $tool['name'],
                    'description' => $tool['description'],
                    'input_schema' => $tool['parameters'],
                ],
                $request->tools,
            );
        }

        return $payload;
    }

    /**
     * @return array{0: string, 1: array<int, array<string, mixed>>}
     */
    private function mapMessage(AiChatMessage $message): array
    {
        if ($message->role === 'tool') {
            return ['user', [[
                'type' => 'tool_result',
                'tool_use_id' => (string) $message->toolCallId,
                'content' => $message->content,
            ]]];
        }

        if ($message->role === 'assistant') {
            $blocks = [];
            if ($message->content !== '') {
                $blocks[] = ['type' => 'text', 'text' => $message->content];
            }
            foreach ($message->toolCalls as $call) {
                $blocks[] = [
                    'type' => 'tool_use',
                    'id' => $call->id,
                    'name' => $call->name,
                    'input' => $call->arguments(),
                ];
            }

            return ['assistant', $blocks !== [] ? $blocks : [['type' => 'text', 'text' => '']]];
        }

        return ['user', [['type' => 'text', 'text' => $message->content]]];
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function parseMessage(array $decoded): AiChatResult
    {
        $text = '';
        $reasoning = '';
        $calls = [];
        foreach ((array) ($decoded['content'] ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            if ($type === 'thinking') {
                $reasoning .= (string) ($block['thinking'] ?? '');
                continue;
            }
            if ($type === 'text') {
                $text .= (string) ($block['text'] ?? '');
                continue;
            }
            if ($type === 'tool_use') {
                $input = $block['input'] ?? [];
                $calls[] = new AiToolCall(
                    (string) ($block['id'] ?? ''),
                    (string) ($block['name'] ?? ''),
                    is_array($input) ? (string) json_encode($input, JSON_UNESCAPED_UNICODE) : '{}',
                );
            }
        }

        $usage = $decoded['usage'] ?? [];

        return new AiChatResult(
            $text,
            $calls,
            new AiUsage((int) ($usage['input_tokens'] ?? 0), (int) ($usage['output_tokens'] ?? 0)),
            (string) ($decoded['stop_reason'] ?? ''),
            $reasoning,
        );
    }

    /**
     * @return array<int, string>
     */
    private function anthropicHeaders(bool $stream): array
    {
        $headers = [
            'Content-Type: application/json',
            'anthropic-version: ' . self::API_VERSION,
            'Accept: ' . ($stream ? 'text/event-stream' : 'application/json'),
        ];
        if ($this->apiKey !== '') {
            $headers[] = 'x-api-key: ' . $this->apiKey;
        }

        return $headers;
    }
}
