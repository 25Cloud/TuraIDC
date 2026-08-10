<?php

namespace App\Http\Resources\Product;

use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Integrations\Plugins\SupplierPluginCardRenderer;
use App\Services\Upstream\ProviderRegistry;
use App\Support\AdminPrivacy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Schema;

/** @mixin Supplier */
class SupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $privacy = AdminPrivacy::fromRequest($request);
        $binding = $this->bindingProjection();
        $providerKey = trim((string) ($binding['provider_key'] ?? ''));

        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'provider_key' => $providerKey,
            'provider_label' => $this->providerLabel($providerKey),
            'api_url' => '',
            'has_api_url' => (bool) ($binding['has_base_url'] ?? false),
            'api_username' => (string) ($binding['account_name'] ?? ''),
            'has_api_key' => (bool) ($binding['has_api_key'] ?? false),
            'has_provider_secret_values' => $this->providerSecretValues($providerKey, (array) ($binding['provider_config'] ?? [])),
            'provider_config' => $this->visibleProviderConfig($providerKey, (array) ($binding['provider_config'] ?? [])),
            'upstream_binding' => $this->upstreamBindingPayload($binding),
            'contact_name' => $privacy->name($this->contact_name),
            'contact_phone' => $privacy->phone($this->contact_phone),
            'contact_email' => $privacy->email($this->contact_email),
            'website' => $this->website,
            'status' => $this->status,
            'sort_order' => $this->sort_order,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
            'card' => app(SupplierPluginCardRenderer::class)->render($this->resource, [
                'binding' => $binding,
            ]),
        ];
    }

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

            $visible[$key] = (bool) ($field['secret'] ?? false) ? '' : ($providerConfig[$key] ?? null);
        }

        return array_filter($visible, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function providerSecretValues(string $providerKey, array $providerConfig): array
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

    private function upstreamBindingPayload(?array $binding = null): ?array
    {
        if (! Schema::hasTable('supplier_plugin_bindings')) {
            return null;
        }

        $binding ??= $this->bindingProjection();
        if ($binding === []) {
            return null;
        }

        return [
            'id' => (int) $binding['id'],
            'plugin_id' => (int) $binding['plugin_id'],
            'provider_key' => (string) $binding['provider_key'],
            'environment' => (string) $binding['environment'],
            'status' => (int) $binding['status'],
            'priority' => (int) $binding['priority'],
            'base_url' => '',
            'has_base_url' => (bool) ($binding['has_base_url'] ?? false),
            'account_name' => (string) ($binding['account_name'] ?? ''),
            'has_secret_values' => is_array($binding['has_secret_values'] ?? null) ? $binding['has_secret_values'] : [],
            'last_checked_at' => $binding['last_checked_at'] ?? null,
            'last_check_status' => $binding['last_check_status'] ?? null,
            'last_check_error' => $binding['last_check_error'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function bindingProjection(): array
    {
        return app(PluginBindingResolver::class)->supplierBindingProjection($this->resource);
    }

    private function providerLabel(string $providerKey): string
    {
        if ($providerKey === '') {
            return '';
        }

        return app(ProviderRegistry::class)->descriptor($providerKey)?->label ?? $providerKey;
    }
}
