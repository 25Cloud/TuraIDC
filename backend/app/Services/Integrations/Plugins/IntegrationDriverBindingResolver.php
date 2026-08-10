<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins;

use App\Models\IntegrationPlugin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IntegrationDriverBindingResolver
{
    /**
     * @return array{plugin_id: int|null, driver_key: string}
     */
    public function mailContext(?string $driverKey = null): array
    {
        return $this->contextForDomain(
            PluginDomain::MAIL,
            $driverKey ?: $this->mailDriverKey()
        );
    }

    /**
     * @return array{plugin_id: int|null, driver_key: string}
     */
    public function smsContext(?string $driverKey = null): array
    {
        return $this->contextForDomain(
            PluginDomain::SMS,
            $driverKey ?: $this->smsDriverKey()
        );
    }

    /**
     * @return array{plugin_id: int|null, driver_key: string}
     */
    public function verificationContext(?string $driverKey = null): array
    {
        return $this->contextForDomain(
            PluginDomain::VERIFICATION,
            $driverKey ?: $this->verificationDriverKey()
        );
    }

    public function mailDriverKey(): string
    {
        return $this->mailDriverCandidates()[0] ?? 'smtp';
    }

    public function smsDriverKey(): string
    {
        return $this->smsDriverCandidates()[0] ?? 'aliyun';
    }

    public function captchaDriverKey(): string
    {
        return $this->captchaDriverCandidates()[0] ?? '';
    }

    public function verificationDriverKey(): string
    {
        return $this->verificationDriverCandidates()[0] ?? 'stay33';
    }

    /**
     * @return array<int, string>
     */
    public function mailDriverCandidates(): array
    {
        return $this->uniqueKeys([
            $this->selectedBindingProviderKey(PluginDomain::MAIL, 'mail_driver'),
            config('integrations.mail.default', 'smtp'),
            'smtp',
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function smsDriverCandidates(): array
    {
        return $this->uniqueKeys([
            $this->selectedBindingProviderKey(PluginDomain::SMS, 'sms_driver'),
            config('integrations.sms.default', 'aliyun'),
            'aliyun',
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function captchaDriverCandidates(): array
    {
        return $this->uniqueKeys([
            $this->selectedBindingProviderKey(PluginDomain::CAPTCHA, 'captcha_driver'),
            $this->firstEnabledPluginKey(PluginDomain::CAPTCHA),
        ]);
    }

    /**
     * @return array<int, string>
     */
    public function verificationDriverCandidates(): array
    {
        return $this->uniqueKeys([
            $this->selectedBindingProviderKey(PluginDomain::VERIFICATION, 'verification_driver'),
            config('integrations.identity.default', 'stay33'),
            'stay33',
        ]);
    }

    /**
     * @return array{plugin_id: int|null, driver_key: string}
     */
    private function contextForDomain(string $domain, string $driverKey): array
    {
        $resolvedDriverKey = trim($driverKey);

        return [
            'plugin_id' => $this->pluginIdForDriver($domain, $resolvedDriverKey),
            'driver_key' => $resolvedDriverKey,
        ];
    }

    private function selectedBindingProviderKey(string $domain, string $bindingKey): string
    {
        if (! Schema::hasTable('integration_plugin_bindings')) {
            return '';
        }

        $binding = DB::table('integration_plugin_bindings')
            ->where('domain', $domain)
            ->where('bindable_type', 'setting')
            ->where('bindable_id', 0)
            ->where('binding_key', $bindingKey)
            ->where('status', 1)
            ->orderByDesc('status')
            ->orderBy('priority')
            ->orderByDesc('id')
            ->first(['provider_key']);

        return trim((string) ($binding->provider_key ?? ''));
    }

    private function pluginIdForDriver(string $domain, string $driverKey): ?int
    {
        if ($driverKey === '') {
            return null;
        }

        if (Schema::hasTable('integration_plugin_bindings')) {
            $binding = DB::table('integration_plugin_bindings')
                ->where('domain', $domain)
                ->where('provider_key', $driverKey)
                ->where('status', 1)
                ->orderByDesc('status')
                ->orderBy('priority')
                ->orderByDesc('id')
                ->first(['plugin_id']);

            if ($binding !== null && (int) ($binding->plugin_id ?? 0) > 0) {
                return (int) $binding->plugin_id;
            }
        }

        if (! Schema::hasTable('integration_plugins')) {
            return null;
        }

        $plugin = DB::table('integration_plugins')
            ->where('domain', $domain)
            ->where('status', IntegrationPlugin::STATUS_ENABLED)
            ->where(static function ($query) use ($driverKey): void {
                $query->where('plugin_key', $driverKey)
                    ->orWhere('slug', $driverKey);
            })
            ->orderByDesc('status')
            ->orderBy('id')
            ->first(['id']);

        return $plugin === null ? null : (int) $plugin->id;
    }

    private function firstEnabledPluginKey(string $domain): string
    {
        if (! Schema::hasTable('integration_plugins')) {
            return '';
        }

        $plugin = DB::table('integration_plugins')
            ->where('domain', $domain)
            ->where('status', IntegrationPlugin::STATUS_ENABLED)
            ->orderBy('id')
            ->first(['plugin_key', 'slug']);

        if ($plugin === null) {
            return '';
        }

        return trim((string) ($plugin->plugin_key ?? '')) ?: trim((string) ($plugin->slug ?? ''));
    }

    /**
     * @param  array<int, mixed>  $keys
     * @return array<int, string>
     */
    private function uniqueKeys(array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            $resolvedKey = trim((string) $key);
            if ($resolvedKey !== '' && ! in_array($resolvedKey, $result, true)) {
                $result[] = $resolvedKey;
            }
        }

        return $result;
    }
}
