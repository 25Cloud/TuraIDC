<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins;

use App\Exceptions\BusinessException;
use App\Models\IntegrationPlugin;
use App\Services\Integrations\Payments\PaymentGatewayManager;
use App\Services\Integrations\Payments\PaymentGatewayRegistry;
use App\Services\Mail\MailDriverManager;
use App\Services\Sms\SmsDriverManager;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use App\Services\Verification\VerificationDriverManager;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PluginInstaller
{
    public function __construct(
        private readonly PluginScanner $scanner,
        private readonly PluginFileLoader $fileLoader,
        private readonly PluginConfigRepository $configRepository,
        private readonly PluginProviderRegistry $providerRegistry,
        private readonly Container $container,
    ) {}

    public function install(string $domain, string $slug): IntegrationPlugin
    {
        $manifest = $this->scanner->requireManifest($domain, $slug);
        $this->assertManifestExecutable($manifest);

        $plugin = DB::transaction(function () use ($manifest): IntegrationPlugin {
            return IntegrationPlugin::query()->updateOrCreate(
                [
                    'domain' => $manifest->domain,
                    'slug' => $manifest->slug,
                ],
                [
                    'plugin_key' => $manifest->key,
                    'name' => $manifest->name,
                    'version' => $manifest->version,
                    'provider_class' => $manifest->providerClass,
                    'entry_class' => $manifest->entryClass,
                    'capabilities_json' => $manifest->capabilities,
                    'config_schema_json' => $manifest->configSchema,
                    'installed_at' => now(),
                ]
            );
        });

        $this->forgetDomainRuntime($manifest->domain);

        return $plugin;
    }

    public function enable(IntegrationPlugin $plugin): IntegrationPlugin
    {
        $manifest = $this->scanner->requireManifest((string) $plugin->domain, (string) $plugin->slug);
        $this->assertManifestExecutable($manifest);

        DB::transaction(function () use ($plugin, $manifest): void {
            $this->assertSingleEnabledDomainAvailable($plugin);
            $this->configRepository->assertConfigReady($plugin, $manifest);

            $plugin->forceFill([
                'status' => IntegrationPlugin::STATUS_ENABLED,
            ])->save();

            $this->syncDriverBinding($plugin, $manifest, true);
        });

        $this->forgetDomainRuntime($manifest->domain);
        $this->providerRegistry->activate($manifest);

        return $plugin->fresh('config') ?? $plugin;
    }

    private function assertSingleEnabledDomainAvailable(IntegrationPlugin $plugin): void
    {
        $domain = (string) $plugin->domain;
        if (! PluginDomain::requiresSingleEnabledPlugin($domain)) {
            return;
        }

        $enabledPlugin = IntegrationPlugin::query()
            ->where('domain', $domain)
            ->where('status', IntegrationPlugin::STATUS_ENABLED)
            ->where('id', '<>', (int) $plugin->id)
            ->lockForUpdate()
            ->first(['id', 'name', 'slug']);

        if (! $enabledPlugin instanceof IntegrationPlugin) {
            return;
        }

        throw new BusinessException($this->singleEnabledDomainMessage($enabledPlugin), 42200);
    }

    private function singleEnabledDomainMessage(IntegrationPlugin $enabledPlugin): string
    {
        $pluginName = trim((string) $enabledPlugin->name) !== ''
            ? (string) $enabledPlugin->name
            : ((string) $enabledPlugin->slug ?: '其他插件');

        return "当前功能域已启用「{$pluginName}」，请先停用后再启用其他插件";
    }

    public function disable(IntegrationPlugin $plugin): IntegrationPlugin
    {
        $manifest = $this->scanner->find((string) $plugin->domain, (string) $plugin->slug);

        if (! $manifest instanceof PluginManifest) {
            DB::transaction(function () use ($plugin): void {
                $plugin->forceFill([
                    'status' => IntegrationPlugin::STATUS_DISABLED,
                ])->save();

                if (Schema::hasTable('integration_plugin_bindings')) {
                    DB::table('integration_plugin_bindings')
                        ->where('plugin_id', (int) $plugin->id)
                        ->update([
                            'status' => 0,
                            'updated_at' => now(),
                        ]);
                }
            });

            $this->forgetDomainRuntime((string) $plugin->domain);

            return $plugin->fresh('config') ?? $plugin;
        }

        DB::transaction(function () use ($plugin, $manifest): void {
            $plugin->forceFill([
                'status' => IntegrationPlugin::STATUS_DISABLED,
            ])->save();

            $this->syncDriverBinding($plugin, $manifest, false);
        });

        $this->forgetDomainRuntime($manifest->domain);

        return $plugin->fresh('config') ?? $plugin;
    }

    private function assertManifestExecutable(PluginManifest $manifest): void
    {
        $this->fileLoader->ensureLoaded($manifest);

        if (! class_exists($manifest->entryClass)) {
            throw new BusinessException('插件入口类不存在', 42200);
        }

        if (! method_exists($manifest->entryClass, 'execute')) {
            throw new BusinessException('插件入口类缺少 execute 方法', 42200);
        }
    }

    private function syncDriverBinding(IntegrationPlugin $plugin, PluginManifest $manifest, bool $enabled): void
    {
        if (! Schema::hasTable('integration_plugin_bindings')) {
            return;
        }

        $binding = is_array($manifest->extra['driver_binding'] ?? null) ? $manifest->extra['driver_binding'] : [];
        $bindingKey = trim((string) ($binding['binding_key'] ?? ''));
        if ($bindingKey === '') {
            return;
        }

        $identity = [
            'domain' => $manifest->domain,
            'binding_type' => trim((string) ($binding['binding_type'] ?? 'global')) ?: 'global',
            'bindable_type' => trim((string) ($binding['bindable_type'] ?? 'setting')) ?: 'setting',
            'bindable_id' => (int) ($binding['bindable_id'] ?? 0),
            'binding_key' => $bindingKey,
        ];

        $payload = [
            'plugin_id' => (int) $plugin->id,
            'provider_key' => trim((string) ($binding['provider_key'] ?? '')) ?: $manifest->key,
            'priority' => (int) ($binding['priority'] ?? 0),
            'status' => $enabled ? 1 : 0,
            'updated_at' => now(),
        ];

        $query = DB::table('integration_plugin_bindings')->where($identity);
        if ($query->exists()) {
            $query->update($payload);

            return;
        }

        DB::table('integration_plugin_bindings')->insert(array_merge($identity, $payload, [
            'created_at' => now(),
        ]));
    }

    private function forgetDomainRuntime(string $domain): void
    {
        match ($domain) {
            PluginDomain::PAYMENT => $this->forgetInstances(PaymentGatewayRegistry::class, PaymentGatewayManager::class),
            PluginDomain::VERIFICATION => $this->forgetInstances(VerificationDriverManager::class),
            PluginDomain::MAIL => $this->forgetInstances(MailDriverManager::class),
            PluginDomain::SMS => $this->forgetInstances(SmsDriverManager::class),
            PluginDomain::UPSTREAM => $this->forgetInstances(ProviderRegistry::class, ProviderResolver::class),
            default => null,
        };
    }

    private function forgetInstances(string ...$classes): void
    {
        foreach ($classes as $class) {
            $this->container->forgetInstance($class);
        }
    }
}
