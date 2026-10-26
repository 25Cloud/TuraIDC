<?php

declare(strict_types=1);

namespace App\Services\Integrations\Ai\Drivers\Concerns;

use App\Exceptions\BusinessException;

/**
 * AI 网关 HTTP 能力：一次性与流式两种调用。
 *
 * 为什么不用 Http facade：它会把响应整体读完再返回，拿不到 SSE 增量；
 * 为什么不用 curl：生产 PHP 只保证 openssl/mbstring/fileinfo 等基础扩展，
 * 而既有上游 transport 已用 stream_context + fopen 完成长连接调用，这里沿用同一手法。
 */
trait TalksToGateway
{
    /**
     * 一次性 JSON 请求。
     *
     * @param  array<int, string>  $headers
     * @param  array<string, mixed>  $payload
     * @return array{status: int, body: string}
     */
    protected function sendJson(string $url, array $headers, array $payload, int $connectTimeout, int $timeout): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'timeout' => max(1, $timeout),
                'ignore_errors' => true,
                'protocol_version' => 1.1,
                'follow_location' => 0,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        if (! is_string($body)) {
            throw new BusinessException('AI 网关连接失败，请检查网关地址与网络', 50000, 500);
        }
        if ($status >= 400 || $status === 0) {
            throw new BusinessException($this->describeHttpFailure($status, $body), 50000, 500);
        }

        return ['status' => $status, 'body' => $body];
    }

    /**
     * 流式请求：每解析出一条 SSE 事件回调一次。
     *
     * @param  array<int, string>  $headers
     * @param  array<string, mixed>  $payload
     */
    protected function streamSse(string $url, array $headers, array $payload, int $timeout, callable $onEvent): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'timeout' => max(1, $timeout),
                'ignore_errors' => true,
                'protocol_version' => 1.1,
                'follow_location' => 0,
            ],
        ]);

        $handle = @fopen($url, 'rb', false, $context);
        if ($handle === false) {
            throw new BusinessException('AI 网关连接失败，请检查网关地址与网络', 50000, 500);
        }

        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', (string) $line, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        try {
            if ($status >= 400 || $status === 0) {
                $errorBody = '';
                while (! feof($handle)) {
                    $chunk = fread($handle, 8192);
                    if ($chunk === false) {
                        break;
                    }
                    $errorBody .= $chunk;
                    if (strlen($errorBody) > 8192) {
                        break;
                    }
                }
                throw new BusinessException($this->describeHttpFailure($status, $errorBody), 50000, 500);
            }

            $deadline = time() + max(1, $timeout);
            $eventName = 'message';
            $dataLines = [];

            while (! feof($handle)) {
                if (time() > $deadline) {
                    throw new BusinessException('AI 网关响应超时，请稍后重试', 50000, 500);
                }
                $line = fgets($handle);
                if ($line === false) {
                    break;
                }
                $line = rtrim((string) $line, "\r\n");

                if ($line === '') {
                    if ($dataLines !== []) {
                        $onEvent($eventName, implode("\n", $dataLines));
                    }
                    $eventName = 'message';
                    $dataLines = [];
                    continue;
                }
                if (str_starts_with($line, ':')) {
                    continue;
                }
                $colon = strpos($line, ':');
                $field = $colon === false ? $line : substr($line, 0, $colon);
                $raw = $colon === false ? '' : substr($line, $colon + 1);
                $value = str_starts_with($raw, ' ') ? substr($raw, 1) : $raw;

                if ($field === 'event') {
                    $eventName = $value;
                } elseif ($field === 'data') {
                    $dataLines[] = $value;
                }
            }

            if ($dataLines !== []) {
                $onEvent($eventName, implode("\n", $dataLines));
            }
        } finally {
            fclose($handle);
        }
    }

    /** 上游错误转中文，绝不把供应商原文抛给用户 */
    protected function describeHttpFailure(int $status, string $body): string
    {
        $detail = '';
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $detail = (string) (
                $decoded['error']['message']
                ?? $decoded['message']
                ?? $decoded['error']
                ?? ''
            );
        }
        $detail = trim(preg_replace('/\s+/', ' ', $detail) ?? '');
        if (mb_strlen($detail) > 160) {
            $detail = mb_substr($detail, 0, 160) . '…';
        }

        if ($status === 401 || $status === 403) {
            return 'AI 网关鉴权失败，请检查 API 密钥与权限';
        }
        if ($status === 404) {
            return 'AI 网关接口不存在，请检查网关地址与协议类型';
        }
        if ($status === 429) {
            return 'AI 网关限流，请稍后重试';
        }
        if ($status >= 500) {
            return 'AI 网关暂时不可用，请稍后重试';
        }

        return $detail !== '' ? 'AI 网关返回错误：' . $detail : 'AI 网关返回异常（HTTP ' . $status . '）';
    }

    /**
     * 拼接端点：允许 base_url 自带 /v1（OpenAI 风格）或 /compatible-mode/v1（通义兼容模式）。
     */
    protected function endpoint(string $baseUrl, string $path): string
    {
        $base = rtrim(trim($baseUrl), '/');
        if (str_ends_with($base, '/v1')) {
            $base = substr($base, 0, -3);
        }

        return $base . $path;
    }

    /**
     * @return array<int, string>
     */
    protected function jsonHeaders(?string $apiKey, array $extra = []): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if ($apiKey !== null && $apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        return array_merge($headers, $extra);
    }
}
