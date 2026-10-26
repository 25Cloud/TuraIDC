<?php

declare(strict_types=1);

namespace App\Services\Ai\Tools\Concerns;

/**
 * 工具返回值整理：给模型的永远是短摘要 + 必要字段，绝不外泄完整敏感信息。
 */
trait FormatsToolResults
{
    /** 金额字符串转分，避免浮点误差进入提示词 */
    protected function toCents(mixed $amount): ?int
    {
        if (! is_numeric($amount)) {
            return null;
        }

        return (int) round(((float) $amount) * 100);
    }

    /** 文本截断，防止单条工具结果把上下文烧穿 */
    protected function excerpt(?string $text, int $limit = 300): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $text));

        return mb_strlen($value) > $limit ? mb_substr($value, 0, $limit) . '…' : $value;
    }

    /**
     * 统一成 list：
     * - LengthAwarePaginator / array{list: array} / 纯数组 都能吃。
     *
     * @return array<int, mixed>
     */
    protected function toList(mixed $payload): array
    {
        if ($payload instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator) {
            return array_values($payload->items());
        }
        if ($payload instanceof \Illuminate\Support\Collection) {
            return $payload->values()->all();
        }
        if (is_array($payload)) {
            if (isset($payload['list']) && is_array($payload['list'])) {
                return array_values($payload['list']);
            }
            if (isset($payload['items']) && is_array($payload['items'])) {
                return array_values($payload['items']);
            }

            return array_is_list($payload) ? $payload : [$payload];
        }

        return [];
    }

    /** 从可能嵌套的结构里安全取标量 */
    protected function pick(mixed $payload, string $key, mixed $default = null): mixed
    {
        if (is_array($payload) && array_key_exists($key, $payload)) {
            return $payload[$key];
        }

        return $default;
    }
}
