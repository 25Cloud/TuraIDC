<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins;

use App\Exceptions\BusinessException;
use App\Models\AdminUser;
use App\Models\IntegrationPlugin;
use App\Models\IntegrationPluginConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PluginConfigRepository
{
    /**
     * 这里刻意不缓存解密结果。凭据必须每次读当前值：
     * updated_at 是秒级精度，同一秒内的两次保存无法区分；而队列 worker 是长驻进程，
     * 容器单例跨任务存活，缓存住的旧密钥会在管理员轮换凭据后继续被使用。
     * 单次 AES 解密的开销远小于这个风险。
     */
    public function resolvedConfig(IntegrationPlugin $plugin): array
    {
        $config = $plugin->relationLoaded('config') ? $plugin->config : $plugin->config()->first();

        if (! $config instanceof IntegrationPluginConfig) {
            return [];
        }

        return array_merge(
            is_array($config->config_json) ? $config->config_json : [],
            $this->decodeSecrets((string) ($config->secret_json ?? ''))
        );
    }

    public function resolvedConfigByDomainAndSlug(string $domain, string $slug): array
    {
        $plugin = Cache::remember(
            'plugin_record_'.trim($domain).'_'.md5(trim($slug)),
            60,
            function () use ($domain, $slug): ?IntegrationPlugin {
                if (! Schema::hasTable('integration_plugins')) {
                    return null;
                }

                return IntegrationPlugin::query()
                    ->where('domain', trim($domain))
                    ->where('slug', trim($slug))
                    ->first();
            }
        );

        return $plugin instanceof IntegrationPlugin ? $this->resolvedConfig($plugin) : [];
    }

    /**
     * @return array{config: array<string, mixed>, has_secret_values: array<string, bool>}
     */
    public function displayConfig(IntegrationPlugin $plugin): array
    {
        $config = $plugin->relationLoaded('config') ? $plugin->config : $plugin->config()->first();

        if (! $config instanceof IntegrationPluginConfig) {
            return [
                'config' => [],
                'has_secret_values' => [],
            ];
        }

        return [
            'config' => is_array($config->config_json) ? $config->config_json : [],
            'has_secret_values' => is_array($config->has_secret_json) ? $config->has_secret_json : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function secretPreviews(IntegrationPlugin $plugin, PluginManifest $manifest): array
    {
        $resolvedConfig = $this->resolvedConfig($plugin);
        $previews = [];

        foreach ($manifest->configSchema as $item) {
            if ($this->isDisplayOnlySchemaItem($item)) {
                continue;
            }

            if (! (bool) ($item['secret'] ?? false)) {
                continue;
            }

            $key = trim((string) ($item['key'] ?? ''));
            if ($key === '' || ! array_key_exists($key, $resolvedConfig)) {
                continue;
            }

            $value = $resolvedConfig[$key];
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            if ($key === 'accounts' && is_array($value)) {
                $previews[$key] = $this->smtpAccountsPreview($value);

                continue;
            }

            $previews[$key] = [
                'type' => 'configured',
                'configured' => true,
            ];
        }

        return $previews;
    }

    /**
     * @return array{key: string, value: mixed}
     */
    public function revealSecret(IntegrationPlugin $plugin, PluginManifest $manifest, string $key): array
    {
        $schemaKey = trim($key);
        if ($schemaKey === '') {
            throw new BusinessException('密钥字段不存在', 42200);
        }

        $schemaItem = collect($manifest->configSchema)->first(function (array $item) use ($schemaKey): bool {
            return trim((string) ($item['key'] ?? '')) === $schemaKey;
        });

        if (! is_array($schemaItem) || $this->isDisplayOnlySchemaItem($schemaItem) || ! (bool) ($schemaItem['secret'] ?? false)) {
            throw new BusinessException('密钥字段不存在', 42200);
        }

        $resolvedConfig = $this->resolvedConfig($plugin);
        $value = $resolvedConfig[$schemaKey] ?? null;

        if ($this->isBlankConfigValue($value)) {
            throw new BusinessException('密钥尚未配置', 42200);
        }

        return [
            'key' => $schemaKey,
            'value' => $value,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{config: array<string, mixed>, has_secret_values: array<string, bool>}
     */
    public function save(IntegrationPlugin $plugin, PluginManifest $manifest, array $input, ?AdminUser $admin = null): array
    {
        $previousResolvedConfig = $this->resolvedConfig($plugin);
        [$nonSecretPayload, $secretPayload] = $this->partitionConfigPayload($manifest, $input, $plugin);
        $hasSecretValues = array_map(static fn (mixed $value): bool => $value !== null && $value !== '', $secretPayload);
        $this->assertRequiredFields($manifest, array_merge($nonSecretPayload, $secretPayload));

        DB::transaction(function () use ($plugin, $nonSecretPayload, $secretPayload, $hasSecretValues, $admin): void {
            $plugin->config()->updateOrCreate(
                ['plugin_id' => (int) $plugin->id],
                [
                    'config_json' => $nonSecretPayload,
                    'secret_json' => $this->encodeSecrets($secretPayload),
                    'has_secret_json' => $hasSecretValues,
                    'updated_by' => $admin?->id,
                ]
            );
        });

        $this->clearConfigSavedRuntimeCaches($manifest, $previousResolvedConfig, array_merge($nonSecretPayload, $secretPayload));

        return $this->displayConfig($plugin->fresh('config') ?? $plugin);
    }

    public function assertConfigReady(IntegrationPlugin $plugin, PluginManifest $manifest): void
    {
        $this->assertRequiredFields($manifest, $this->resolvedConfig($plugin));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function partitionConfigPayload(PluginManifest $manifest, array $input, IntegrationPlugin $plugin): array
    {
        $existing = $this->resolvedConfig($plugin);
        $nonSecretPayload = [];
        $secretPayload = [];

        foreach ($manifest->configSchema as $item) {
            $schemaKey = trim((string) ($item['key'] ?? ''));
            if ($schemaKey === '') {
                continue;
            }

            if ($this->isDisplayOnlySchemaItem($item)) {
                continue;
            }

            $isSecret = (bool) ($item['secret'] ?? false);
            $hasSubmittedValue = array_key_exists($schemaKey, $input);
            $submittedValue = $input[$schemaKey] ?? null;

            if ($isSecret) {
                if ($schemaKey === 'accounts' && $hasSubmittedValue && is_array($submittedValue)) {
                    $secretPayload[$schemaKey] = $this->mergeSmtpAccountSecrets(
                        $submittedValue,
                        is_array($existing[$schemaKey] ?? null) ? $existing[$schemaKey] : []
                    );

                    continue;
                }

                if (! $hasSubmittedValue || $submittedValue === '' || $submittedValue === null) {
                    $secretPayload[$schemaKey] = $existing[$schemaKey] ?? null;
                } else {
                    $secretPayload[$schemaKey] = $submittedValue;
                }

                continue;
            }

            $nonSecretPayload[$schemaKey] = $hasSubmittedValue
                ? $submittedValue
                : ($existing[$schemaKey] ?? null);
        }

        return [$nonSecretPayload, $secretPayload];
    }

    /**
     * @param  array<string, mixed>  $resolvedConfig
     */
    private function assertRequiredFields(PluginManifest $manifest, array $resolvedConfig): void
    {
        foreach ($manifest->configSchema as $item) {
            if ($this->isDisplayOnlySchemaItem($item)) {
                continue;
            }

            if (! (bool) ($item['required'] ?? false)) {
                continue;
            }

            $key = trim((string) ($item['key'] ?? ''));
            $value = $resolvedConfig[$key] ?? null;

            if ($value === null || $value === '' || $value === []) {
                throw new BusinessException("插件配置缺少必填项 [{$key}]", 42200);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $previousResolvedConfig
     * @param  array<string, mixed>  $currentResolvedConfig
     */
    private function clearConfigSavedRuntimeCaches(
        PluginManifest $manifest,
        array $previousResolvedConfig,
        array $currentResolvedConfig,
    ): void {
        $hook = is_array($manifest->extra['config_saved_cache_clear'] ?? null)
            ? $manifest->extra['config_saved_cache_clear']
            : [];
        $class = trim((string) ($hook['class'] ?? ''));
        $method = trim((string) ($hook['method'] ?? ''));

        if ($class === '' || $method === '') {
            return;
        }

        try {
            app(PluginFileLoader::class)->ensureLoaded($manifest);

            if (! class_exists($class) || ! method_exists($class, $method)) {
                return;
            }

            $class::$method($previousResolvedConfig);
            $class::$method($currentResolvedConfig);
        } catch (\Throwable $exception) {
            Log::warning('[plugins] config saved cache clear hook failed', [
                'domain' => $manifest->domain,
                'slug' => $manifest->slug,
                'class' => $class,
                'method' => $method,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function isDisplayOnlySchemaItem(array $item): bool
    {
        return in_array((string) ($item['type'] ?? ''), ['notice', 'divider', 'readonly'], true);
    }

    private function isBlankConfigValue(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    /**
     * @param  array<int, mixed>  $accounts
     * @return array{type: string, count: int, items: array<int, array<string, mixed>>}
     */
    private function smtpAccountsPreview(array $accounts): array
    {
        $items = [];

        foreach ($accounts as $account) {
            if (! is_array($account)) {
                continue;
            }

            $items[] = [
                'index' => count($items),
                'host' => (string) ($account['host'] ?? ''),
                'port' => (int) (($account['port'] ?? 0) ?: 0),
                'username' => (string) ($account['username'] ?? ''),
                'from_name' => (string) ($account['from_name'] ?? ''),
                'encryption' => (string) ($account['encryption'] ?? ''),
                'enabled' => (bool) ($account['enabled'] ?? true),
                'password_configured' => trim((string) ($account['password'] ?? '')) !== '',
            ];
        }

        return [
            'type' => 'smtp_accounts',
            'count' => count($items),
            'items' => $items,
        ];
    }

    /**
     * @param  array<int, mixed>  $submittedAccounts
     * @param  array<int, mixed>  $existingAccounts
     * @return array<int, array<string, mixed>>
     */
    private function mergeSmtpAccountSecrets(array $submittedAccounts, array $existingAccounts): array
    {
        $accounts = [];

        foreach ($submittedAccounts as $position => $submitted) {
            if (! is_array($submitted)) {
                continue;
            }

            $existingIndex = array_key_exists('__index', $submitted)
                ? (int) $submitted['__index']
                : (int) $position;
            $existing = is_array($existingAccounts[$existingIndex] ?? null) ? $existingAccounts[$existingIndex] : [];
            $password = trim((string) ($submitted['password'] ?? '')) !== ''
                ? (string) $submitted['password']
                : (string) ($existing['password'] ?? '');

            $accounts[] = [
                'host' => trim((string) ($submitted['host'] ?? '')),
                'port' => (int) (($submitted['port'] ?? 0) ?: 0),
                'username' => trim((string) ($submitted['username'] ?? '')),
                'password' => $password,
                'from_name' => trim((string) ($submitted['from_name'] ?? '')),
                'encryption' => trim((string) ($submitted['encryption'] ?? '')),
                'enabled' => (bool) ($submitted['enabled'] ?? true),
            ];
        }

        return $accounts;
    }

    /**
     * @param  array<string, mixed>  $secrets
     */
    private function encodeSecrets(array $secrets): ?string
    {
        if ($secrets === []) {
            return null;
        }

        return Crypt::encryptString((string) json_encode($secrets, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeSecrets(string $payload): array
    {
        if (trim($payload) === '') {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($payload), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
