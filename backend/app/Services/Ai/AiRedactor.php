<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * 出站脱敏：把手机号、身份证、邮箱在送进模型前掩码。
 *
 * 默认开启（settings ai.redact_pii=1）。工具返回的最小字段本身已不含完整证件，
 * 这里再兜一层，防止用户自己在对话里贴出敏感信息后被完整送进第三方。
 */
final class AiRedactor
{
    public function __construct(private readonly AiConfig $config) {}

    public function redact(string $text): string
    {
        if ($text === '' || ! $this->config->redactPii()) {
            return $text;
        }

        // 手机号：1 开头 11 位，保留前 3 后 2
        $text = (string) preg_replace_callback('/(?<!\d)(1[3-9]\d{9})(?!\d)/u', static function (array $matches): string {
            $phone = $matches[1];

            return substr($phone, 0, 3) . '******' . substr($phone, -2);
        }, $text);

        // 身份证：18 位（含 X 结尾），保留前 4 后 2
        $text = (string) preg_replace_callback('/(?<![\dA-Za-z])(\d{17}[\dXx])(?![\dA-Za-z])/u', static function (array $matches): string {
            $id = $matches[1];

            return substr($id, 0, 4) . str_repeat('*', 12) . substr($id, -2);
        }, $text);

        // 邮箱：保留首字符与域名
        $text = (string) preg_replace_callback('/([A-Za-z0-9._%+-])[A-Za-z0-9._%+-]*@([A-Za-z0-9.-]+\.[A-Za-z]{2,})/u', static function (array $matches): string {
            return $matches[1] . '***@' . $matches[2];
        }, $text);

        return $text;
    }

    /**
     * 递归脱敏数组里的字符串值（工具结果回灌前用）。
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function redactArray(array $payload): array
    {
        if (! $this->config->redactPii()) {
            return $payload;
        }

        foreach ($payload as $key => $value) {
            if (is_string($value)) {
                $payload[$key] = $this->redact($value);
                continue;
            }
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $payload[$key] = $this->redactArray($value);
            }
        }

        return $payload;
    }
}
