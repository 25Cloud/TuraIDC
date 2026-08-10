<?php

namespace App\Services\Auth;

use App\Models\IntegrationPlugin;
use App\Services\Integrations\Plugins\IntegrationDriverBindingResolver;
use App\Services\Integrations\Plugins\PluginConfigRepository;
use Illuminate\Support\Facades\RateLimiter;

class MessageRateLimitService
{
    private const DEFAULT_IP_MINUTE_LIMIT = 6;

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
        if (! $ip || $config['ip_minute_limit'] <= 0) {
            return ['ok' => true];
        }

        $ipMinuteKey = $this->key($channel, 'ip-minute', $ip);
        if (RateLimiter::tooManyAttempts($ipMinuteKey, $config['ip_minute_limit'])) {
            return [
                'ok' => false,
                'message' => '当前 IP 每分钟发送次数已达上限，请稍后再试',
            ];
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
    }

    private function getConfig(string $channel): array
    {
        $pluginConfig = $this->pluginConfig($channel);
        if ($pluginConfig === null) {
            return [
                'enabled' => false,
                'ip_minute_limit' => 0,
            ];
        }

        return [
            'enabled' => $this->boolValue($pluginConfig, 'rate_limit_enabled', true),
            'ip_minute_limit' => $this->intValue($pluginConfig, 'ip_minute_limit', self::DEFAULT_IP_MINUTE_LIMIT),
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
