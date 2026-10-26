<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\Setting;

/**
 * AI 设置读取入口（settings 表 group=ai）。
 *
 * 与 OpenApiConfig 同构：静态默认值在 config/ai_gateway.php，
 * 站长可改的值一律走 settings，缺省回落到配置默认值。
 */
final class AiConfig
{
    public const GROUP = 'ai';

    public function enabled(): bool
    {
        return (int) Setting::getValue(self::GROUP, 'enabled', 0) === 1;
    }

    public function protocol(): string
    {
        $value = (string) Setting::getValue(
            self::GROUP,
            'protocol',
            (string) config('ai_gateway.default_protocol', 'chat_completions'),
        );

        return array_key_exists($value, $this->protocols()) ? $value : 'chat_completions';
    }

    /**
     * @return array<string, array{label: string, path: string}>
     */
    public function protocols(): array
    {
        return (array) config('ai_gateway.protocols', []);
    }

    public function protocolLabel(): string
    {
        $protocols = $this->protocols();

        return (string) ($protocols[$this->protocol()]['label'] ?? $this->protocol());
    }

    public function baseUrl(): string
    {
        return trim((string) Setting::getValue(self::GROUP, 'base_url', ''));
    }

    public function apiKey(): string
    {
        // 存储键用 provider_api_key，避免与供应商等既有 api_key 设置项共用加密白名单
        $value = Setting::getValue(self::GROUP, 'provider_api_key', null);
        if ($value === null || $value === '') {
            $value = Setting::getValue(self::GROUP, 'api_key', '');
        }

        return trim((string) $value);
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey() !== '';
    }

    public function model(): string
    {
        return trim((string) Setting::getValue(self::GROUP, 'model', ''));
    }

    public function temperature(): float
    {
        $value = (float) Setting::getValue(self::GROUP, 'temperature', 0.3);

        return max(0.0, min(2.0, $value));
    }

    public function maxTokens(): int
    {
        return max(128, min(8192, (int) Setting::getValue(self::GROUP, 'max_tokens', 1200)));
    }

    public function channelEnabled(string $channel): bool
    {
        $key = match ($channel) {
            'site' => 'site_enabled',
            'admin' => 'admin_enabled',
            default => 'console_enabled',
        };

        return (int) Setting::getValue(self::GROUP, $key, 1) === 1;
    }

    public function allowBalancePay(): bool
    {
        return (int) Setting::getValue(self::GROUP, 'allow_balance_pay', 0) === 1;
    }

    public function userDailyMessages(): int
    {
        return max(0, (int) Setting::getValue(self::GROUP, 'user_daily_messages', 60));
    }

    public function userDailyTokens(): int
    {
        return max(0, (int) Setting::getValue(self::GROUP, 'user_daily_tokens', 120000));
    }

    public function globalDailyTokens(): int
    {
        return max(0, (int) Setting::getValue(self::GROUP, 'global_daily_tokens', 3000000));
    }

    public function globalDailyCostCents(): int
    {
        return max(0, (int) Setting::getValue(self::GROUP, 'global_daily_cost_cents', 5000));
    }

    public function maxToolRounds(): int
    {
        $configured = (int) Setting::getValue(self::GROUP, 'max_tool_rounds', 0);
        if ($configured > 0) {
            return min(12, $configured);
        }

        return max(1, (int) config('ai_gateway.max_tool_rounds', 6));
    }

    public function requestTimeoutSeconds(): int
    {
        return max(15, min(180, (int) Setting::getValue(self::GROUP, 'request_timeout_seconds', 60)));
    }

    public function historyLimit(): int
    {
        $configured = (int) Setting::getValue(self::GROUP, 'history_message_limit', 0);
        if ($configured > 0) {
            return min(40, $configured);
        }

        return max(2, (int) config('ai_gateway.history_limit', 12));
    }

    public function redactPii(): bool
    {
        return (int) Setting::getValue(self::GROUP, 'redact_pii', 1) === 1;
    }

    /**
     * @return array<int, string>
     */
    public function enabledTools(): array
    {
        $raw = (string) Setting::getValue(self::GROUP, 'enabled_tools', '');
        if (trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? array_values(array_filter(array_map('strval', $decoded))) : [];
    }

    public function retentionDays(): int
    {
        return max(1, (int) Setting::getValue(self::GROUP, 'retention_days', 30));
    }

    public function defaultPermissionMode(): string
    {
        $value = (string) Setting::getValue(self::GROUP, 'default_permission_mode', 'confirm');

        return in_array($value, ['read_only', 'confirm', 'auto_approve'], true) ? $value : 'confirm';
    }

    /** 站点允许的最高档位：用户只能在不超过它的范围内切换 */
    public function maxPermissionMode(): string
    {
        $value = (string) Setting::getValue(self::GROUP, 'max_permission_mode', 'auto_approve');

        return in_array($value, ['read_only', 'confirm', 'auto_approve'], true) ? $value : 'auto_approve';
    }

    public function confirmTtlMinutes(): int
    {
        return max(1, min(30, (int) config('ai_gateway.confirm_ttl_minutes', 5)));
    }

    public function maxToolResultChars(): int
    {
        return max(512, (int) config('ai_gateway.max_tool_result_chars', 4096));
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl() !== '' && $this->model() !== '';
    }

    /** 成本估算：按每 1000 token 单价折算成分，仅用于额度熔断，站长可改 */
    public function costPerThousandTokensCents(): int
    {
        return max(0, (int) Setting::getValue(self::GROUP, 'cost_per_1k_tokens_cents', 1));
    }
}
