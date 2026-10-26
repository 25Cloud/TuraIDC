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
 * OpenAI 兼容协议：POST /v1/chat/completions。
 *
 * 覆盖 DeepSeek、通义兼容模式、Moonshot、vLLM、Ollama 等绝大多数站点会用到的网关。
 */
final class ChatCompletionsDriver implements AiProviderInterface
{
    use TalksToGateway;

    private const PATH = '/v1/chat/completions';

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $connectTimeout = 15,
    ) {}

    public function key(): string
    {
        return 'chat_completions';
    }

    public function label(): string
    {
        return 'OpenAI 对话补全（/v1/chat/completions）';
    }

    public function chat(AiChatRequest $request, ?callable $onText = null): AiChatResult
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

            return $this->parseCompletion($decoded);
        }

        $content = '';
        $finishReason = '';
        $usage = new AiUsage;
        /** @var array<int, array{id: string, name: string, arguments: string}> $toolCalls */
        $toolCalls = [];

        $this->streamSse(
            $url,
            array_merge($headers, ['Accept: text/event-stream']),
            $payload,
            $request->timeoutSeconds,
            function (string $event, string $data) use (&$content, &$toolCalls, &$usage, &$finishReason, $onText): void {
                if ($data === '[DONE]') {
                    return;
                }
                $chunk = json_decode($data, true);
                if (! is_array($chunk)) {
                    return;
                }
                if (isset($chunk['usage']) && is_array($chunk['usage'])) {
                    $usage = new AiUsage(
                        (int) ($chunk['usage']['prompt_tokens'] ?? 0),
                        (int) ($chunk['usage']['completion_tokens'] ?? 0),
                    );
                }
                $choice = $chunk['choices'][0] ?? null;
                if (! is_array($choice)) {
                    return;
                }
                if (isset($choice['finish_reason']) && is_string($choice['finish_reason'])) {
                    $finishReason = $choice['finish_reason'];
                }
                $delta = $choice['delta'] ?? [];
                if (! is_array($delta)) {
                    return;
                }
                if (isset($delta['content']) && is_string($delta['content']) && $delta['content'] !== '') {
                    $content .= $delta['content'];
                    $onText($delta['content']);
                }
                foreach ((array) ($delta['tool_calls'] ?? []) as $position => $call) {
                    if (! is_array($call)) {
                        continue;
                    }
                    $index = (int) ($call['index'] ?? $position);
                    $current = $toolCalls[$index] ?? ['id' => '', 'name' => '', 'arguments' => ''];
                    if (isset($call['id']) && is_string($call['id']) && $call['id'] !== '') {
                        $current['id'] = $call['id'];
                    }
                    $function = $call['function'] ?? [];
                    if (is_array($function)) {
                        if (isset($function['name']) && is_string($function['name']) && $function['name'] !== '') {
                            $current['name'] = $function['name'];
                        }
                        if (isset($function['arguments']) && is_string($function['arguments'])) {
                            $current['arguments'] .= $function['arguments'];
                        }
                    }
                    $toolCalls[$index] = $current;
                }
            },
        );

        return new AiChatResult(
            $content,
            $this->normalizeToolCalls($toolCalls),
            $usage,
            $finishReason,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(AiChatRequest $request, bool $stream): array
    {
        $messages = [];
        foreach ($request->messages as $message) {
            $messages[] = $this->mapMessage($message);
        }

        $payload = [
            'model' => $request->model,
            'messages' => $messages,
            'temperature' => $request->temperature,
            'max_tokens' => $request->maxTokens,
            'stream' => $stream,
        ];

        if ($stream) {
            // 流式下必须显式要 usage，否则无法记账
            $payload['stream_options'] = ['include_usage' => true];
        }

        if ($request->hasTools()) {
            $payload['tools'] = array_map(
                static fn (array $tool): array => [
                    'type' => 'function',
                    'function' => [
                        'name' => $tool['name'],
                        'description' => $tool['description'],
                        'parameters' => $tool['parameters'],
                    ],
                ],
                $request->tools,
            );
            $payload['tool_choice'] = 'auto';
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapMessage(AiChatMessage $message): array
    {
        if ($message->role === 'tool') {
            return [
                'role' => 'tool',
                'tool_call_id' => (string) $message->toolCallId,
                'content' => $message->content,
            ];
        }

        if ($message->role === 'assistant') {
            $row = ['role' => 'assistant', 'content' => $message->content];
            if ($message->toolCalls !== []) {
                $row['tool_calls'] = array_map(
                    static fn (AiToolCall $call): array => [
                        'id' => $call->id,
                        'type' => 'function',
                        'function' => [
                            'name' => $call->name,
                            'arguments' => $call->argumentsJson !== '' ? $call->argumentsJson : '{}',
                        ],
                    ],
                    $message->toolCalls,
                );
            }

            return $row;
        }

        return ['role' => $message->role, 'content' => $message->content];
    }

    /**
     * @param  array<int, array{id: string, name: string, arguments: string}>  $accumulated
     * @return array<int, AiToolCall>
     */
    private function normalizeToolCalls(array $accumulated): array
    {
        $calls = [];
        foreach ($accumulated as $index => $item) {
            if ($item['name'] === '') {
                continue;
            }
            $calls[] = new AiToolCall(
                $item['id'] !== '' ? $item['id'] : 'call_' . $index,
                $item['name'],
                $item['arguments'] !== '' ? $item['arguments'] : '{}',
            );
        }

        return $calls;
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function parseCompletion(array $decoded): AiChatResult
    {
        $choice = $decoded['choices'][0] ?? [];
        $message = is_array($choice) ? ($choice['message'] ?? []) : [];
        $content = is_array($message) && isset($message['content']) && is_string($message['content'])
            ? $message['content']
            : '';

        $calls = [];
        foreach ((array) ($message['tool_calls'] ?? []) as $index => $call) {
            if (! is_array($call)) {
                continue;
            }
            $function = $call['function'] ?? [];
            if (! is_array($function) || empty($function['name'])) {
                continue;
            }
            $calls[] = new AiToolCall(
                (string) ($call['id'] ?? ('call_' . $index)),
                (string) $function['name'],
                is_string($function['arguments'] ?? null) ? $function['arguments'] : '{}',
            );
        }

        $usage = $decoded['usage'] ?? [];

        return new AiChatResult(
            $content,
            $calls,
            new AiUsage((int) ($usage['prompt_tokens'] ?? 0), (int) ($usage['completion_tokens'] ?? 0)),
            (string) (((array) $choice)['finish_reason'] ?? ''),
        );
    }
}
