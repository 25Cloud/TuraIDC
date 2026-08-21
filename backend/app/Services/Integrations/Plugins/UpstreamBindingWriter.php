<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins;

use App\Models\Product;
use App\Models\Supplier;
use App\Services\Upstream\ProviderKey;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\Support\WebSessionCookieParser;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class UpstreamBindingWriter
{
    /**
     * @param  array<string, mixed>|null  $bindingPayload
     */
    public function syncSupplierBinding(Supplier $supplier, ?array $bindingPayload = null): ?int
    {
        if (! $this->hasTables(['supplier_plugin_bindings', 'integration_plugins'])) {
            return null;
        }

        $bindingPayload ??= [];
        $existingBinding = $this->existingSupplierBinding((int) $supplier->id);
        $providerKey = trim((string) ($bindingPayload['provider_key'] ?? $existingBinding?->provider_key ?? ''));
        $pluginId = $this->pluginIdForProvider($providerKey);
        if ($providerKey === '' || $pluginId === null) {
            return null;
        }

        $existingSecrets = $this->existingSupplierSecrets((int) $supplier->id, $pluginId);
        $providerConfig = is_array($bindingPayload['provider_config'] ?? null)
            ? (array) $bindingPayload['provider_config']
            : (is_array($existingSecrets['provider_config'] ?? null) ? (array) $existingSecrets['provider_config'] : []);
        $apiKey = $this->nullableString($bindingPayload['api_key'] ?? null, 255);
        if ($apiKey === null) {
            $apiKey = $this->nullableString($existingSecrets['api_key'] ?? null, 255);
        }

        // 上游 Web 会话 Cookie 纳入加密存储：优先 bindingPayload 显式提交，
        // 其次从供应商 notes 的会话 Cookie 行提取迁入（历史明文），最后保留既有 secret。
        $webSessionCookie = $this->nullableString($bindingPayload['web_session_cookie'] ?? null, 4000);
        if ($webSessionCookie === null) {
            $webSessionCookie = $this->nullableString(
                $this->webSessionCookieFromNotes((string) ($supplier->notes ?? '')),
                4000
            );
        }
        if ($webSessionCookie === null) {
            $webSessionCookie = $this->nullableString($existingSecrets['web_session_cookie'] ?? null, 4000);
        }

        $now = now();
        $secretPayload = [
            'api_key' => $apiKey,
            'web_session_cookie' => $webSessionCookie,
            'provider_config' => $providerConfig,
        ];
        $payload = [
            'supplier_id' => (int) $supplier->id,
            'plugin_id' => $pluginId,
            'provider_key' => $providerKey,
            'environment' => $this->nullableString($bindingPayload['environment'] ?? $existingBinding?->environment ?? null, 60) ?? 'production',
            'status' => (int) ($bindingPayload['status'] ?? $existingBinding?->status ?? $supplier->status ?? 0),
            'ticket_delivery_enabled' => $providerKey === ProviderKey::ZJMF_FINANCE_API
                ? (int) ($bindingPayload['ticket_delivery_enabled'] ?? $existingBinding?->ticket_delivery_enabled ?? 0)
                : 0,
            'priority' => (int) ($bindingPayload['priority'] ?? $existingBinding?->priority ?? $supplier->sort_order ?? 0),
            'base_url' => $this->nullableString($bindingPayload['base_url'] ?? $existingBinding?->base_url ?? null, 255),
            'account_name' => $this->nullableString($bindingPayload['account_name'] ?? $existingBinding?->account_name ?? null, 120),
            'config_json' => $this->encodeJson([
                'legacy_supplier_code' => $this->nullableString($supplier->code ?? null, 120),
                'provider_config_present' => $providerConfig !== [],
                'source' => 'supplier_upsert',
            ]),
            'secret_json' => $this->encryptSecrets($secretPayload),
            'has_secret_json' => $this->encodeJson($this->supplierSecretMap($providerKey, $secretPayload)),
            'updated_at' => $now,
        ];

        DB::table('supplier_plugin_bindings')->updateOrInsert(
            [
                'supplier_id' => (int) $supplier->id,
                'plugin_id' => $pluginId,
                'environment' => $payload['environment'],
            ],
            array_merge($payload, ['created_at' => $now])
        );

        $binding = DB::table('supplier_plugin_bindings')
            ->where('supplier_id', (int) $supplier->id)
            ->where('plugin_id', $pluginId)
            ->where('environment', $payload['environment'])
            ->first(['id']);

        return $binding === null ? null : (int) $binding->id;
    }

    /**
     * @param  array<string, mixed>|null  $upstreamSnapshot
     * @param  array<int, mixed>|array<string, mixed>|null  $optionSchema
     */
    public function syncProductBinding(
        Product $product,
        ?Supplier $supplier = null,
        mixed $upstreamProductId = null,
        ?array $upstreamSnapshot = null,
        ?array $optionSchema = null,
    ): ?int {
        if (! $this->hasTables(['supplier_plugin_bindings', 'product_upstream_bindings', 'integration_plugins'])) {
            return null;
        }

        $existingProductBindingId = $this->existingProductBindingId((int) $product->id);
        $supplier ??= app(PluginBindingResolver::class)->supplierForProduct($product);
        if (! $supplier instanceof Supplier) {
            return $existingProductBindingId;
        }

        $supplierBindingId = $this->syncSupplierBinding($supplier) ?? $this->existingSupplierBindingId($supplier);
        if ($supplierBindingId === null) {
            return null;
        }

        $supplierBinding = DB::table('supplier_plugin_bindings')->where('id', $supplierBindingId)->first();
        if ($supplierBinding === null) {
            return null;
        }

        $resolvedUpstreamProductId = trim((string) ($upstreamProductId ?? ''));
        if ($resolvedUpstreamProductId === '') {
            return $existingProductBindingId;
        }

        $now = now();
        $payload = [
            'product_id' => (int) $product->id,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'plugin_id' => (int) $supplierBinding->plugin_id,
            'provider_key' => (string) $supplierBinding->provider_key,
            'upstream_product_id' => $resolvedUpstreamProductId,
            'upstream_product_snapshot_json' => $this->encodeJson($upstreamSnapshot ?? [
                'name' => $product->supplier_product_name ?: $product->name,
                'source' => 'product_sync',
            ]),
            'option_schema_json' => $this->encodeJson($optionSchema ?? (array) ($product->config_options ?? [])),
            'provision_policy_json' => $this->encodeJson([
                'purchase_requires' => (array) ($product->purchase_requires ?? []),
            ]),
            'auto_setup' => (int) ($product->auto_setup ?? 0) === 1 ? 1 : 0,
            'status' => (int) ($product->status ?? 0),
            'last_synced_at' => $now,
            'last_sync_error' => null,
            'updated_at' => $now,
        ];

        DB::table('product_upstream_bindings')->updateOrInsert(
            [
                'product_id' => (int) $product->id,
                'supplier_plugin_binding_id' => $supplierBindingId,
                'upstream_product_id' => $resolvedUpstreamProductId,
            ],
            array_merge($payload, ['created_at' => $now])
        );

        $binding = DB::table('product_upstream_bindings')
            ->where('product_id', (int) $product->id)
            ->where('supplier_plugin_binding_id', $supplierBindingId)
            ->where('upstream_product_id', $resolvedUpstreamProductId)
            ->first(['id']);

        return $binding === null ? null : (int) $binding->id;
    }

    private function pluginIdForProvider(string $providerKey): ?int
    {
        if ($providerKey === '' || ! Schema::hasTable('integration_plugins')) {
            return null;
        }

        $plugin = DB::table('integration_plugins')
            ->where('domain', PluginDomain::UPSTREAM)
            ->where(static function ($query) use ($providerKey): void {
                $query->where('plugin_key', $providerKey)
                    ->orWhere('slug', $providerKey);
            })
            ->orderByDesc('status')
            ->orderBy('id')
            ->first(['id']);

        return $plugin === null ? null : (int) $plugin->id;
    }

    private function existingSupplierBindingId(Supplier $supplier): ?int
    {
        $binding = $this->existingSupplierBinding((int) $supplier->id);

        return $binding === null ? null : (int) $binding->id;
    }

    private function existingSupplierBinding(int $supplierId): ?object
    {
        if ($supplierId <= 0 || ! Schema::hasTable('supplier_plugin_bindings')) {
            return null;
        }

        return DB::table('supplier_plugin_bindings')
            ->where('supplier_id', $supplierId)
            ->orderByDesc('status')
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->first();
    }

    private function existingProductBindingId(int $productId): ?int
    {
        if ($productId <= 0 || ! Schema::hasTable('product_upstream_bindings')) {
            return null;
        }

        $binding = DB::table('product_upstream_bindings')
            ->where('product_id', $productId)
            ->orderByDesc('status')
            ->orderByDesc('id')
            ->first(['id']);

        return $binding === null ? null : (int) $binding->id;
    }

    /**
     * @return array<string, mixed>
     */
    private function existingSupplierSecrets(int $supplierId, int $pluginId): array
    {
        if ($supplierId <= 0 || $pluginId <= 0) {
            return [];
        }

        $encrypted = DB::table('supplier_plugin_bindings')
            ->where('supplier_id', $supplierId)
            ->where('plugin_id', $pluginId)
            ->where('environment', 'production')
            ->value('secret_json');

        $encrypted = trim((string) ($encrypted ?? ''));
        if ($encrypted === '') {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($encrypted), true);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<int, string>  $tables
     */
    private function hasTables(array $tables): bool
    {
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function encodeJson(?array $payload): ?string
    {
        if ($payload === null || $payload === []) {
            return null;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<string, mixed>  $secrets
     */
    private function encryptSecrets(array $secrets): ?string
    {
        $filtered = array_filter($secrets, static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
        if ($filtered === []) {
            return null;
        }

        return Crypt::encryptString((string) json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $secrets
     * @return array<string, bool>|null
     */
    private function hasSecretMap(array $secrets): ?array
    {
        $map = [];
        foreach ($secrets as $key => $value) {
            if ($value !== null && $value !== '' && $value !== []) {
                $map[$key] = true;
            }
        }

        return $map === [] ? null : $map;
    }

    /**
     * @param  array<string, mixed>  $secrets
     * @return array<string, bool>|null
     */
    private function supplierSecretMap(string $providerKey, array $secrets): ?array
    {
        $map = [];

        if ($this->nullableString($secrets['api_key'] ?? null, 255) !== null) {
            $map['api_key'] = true;
        }

        if ($this->nullableString($secrets['web_session_cookie'] ?? null, 4000) !== null) {
            $map['web_session_cookie'] = true;
        }

        $providerConfig = is_array($secrets['provider_config'] ?? null) ? (array) $secrets['provider_config'] : [];
        $descriptor = app(ProviderRegistry::class)->descriptor($providerKey);
        foreach ((array) ($descriptor?->supplierForm['fields'] ?? []) as $field) {
            if (! is_array($field) || ! (bool) ($field['secret'] ?? false)) {
                continue;
            }

            $key = trim((string) ($field['key'] ?? ''));
            if ($key === '' || $key === 'api_key') {
                continue;
            }

            if ($this->nullableString($providerConfig[$key] ?? null, 1000) !== null) {
                $map[$key] = true;
            }
        }

        return $map === [] ? null : $map;
    }

    /**
     * 从供应商 notes 提取会话 Cookie（JSON 或文本行），用于加密迁入 secret_json。
     */
    private function webSessionCookieFromNotes(string $notes): ?string
    {
        if (trim($notes) === '') {
            return null;
        }

        $cookie = app(WebSessionCookieParser::class)->parse($notes);

        return trim($cookie) !== '' ? $cookie : null;
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        $normalized = trim((string) ($value ?? ''));
        if ($normalized === '') {
            return null;
        }

        return mb_substr($normalized, 0, $maxLength);
    }
}
