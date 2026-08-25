<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Models\Supplier;
use App\Models\SupplierBalance;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Integrations\Plugins\SupplierPluginCardRenderer;
use App\Services\Upstream\ProviderRegistry;
use App\Support\AdminPrivacy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Schema;

/** @mixin Supplier */
class AdminSupplierResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $privacy = AdminPrivacy::fromRequest($request);
        $binding = $this->bindingProjection();
        $providerKey = trim((string) ($binding['provider_key'] ?? ''));

        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'code' => (string) $this->code,
            'provider_key' => $providerKey,
            'provider_label' => $this->providerLabel($providerKey),
            'connection' => [
                'base_url' => (string) ($binding['base_url'] ?? ''),
                'base_url_configured' => (bool) ($binding['has_base_url'] ?? false),
                'account_name' => (string) ($binding['account_name'] ?? ''),
            ],
            'credentials' => [
                'api_credential_configured' => (bool) ($binding['has_api_key'] ?? false),
                'provider_values_configured' => $this->providerCredentialValues($providerKey, (array) ($binding['provider_config'] ?? [])),
            ],
            'provider_config' => $this->visibleProviderConfig($providerKey, (array) ($binding['provider_config'] ?? [])),
            'upstream_binding' => $this->upstreamBindingPayload($binding),
            'contact_name' => $privacy->name($this->contact_name),
            'contact_phone' => $privacy->phone($this->contact_phone),
            'contact_email' => $privacy->email($this->contact_email),
            'website' => $this->website,
            'status' => (int) $this->status,
            'sort_order' => (int) $this->sort_order,
            'notes' => $this->notes,
            'balance_setting' => $this->balanceSetting(),
            'created_at' => optional($this->created_at)?->format('Y-m-d H:i:s'),
            'updated_at' => optional($this->updated_at)?->format('Y-m-d H:i:s'),
            'card' => app(SupplierPluginCardRenderer::class)->render($this->resource, [
                'binding' => $binding,
            ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bindingProjection(): array
    {
        return app(PluginBindingResolver::class)->supplierBindingProjection($this->resource);
    }

    /**
     * 上游余额与告警设置。
     *
     * 表可能尚未迁移（老库升级过程中），此时返回默认值而不是让整个供应商接口报错。
     *
     * @return array<string, mixed>
     */
    private function balanceSetting(): array
    {
        $defaults = [
            'balance' => null,
            'currency' => null,
            'low_balance_threshold' => SupplierBalance::DEFAULT_LOW_BALANCE_THRESHOLD,
            'low_balance_alert_enabled' => true,
            'last_synced_at' => null,
            'last_sync_status' => null,
            'last_sync_error' => null,
            'is_below_threshold' => false,
        ];

        if (! Schema::hasTable('supplier_balances')) {
            return $defaults;
        }

        // 优先读预加载的关联：供应商列表逐个查会形成 N+1
        // （AdminConfigurationV2QueryService::suppliers() 已 with('balanceRecord')）。
        $record = $this->resource->relationLoaded('balanceRecord')
            ? $this->resource->getRelation('balanceRecord')
            : SupplierBalance::query()->where('supplier_id', (int) $this->id)->first();
        if (! $record instanceof SupplierBalance) {
            return $defaults;
        }

        return [
            'balance' => $record->balance === null ? null : (string) $record->balance,
            'currency' => $record->currency,
            'low_balance_threshold' => (string) $record->low_balance_threshold,
            'low_balance_alert_enabled' => (bool) $record->low_balance_alert_enabled,
            'last_synced_at' => optional($record->last_synced_at)?->format('Y-m-d H:i:s'),
            'last_sync_status' => $record->last_sync_status,
            'last_sync_error' => $record->last_sync_error,
            'is_below_threshold' => $record->isBelowThreshold(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function upstreamBindingPayload(array $binding): ?array
    {
        if (! Schema::hasTable('supplier_plugin_bindings') || $binding === []) {
            return null;
        }

        return [
            'id' => (int) $binding['id'],
            'plugin_id' => (int) $binding['plugin_id'],
            'provider_key' => (string) $binding['provider_key'],
            'environment' => (string) $binding['environment'],
            'status' => (int) $binding['status'],
            'ticket_delivery_enabled' => (bool) ($binding['ticket_delivery_enabled'] ?? false),
            'priority' => (int) $binding['priority'],
            'base_url' => (string) ($binding['base_url'] ?? ''),
            'base_url_configured' => (bool) ($binding['has_base_url'] ?? false),
            'account_name' => (string) ($binding['account_name'] ?? ''),
            'credentials_configured' => $this->nonApiCredentialFlags($binding),
            'last_checked_at' => $binding['last_checked_at'] ?? null,
            'last_check_status' => $binding['last_check_status'] ?? null,
        ];
    }

    /**
     * @return array<string, bool>
     */
    private function nonApiCredentialFlags(array $binding): array
    {
        $values = is_array($binding['has_secret_values'] ?? null) ? $binding['has_secret_values'] : [];
        unset($values['api_key']);

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function visibleProviderConfig(string $providerKey, array $providerConfig): array
    {
        $descriptor = app(ProviderRegistry::class)->descriptor($providerKey);
        $fields = (array) ($descriptor?->supplierForm['fields'] ?? []);
        $visible = [];

        foreach ($fields as $field) {
            if (! is_array($field)) {
                continue;
            }

            $key = trim((string) ($field['key'] ?? ''));
            if ($key === '' || in_array($key, ['api_url', 'api_username', 'api_key'], true)) {
                continue;
            }

            $value = $providerConfig[$key] ?? null;
            if ((bool) ($field['secret'] ?? false) || $value === null || $value === '') {
                continue;
            }

            $visible[$key] = $value;
        }

        return $visible;
    }

    /**
     * @return array<string, bool>
     */
    private function providerCredentialValues(string $providerKey, array $providerConfig): array
    {
        $descriptor = app(ProviderRegistry::class)->descriptor($providerKey);
        $fields = (array) ($descriptor?->supplierForm['fields'] ?? []);
        $values = [];

        foreach ($fields as $field) {
            if (! is_array($field) || ! (bool) ($field['secret'] ?? false)) {
                continue;
            }

            $key = trim((string) ($field['key'] ?? ''));
            if ($key === '' || in_array($key, ['api_url', 'api_username', 'api_key'], true)) {
                continue;
            }

            $values[$key] = trim((string) ($providerConfig[$key] ?? '')) !== '';
        }

        return $values;
    }

    private function providerLabel(string $providerKey): string
    {
        if ($providerKey === '') {
            return '';
        }

        return app(ProviderRegistry::class)->descriptor($providerKey)?->label ?? $providerKey;
    }
}
