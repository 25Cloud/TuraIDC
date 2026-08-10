<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins;

use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ServiceUpstreamBindingWriter
{
    private ?PluginBindingResolver $bindingResolver = null;

    private ?UpstreamBindingWriter $upstreamBindingWriter = null;

    /**
     * @param  array<string, mixed>|null  $provisionData
     */
    public function syncServiceState(Service $service, ?Product $product = null, ?array $provisionData = null): ?int
    {
        if (! $this->hasTables(['services', 'integration_plugins', 'service_upstream_bindings'])) {
            return null;
        }

        $serviceId = (int) $service->getKey();
        if ($serviceId <= 0 || ! $this->serviceExists($serviceId)) {
            return null;
        }

        $provisionData ??= is_array($service->provision_data ?? null) ? $service->provision_data : [];
        $upstreamServiceId = $this->firstNonBlank($provisionData, ['upstream_host_id', 'server_id', 'host_id', 'id'])
            ?? $this->bindingResolver()->upstreamServiceIdForService($service);
        if ($upstreamServiceId === null) {
            return null;
        }

        $product = $this->resolveProduct($service, $product);
        $providerKey = $this->resolveProviderKey($service, $product, $provisionData);
        $pluginId = $this->pluginIdForProvider($providerKey);
        if ($providerKey === '' || $pluginId === null) {
            return null;
        }

        $productBindingId = $this->resolveProductBindingId($product);
        $supplierBindingId = $this->resolveSupplierBindingId($service, $product, $provisionData, $providerKey, $productBindingId);
        $runtimeSnapshot = $this->runtimeSnapshotPayload($provisionData, $providerKey);
        $connectionSnapshot = $this->connectionPayload($service, $provisionData);
        $now = now();

        DB::table('service_upstream_bindings')->updateOrInsert(
            [
                'service_id' => $serviceId,
                'plugin_id' => $pluginId,
                'upstream_service_id' => $this->limit($upstreamServiceId, 120),
            ],
            [
                'product_upstream_binding_id' => $productBindingId,
                'supplier_plugin_binding_id' => $supplierBindingId,
                'provider_key' => $providerKey,
                'upstream_account_id' => $this->nullableString($provisionData['upstream_account_id'] ?? null, 120),
                'runtime_snapshot_json' => $this->encodeJson($runtimeSnapshot),
                'connection_snapshot_json' => $this->encodeJson($connectionSnapshot),
                'status_snapshot' => $this->nullableString(
                    $provisionData['runtime_status'] ?? ($provisionData['upstream_status'] ?? null),
                    60
                ),
                'last_synced_at' => $now,
                'last_sync_error' => $this->nullableString($provisionData['status_sync_error'] ?? null, 500),
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );

        $binding = DB::table('service_upstream_bindings')
            ->where('service_id', $serviceId)
            ->where('plugin_id', $pluginId)
            ->where('upstream_service_id', $this->limit($upstreamServiceId, 120))
            ->first(['id']);

        if ($binding === null) {
            return null;
        }

        $bindingId = (int) $binding->id;
        $this->syncRuntimeSnapshot($serviceId, $bindingId, $pluginId, $providerKey, $provisionData, $runtimeSnapshot);
        $this->syncConnectionSnapshot($serviceId, $bindingId, $pluginId, $providerKey, $provisionData, $connectionSnapshot);

        return $bindingId;
    }

    /**
     * @param  array<string, mixed>  $provisionData
     * @param  array<string, mixed>  $requestMeta
     * @param  array<string, mixed>  $responseMeta
     */
    public function recordProvisionAttempt(
        Service $service,
        ?Product $product,
        array $provisionData,
        string $attemptStatus,
        ?string $errorMessage = null,
        array $requestMeta = [],
        array $responseMeta = [],
        string $action = 'provision',
    ): void {
        if (! $this->hasTables(['services', 'service_provision_attempts'])) {
            return;
        }

        $serviceId = (int) $service->getKey();
        if ($serviceId <= 0 || ! $this->serviceExists($serviceId)) {
            return;
        }

        $bindingId = $this->syncServiceState($service, $product, $provisionData);
        $context = $this->bindingContext($service, $product, $provisionData, $bindingId);
        $action = $this->normalizeAttemptAction($action);
        $traceId = $this->firstNonBlank($provisionData, ['trace_id'])
            ?? $this->firstNonBlank($requestMeta, ['trace_id']);
        $now = now();

        DB::table('service_provision_attempts')->insert([
            'service_id' => $serviceId,
            'service_upstream_binding_id' => $bindingId,
            'plugin_id' => $context['plugin_id'],
            'provider_key' => $context['provider_key'],
            'action' => $action,
            'attempt_status' => $this->limit($attemptStatus, 30),
            'trace_id' => $this->nullableString($traceId, 64),
            'request_meta_json' => $this->encodeJson(array_filter(array_merge([
                'requested_host' => $provisionData['requested_host'] ?? null,
                'created_from_order' => $provisionData['created_from_order'] ?? null,
                'requested_config_keys' => array_keys((array) ($provisionData['requested_config'] ?? [])),
            ], $requestMeta), static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [])),
            'response_meta_json' => $this->encodeJson(array_filter(array_merge([
                'upstream_host_id' => $provisionData['upstream_host_id'] ?? null,
                'upstream_invoice_id' => $provisionData['upstream_invoice_id'] ?? null,
            ], $responseMeta), static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== [])),
            'error_code' => $errorMessage === null ? null : $this->limit($action.'_failed', 80),
            'error_message' => $this->nullableString($errorMessage, 500),
            'attempted_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<string, mixed>  $provisionData
     * @return array{plugin_id: int|null, provider_key: string|null}
     */
    private function bindingContext(Service $service, ?Product $product, array $provisionData, ?int $bindingId): array
    {
        if ($bindingId !== null && Schema::hasTable('service_upstream_bindings')) {
            $binding = DB::table('service_upstream_bindings')->where('id', $bindingId)->first(['plugin_id', 'provider_key']);
            if ($binding !== null) {
                return [
                    'plugin_id' => $binding->plugin_id === null ? null : (int) $binding->plugin_id,
                    'provider_key' => $this->nullableString($binding->provider_key ?? null, 120),
                ];
            }
        }

        $product = $this->resolveProduct($service, $product);
        $providerKey = $this->resolveProviderKey($service, $product, $provisionData);

        return [
            'plugin_id' => $this->pluginIdForProvider($providerKey),
            'provider_key' => $providerKey !== '' ? $providerKey : null,
        ];
    }

    private function syncRuntimeSnapshot(
        int $serviceId,
        int $bindingId,
        int $pluginId,
        string $providerKey,
        array $provisionData,
        array $runtimeSnapshot
    ): void {
        if (! Schema::hasTable('service_runtime_snapshots')) {
            return;
        }

        $now = now();
        DB::table('service_runtime_snapshots')->updateOrInsert(
            ['service_id' => $serviceId],
            [
                'service_upstream_binding_id' => $bindingId,
                'plugin_id' => $pluginId,
                'provider_key' => $providerKey,
                'status_key' => $this->nullableString($provisionData['runtime_status'] ?? ($provisionData['upstream_status'] ?? null), 60),
                'status_text' => $this->nullableString($provisionData['runtime_description'] ?? null, 120),
                'resource_json' => $this->encodeJson($this->resourcePayload($provisionData)),
                'metrics_json' => $this->encodeJson($this->metricsPayload($provisionData)),
                'snapshot_json' => $this->encodeJson($runtimeSnapshot),
                'synced_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    private function syncConnectionSnapshot(
        int $serviceId,
        int $bindingId,
        int $pluginId,
        string $providerKey,
        array $provisionData,
        array $connectionSnapshot
    ): void {
        if (! Schema::hasTable('service_connection_snapshots')) {
            return;
        }

        $now = now();
        DB::table('service_connection_snapshots')->updateOrInsert(
            ['service_id' => $serviceId, 'connection_type' => 'default'],
            [
                'service_upstream_binding_id' => $bindingId,
                'plugin_id' => $pluginId,
                'provider_key' => $providerKey,
                'hostname' => $this->nullableString($connectionSnapshot['hostname'] ?? null, 255),
                'ip_address' => $this->nullableString($connectionSnapshot['ip_address'] ?? null, 120),
                'port' => is_numeric($connectionSnapshot['port'] ?? null) ? (int) $connectionSnapshot['port'] : null,
                'connection_json' => $this->encodeJson($connectionSnapshot),
                'secret_json' => $this->encryptSecrets([
                    'connection_secret' => $provisionData['connection_secret'] ?? null,
                    'password' => $provisionData['password'] ?? null,
                ]),
                'has_secret_json' => $this->encodeJson($this->hasSecretMap([
                    'connection_secret' => $provisionData['connection_secret'] ?? null,
                    'password' => $provisionData['password'] ?? null,
                ])),
                'checked_at' => $now,
                'updated_at' => $now,
                'created_at' => $now,
            ]
        );
    }

    private function resolveProduct(Service $service, ?Product $product): ?Product
    {
        if ($product instanceof Product) {
            $product->loadMissing('supplier');

            return $product;
        }

        if ($service->relationLoaded('product') && $service->product instanceof Product) {
            $service->product->loadMissing('supplier');

            return $service->product;
        }

        $productId = (int) ($service->product_id ?? 0);
        if ($productId <= 0) {
            return null;
        }

        return Product::query()->with('supplier')->find($productId);
    }

    /**
     * @param  array<string, mixed>  $provisionData
     */
    private function resolveProviderKey(Service $service, ?Product $product, array $provisionData): string
    {
        $providerKey = $this->bindingResolver()->providerKeyForService($service)
            ?? ($product instanceof Product ? $this->bindingResolver()->providerKeyForProduct($product) : null)
            ?? $this->firstNonBlank($provisionData, ['provider_key']);

        return trim((string) $providerKey);
    }

    private function resolveProductBindingId(?Product $product): ?int
    {
        if (! $product instanceof Product) {
            return null;
        }

        $bindingId = $this->upstreamBindingWriter()->syncProductBinding($product);
        if ($bindingId !== null) {
            return $bindingId;
        }

        if (! Schema::hasTable('product_upstream_bindings')) {
            return null;
        }

        $binding = DB::table('product_upstream_bindings')
            ->where('product_id', (int) $product->id)
            ->orderByDesc('status')
            ->orderByDesc('id')
            ->first(['id']);

        return $binding === null ? null : (int) $binding->id;
    }

    /**
     * @param  array<string, mixed>  $provisionData
     */
    private function resolveSupplierBindingId(
        Service $service,
        ?Product $product,
        array $provisionData,
        string $providerKey,
        ?int $productBindingId
    ): ?int {
        if ($productBindingId !== null && Schema::hasTable('product_upstream_bindings')) {
            $productBinding = DB::table('product_upstream_bindings')
                ->where('id', $productBindingId)
                ->first(['supplier_plugin_binding_id']);
            $bindingId = (int) (($productBinding->supplier_plugin_binding_id ?? 0) ?: 0);
            if ($bindingId > 0) {
                return $bindingId;
            }
        }

        $supplier = $this->resolveSupplier($service, $product, $provisionData);
        if ($supplier instanceof Supplier) {
            $bindingId = $this->upstreamBindingWriter()->syncSupplierBinding($supplier);
            if ($bindingId !== null) {
                return $bindingId;
            }
        }

        if (! Schema::hasTable('supplier_plugin_bindings')) {
            return null;
        }

        $supplierId = (int) (
            $this->bindingResolver()->supplierIdForService($service)
            ?? ($product instanceof Product ? $this->bindingResolver()->supplierIdForProduct($product) : null)
        );
        if ($supplierId <= 0) {
            return null;
        }

        $binding = DB::table('supplier_plugin_bindings')
            ->where('supplier_id', $supplierId)
            ->where('provider_key', $providerKey)
            ->orderByDesc('status')
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->first(['id']);

        return $binding === null ? null : (int) $binding->id;
    }

    /**
     * @param  array<string, mixed>  $provisionData
     */
    private function resolveSupplier(Service $service, ?Product $product, array $provisionData): ?Supplier
    {
        $supplier = $this->bindingResolver()->supplierForService($service)
            ?? ($product instanceof Product ? $this->bindingResolver()->supplierForProduct($product) : null);

        if ($supplier instanceof Supplier) {
            return $supplier;
        }

        $supplierId = (int) (
            $this->bindingResolver()->supplierIdForService($service)
            ?? ($product instanceof Product ? $this->bindingResolver()->supplierIdForProduct($product) : null)
            ?? ($provisionData['supplier_id'] ?? null)
        );

        return $supplierId > 0 ? Supplier::query()->find($supplierId) : null;
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

    /**
     * @param  array<string, mixed>  $provisionData
     * @return array<string, mixed>
     */
    private function runtimeSnapshotPayload(array $provisionData, string $providerKey): array
    {
        return array_filter([
            'provider_key' => $provisionData['provider_key'] ?? $providerKey,
            'upstream_status' => $provisionData['upstream_status'] ?? null,
            'runtime_status' => $provisionData['runtime_status'] ?? null,
            'runtime_description' => $provisionData['runtime_description'] ?? null,
            'last_synced_at' => $provisionData['last_synced_at'] ?? null,
            'last_status_sync_at' => $provisionData['last_status_sync_at'] ?? null,
            'status_sync_error' => $provisionData['status_sync_error'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $provisionData
     * @return array<string, mixed>
     */
    private function resourcePayload(array $provisionData): array
    {
        return array_filter([
            'supplier_id' => $provisionData['supplier_id'] ?? null,
            'upstream_product_id' => $provisionData['upstream_product_id'] ?? null,
            'upstream_product_name' => $provisionData['upstream_product_name'] ?? null,
            'upstream_host_id' => $provisionData['upstream_host_id'] ?? null,
            'upstream_host_ids' => $provisionData['upstream_host_ids'] ?? null,
            'upstream_invoice_id' => $provisionData['upstream_invoice_id'] ?? null,
            'host_config_option' => $provisionData['host_config_option'] ?? null,
            'requested_config' => $provisionData['requested_config'] ?? null,
            'created_from_order' => $provisionData['created_from_order'] ?? null,
            'source_type' => $provisionData['source_type'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @param  array<string, mixed>  $provisionData
     * @return array<string, mixed>
     */
    private function metricsPayload(array $provisionData): array
    {
        return array_filter([
            'bw_limit' => $provisionData['bw_limit'] ?? null,
            'bw_usage' => $provisionData['bw_usage'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $provisionData
     * @return array<string, mixed>
     */
    private function connectionPayload(Service $service, array $provisionData): array
    {
        $assignedIps = is_array($provisionData['assigned_ips'] ?? null) ? $provisionData['assigned_ips'] : [];
        $hostname = $this->firstNonBlank($provisionData, [
            'dedicated_ip',
            'nat_remote_host',
            'nat_remote_address',
            'requested_host',
            'custom_hostname',
            'default_service_name',
        ]) ?? $this->nullableString($service->domain ?? null, 255);

        return array_filter([
            'hostname' => $hostname,
            'ip_address' => $this->nullableString($provisionData['dedicated_ip'] ?? ($assignedIps[0] ?? null), 120),
            'port' => is_numeric($provisionData['nat_remote_port'] ?? null) ? (int) $provisionData['nat_remote_port'] : null,
            'assigned_ips' => $assignedIps,
            'dedicated_ip' => $provisionData['dedicated_ip'] ?? null,
            'nat_remote_host' => $provisionData['nat_remote_host'] ?? null,
            'nat_remote_address' => $provisionData['nat_remote_address'] ?? null,
            'nat_remote_port' => $provisionData['nat_remote_port'] ?? null,
            'nat_remote_checked_at' => $provisionData['nat_remote_checked_at'] ?? null,
            'username' => $provisionData['username'] ?? null,
            'internal_ip' => $provisionData['internal_ip'] ?? null,
            'os' => $provisionData['os'] ?? null,
            'requested_host' => $provisionData['requested_host'] ?? null,
            'default_service_name' => $provisionData['default_service_name'] ?? null,
            'custom_service_name' => $provisionData['custom_service_name'] ?? null,
            'custom_hostname' => $provisionData['custom_hostname'] ?? null,
            'client_remark' => $provisionData['client_remark'] ?? null,
            'connection_cached_hostname' => $provisionData['connection_cached_hostname'] ?? null,
            'connection_cached_at' => $provisionData['connection_cached_at'] ?? null,
            'has_connection_secret' => trim((string) ($provisionData['connection_secret'] ?? '')) !== '',
        ], static fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodeJson(array $payload): ?string
    {
        if ($payload === []) {
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
     * @return array<string, bool>
     */
    private function hasSecretMap(array $secrets): array
    {
        $map = [];
        foreach ($secrets as $key => $value) {
            if ($value !== null && $value !== '' && $value !== []) {
                $map[$key] = true;
            }
        }

        return $map;
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

    private function serviceExists(int $serviceId): bool
    {
        return DB::table('services')->where('id', $serviceId)->exists();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private function firstNonBlank(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = trim((string) ($payload[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function nullableString(mixed $value, int $maxLength): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized === '' ? null : $this->limit($normalized, $maxLength);
    }

    private function limit(mixed $value, int $maxLength): string
    {
        return mb_substr(trim((string) $value), 0, $maxLength);
    }

    private function normalizeAttemptAction(string $action): string
    {
        $normalized = $this->limit($action, 80);

        return $normalized === '' ? 'provision' : $normalized;
    }

    private function bindingResolver(): PluginBindingResolver
    {
        return $this->bindingResolver ??= new PluginBindingResolver;
    }

    private function upstreamBindingWriter(): UpstreamBindingWriter
    {
        return $this->upstreamBindingWriter ??= app(UpstreamBindingWriter::class);
    }
}
