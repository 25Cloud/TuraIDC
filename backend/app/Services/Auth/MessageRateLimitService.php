<?php

namespace App\Services\Auth;

use App\Models\IntegrationPlugin;
use App\Services\Integrations\Plugins\IntegrationDriverBindingResolver;
use App\Services\Integrations\Plugins\PluginConfigRepository;
use Illuminate\Support\Facades\RateLimiter;

class MessageRateLimitService
{
    private const DEFAULT_IP_MINUTE_LIMIT = 6;

    private const DEFAULT_TARGET_MINUTE_LIMIT = 1;

    private const DEFAULT_TARGET_HOUR_LIMIT = 5;

    private const DEFAULT_TARGET_DAY_LIMIT = 10;

    public function __construct(
        private ?IntegrationDriverBindingResolver $bindingResolver = null,
        private ?PluginConfigRepository $configRepository = null,
    ) {}

    public function check(string $channel, string $target, ?string $ip = null): array
    {
        $config = $this->getConfig($channel);

        if (! $config['enabled']) {
            return ['ok' => true];
        }

        $ip = $ip ? trim($ip) : null;
        if ($ip && $config['ip_minute_limit'] > 0) {
            $ipMinuteKey = $this->key($channel, 'ip-minute', $ip);
            if (RateLimiter::tooManyAttempts($ipMinuteKey, $config['ip_minute_limit'])) {
                return [
                    'ok' => false,
                    'message' => '当前 IP 每分钟发送次数已达上限，请稍后再试',
                ];
            }
        }

        // 目标号码/邮箱维度限流：防止轮换 IP 对同一目标轰炸
        $target = trim($target);
        if ($target !== '' && $config['target_minute_limit'] > 0) {
            // 与阿里云 dypnsapi SendSmsVerifyCode 每分钟 1 条保持一致，提前拦截避免触发服务商 biz.FREQUENCY
            if (RateLimiter::tooManyAttempts(
                $this->key($channel, 'target-minute', $target),
                $config['target_minute_limit']
            )) {
                return [
                    'ok' => false,
                    'message' => '验证码发送过于频繁，请稍后再试',
                ];
            }
        }

        if ($target !== '' && $config['target_hour_limit'] > 0) {
            if (RateLimiter::tooManyAttempts(
                $this->key($channel, 'target-hour', $target),
                $config['target_hour_limit']
            )) {
                return [
                    'ok' => false,
                    'message' => '该接收方一小时内发送次数已达上限，请稍后再试',
                ];
            }
        }

        if ($target !== '' && $config['target_day_limit'] > 0) {
            if (RateLimiter::tooManyAttempts(
                $this->key($channel, 'target-day', $target),
                $config['target_day_limit']
            )) {
                return [
                    'ok' => false,
                    // 限流 TTL 自首次命中起滚动 24 小时，不严格对齐自然日，避免"明日再试"误导。
                    'message' => '该接收方发送过于频繁，已被临时限制，请稍后再试',
                ];
            }
        }

        return ['ok' => true];
    }

    public function hit(string $channel, string $target, ?string $ip = null): void
    {
        $config = $this->getConfig($channel);

        if (! $config['enabled']) {
            return;
        }

        $ip = $ip ? trim($ip) : null;
        if ($ip && $config['ip_minute_limit'] > 0) {
            RateLimiter::hit(
                $this->key($channel, 'ip-minute', $ip),
                60
            );
        }

        $target = trim($target);
        if ($target !== '' && $config['target_minute_limit'] > 0) {
            RateLimiter::hit(
                $this->key($channel, 'target-minute', $target),
                60
            );
        }

        if ($target !== '' && $config['target_hour_limit'] > 0) {
            RateLimiter::hit(
                $this->key($channel, 'target-hour', $target),
                3600
            );
        }

        if ($target !== '' && $config['target_day_limit'] > 0) {
            RateLimiter::hit(
                $this->key($channel, 'target-day', $target),
                86400
            );
        }
    }

    private function getConfig(string $channel): array
    {
        $pluginConfig = $this->pluginConfig($channel);
        if ($pluginConfig === null) {
            return [
                'enabled' => false,
                'ip_minute_limit' => 0,
                'target_minute_limit' => 0,
                'target_hour_limit' => 0,
                'target_day_limit' => 0,
            ];
        }

        return [
            'enabled' => $this->boolValue($pluginConfig, 'rate_limit_enabled', true),
            'ip_minute_limit' => $this->intValue($pluginConfig, 'ip_minute_limit', self::DEFAULT_IP_MINUTE_LIMIT),
            'target_minute_limit' => $this->intValue($pluginConfig, 'target_minute_limit', self::DEFAULT_TARGET_MINUTE_LIMIT),
            'target_hour_limit' => $this->intValue($pluginConfig, 'target_hour_limit', self::DEFAULT_TARGET_HOUR_LIMIT),
            'target_day_limit' => $this->intValue($pluginConfig, 'target_day_limit', self::DEFAULT_TARGET_DAY_LIMIT),
        ];
    }

    private function key(string $channel, string $scope, string $identifier): string
    {
        return 'message-rate-limit:'.$channel.':'.$scope.':'.sha1($identifier);
    }

    private function pluginConfig(string $channel): ?array
    {
        $context = match ($channel) {
            'email' => $this->bindingResolver()->mailContext(),
            'sms' => $this->bindingResolver()->smsContext(),
            default => null,
        };

        if ($context === null) {
            return null;
        }

        $pluginId = (int) ($context['plugin_id'] ?? 0);
        if ($pluginId <= 0) {
            return null;
        }

        $plugin = IntegrationPlugin::query()->find($pluginId);
        if (! $plugin instanceof IntegrationPlugin || ! $plugin->isEnabled()) {
            return null;
        }

        return $this->configRepository()->resolvedConfig($plugin);
    }

    private function intValue(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;
        $parsed = (int) $value;

        return $parsed >= 0 ? $parsed : $default;
    }

    private function boolValue(array $config, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $config)) {
            return $default;
        }

        $value = $config[$key];
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'on', 'yes'], true);
    }

    private function bindingResolver(): IntegrationDriverBindingResolver
    {
        return $this->bindingResolver ??= app(IntegrationDriverBindingResolver::class);
    }

    private function configRepository(): PluginConfigRepository
    {
        return $this->configRepository ??= app(PluginConfigRepository::class);
    }
}
