<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins;

use App\Models\Product;
use App\Models\ProductUpstreamBinding;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\SupplierPluginBinding;
use App\Services\Upstream\ProviderRegistry;
use App\Support\DatabaseSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class PluginBindingResolver
{
    /**
     * @var array<int, object|null>
     */
    private array $productBindingCache = [];

    /**
     * 服务/供应商维度的行级记忆化，仅在"读作用域"内生效。
     *
     * 背景：渲染一行服务时，同一行会被反复问到同样的绑定与快照——实测客户端服务列表
     * 单行要查 8 次 service_upstream_bindings、各 4 次两张快照表（12 行 = 192 次）。
     *
     * 为什么必须限定作用域而不是常驻缓存：本类是容器 singleton，若缓存跨调用存活，
     * "先读（缓存了 null）→ 写入绑定 → 再读"就会拿到写前的旧值。绑定表存在不经
     * ServiceUpstreamBindingWriter 的裸 SQL 写入路径（回填命令、测试夹具），无法靠写入侧
     * 逐一失效兜住。限定在一行的渲染过程内后：渲染期间不会有写入，收益不变（本来就是
     * 每行 1 次查询），脏读风险消除。
     *
     * @var array<int, object|null>
     */
    private array $serviceBindingCache = [];

    /**
     * 键为 "supplierId|providerKey"（providerKey 为空串表示不限定 provider）。
     *
     * @var array<string, object|null>
     */
    private array $supplierBindingCache = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $serviceRuntimeSnapshotCache = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $serviceConnectionSnapshotCache = [];

    /**
     * 读作用域嵌套深度。> 0 时上面四个缓存才生效。
     */
    private int $readScopeDepth = 0;

    /**
     * 在一个"只读作用域"内执行回调：期间同一服务的绑定与快照只查一次，退出时清空。
     *
     * 调用方应当只在确定不会发生写入的读路径上使用（例如列表逐行投影）。
     *
     * @template TReturn
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withReadScope(callable $callback): mixed
    {
        $this->readScopeDepth++;

        try {
            return $callback();
        } finally {
            $this->readScopeDepth--;
            if ($this->readScopeDepth <= 0) {
                $this->readScopeDepth = 0;
                $this->productBindingCache = [];
                $this->serviceBindingCache = [];
                $this->supplierBindingCache = [];
                $this->serviceRuntimeSnapshotCache = [];
                $this->serviceConnectionSnapshotCache = [];
            }
        }
    }

    private function readScopeActive(): bool
    {
        return $this->readScopeDepth > 0;
    }

    public function providerKeyForSupplier(Supplier $supplier): ?string
    {
        $binding = $this->supplierBindingForSupplier($supplier);

        return $this->nullableString($binding->provider_key ?? null);
    }

    public function providerKeyForProduct(Product $product): ?string
    {
        $binding = $this->productBindingForProduct($product);

        return $this->nullableString($binding->provider_key ?? null);
    }

    public function providerKeyForService(Service $service): ?string
    {
        $binding = $this->serviceBinding((int) $service->id);
        if ($binding !== null) {
            return $this->nullableString($binding->provider_key ?? null);
        }

        $service->loadMissing('product');
        if ($service->product instanceof Product) {
            return $this->providerKeyForProduct($service->product);
        }

        return null;
    }

    public function supplierIdForService(Service $service): ?int
    {
        $binding = $this->serviceBinding((int) $service->id);
        $supplierId = (int) (($binding->supplier_id ?? 0) ?: 0);

        return $supplierId > 0 ? $supplierId : null;
    }

    public function supplierForService(Service $service): ?Supplier
    {
        $supplierId = $this->supplierIdForService($service);

        return $supplierId === null ? null : Supplier::query()->find($supplierId);
    }

    public function upstreamServiceIdForService(Service $service): ?string
    {
        $binding = $this->serviceBinding((int) $service->id);

        return $this->nullableString($binding->upstream_service_id ?? null);
    }

    public function productIdForService(Service $service): ?int
    {
        $binding = $this->serviceBinding((int) $service->id);
        $productId = (int) (($binding->product_id ?? 0) ?: 0);

        return $productId > 0 ? $productId : null;
    }

    public function productForService(Service $service): ?Product
    {
        $productId = $this->productIdForService($service);

        return $productId === null ? null : Product::query()->with('supplier')->find($productId);
    }

    public function upstreamProductIdForService(Service $service): ?string
    {
        $binding = $this->serviceBinding((int) $service->id);

        return $this->nullableString($binding->upstream_product_id ?? null);
    }

    public function upstreamProductIdForProduct(Product $product): ?string
    {
        $binding = $this->productBindingForProduct($product);

        return $this->nullableString($binding->upstream_product_id ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function supplierBindingProjection(Supplier $supplier, bool $includeSecrets = false, ?string $providerKey = null): array
    {
        $supplierId = (int) $supplier->id;
        if ($supplierId <= 0) {
            return [];
        }

        $binding = $this->supplierBindingForSupplier($supplier, $providerKey);
        if ($binding === null) {
            return [];
        }

        $config = $this->decodePayload($binding->config_json ?? null);
        $secrets = $this->decryptPayload($binding->secret_json ?? null);
        $resolvedProviderKey = $this->nullableString($binding->provider_key ?? null);
        $providerConfig = $this->providerConfigFromBinding($config, $secrets);
        $apiKey = $this->nullableString($secrets['api_key'] ?? null);
        $hasSecretValues = $this->decodePayload($binding->has_secret_json ?? null);
        $hasSecretValues = array_replace(
            $this->providerSecretPresence($resolvedProviderKey, $providerConfig),
            array_filter($hasSecretValues, static fn (mixed $value): bool => (bool) $value)
        );

        if ($apiKey !== null) {
            $hasSecretValues['api_key'] = true;
        }

        $projection = [
            'id' => (int) $binding->id,
            'supplier_id' => (int) $binding->supplier_id,
            'plugin_id' => (int) $binding->plugin_id,
            'provider_key' => $resolvedProviderKey,
            'environment' => $this->nullableString($binding->environment ?? null) ?? 'production',
            'status' => (int) ($binding->status ?? 0),
            'ticket_delivery_enabled' => (bool) ($binding->ticket_delivery_enabled ?? false),
            'priority' => (int) ($binding->priority ?? 0),
            'base_url' => $this->nullableString($binding->base_url ?? null),
            'account_name' => $this->nullableString($binding->account_name ?? null),
            'has_base_url' => $this->nullableString($binding->base_url ?? null) !== null,
            'has_api_key' => ($hasSecretValues['api_key'] ?? false) === true,
            'web_session_cookie' => $this->nullableString($secrets['web_session_cookie'] ?? null, 4000),
            'provider_config' => $providerConfig,
            'has_secret_values' => $hasSecretValues,
            'last_checked_at' => $this->formatDateTime($binding->last_checked_at ?? null),
            'last_check_status' => $this->nullableString($binding->last_check_status ?? null),
            'last_check_error' => $this->nullableString($binding->last_check_error ?? null),
        ];

        if ($includeSecrets) {
            $projection['api_key'] = $apiKey;
        }

        return array_filter($projection, static fn (mixed $value): bool => $value !== null);
    }

    public function supplierWithRuntimeCredentials(Supplier $supplier, bool $includeSecrets = true, ?string $providerKey = null): Supplier
    {
        $projection = $this->supplierBindingProjection($supplier, $includeSecrets, $providerKey);
        if ($projection === []) {
            return $supplier;
        }

        $supplier->setAttribute('provider_key', $projection['provider_key'] ?? null);
        $supplier->setAttribute('api_url', $projection['base_url'] ?? null);
        $supplier->setAttribute('api_username', $projection['account_name'] ?? null);
        $supplier->setAttribute('provider_config', (array) ($projection['provider_config'] ?? []));

        if ($includeSecrets) {
            $supplier->setAttribute('api_key', $projection['api_key'] ?? null);
            $supplier->setAttribute('web_session_cookie', $projection['web_session_cookie'] ?? null);
        }

        return $supplier;
    }

    public function supplierIdForProduct(Product $product): ?int
    {
        return $this->supplierIdFromProductBinding($this->productBindingForProduct($product));
    }

    public function supplierForProduct(Product $product): ?Supplier
    {
        $binding = $this->productBindingForProduct($product);
        if ($binding instanceof ProductUpstreamBinding) {
            $supplierBinding = $binding->relationLoaded('supplierPluginBinding')
                ? $binding->supplierPluginBinding
                : null;

            if (
                $supplierBinding instanceof SupplierPluginBinding
                && $supplierBinding->relationLoaded('supplier')
                && $supplierBinding->supplier instanceof Supplier
            ) {
                return $supplierBinding->supplier;
            }
        }

        $supplierId = $this->supplierIdFromProductBinding($binding);

        return $supplierId === null ? null : Supplier::query()->find($supplierId);
    }

    public function productIdForSupplierAndUpstreamProduct(int $supplierId, mixed $upstreamProductId): ?int
    {
        $upstreamProductKey = $this->nullableString($upstreamProductId);
        if ($supplierId <= 0 || $upstreamProductKey === null || ! $this->hasTable('supplier_plugin_bindings') || ! $this->hasTable('product_upstream_bindings')) {
            return null;
        }

        $row = DB::table('product_upstream_bindings as pub')
            ->join('supplier_plugin_bindings as spb', 'spb.id', '=', 'pub.supplier_plugin_binding_id')
            ->where('spb.supplier_id', $supplierId)
            ->where('pub.upstream_product_id', $upstreamProductKey)
            ->orderByDesc('pub.status')
            ->orderByDesc('pub.id')
            ->first(['pub.product_id']);

        $productId = (int) (($row->product_id ?? 0) ?: 0);

        return $productId > 0 ? $productId : null;
    }

    /**
     * Build a provision-data-like projection from the normalized service binding
     * tables. This lets runtime code prefer the new schema while older call sites
     * are migrated away from services.provision_data one by one.
     *
     * @return array<string, mixed>
     */
    public function serviceProvisionProjection(Service $service, bool $includeSecrets = false): array
    {
        $serviceId = (int) $service->id;
        if ($serviceId <= 0) {
            return [];
        }

        $binding = $this->serviceBinding($serviceId);
        $runtime = $this->serviceRuntimeSnapshot($serviceId);
        $connection = $this->serviceConnectionSnapshot($serviceId, includeSecrets: $includeSecrets);

        $projection = [];

        if ($binding !== null) {
            $projection = array_replace($projection, [
                'provider_key' => $this->nullableString($binding->provider_key ?? null),
                'supplier_id' => $this->positiveInt($binding->supplier_id ?? null),
                'upstream_product_id' => $this->nullableString($binding->upstream_product_id ?? null),
                'upstream_host_id' => $this->nullableString($binding->upstream_service_id ?? null),
                'upstream_account_id' => $this->nullableString($binding->upstream_account_id ?? null),
                'upstream_status' => $this->nullableString($binding->status_snapshot ?? null),
                'last_synced_at' => $this->formatDateTime($binding->last_synced_at ?? null),
                'status_sync_error' => $this->nullableString($binding->last_sync_error ?? null),
            ]);

            $runtimeSnapshot = $this->decodePayload($binding->runtime_snapshot_json ?? null);
            $connectionSnapshot = $this->decodePayload($binding->connection_snapshot_json ?? null);
            $projection = array_replace($projection, $runtimeSnapshot, $connectionSnapshot);
        }

        if ($runtime !== []) {
            $projection = array_replace($projection, $runtime);
        }

        if ($connection !== []) {
            $projection = array_replace($projection, $connection);
        }

        return array_filter($projection, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * Remove credentials that belong in the encrypted binding snapshot, not in
     * the legacy services.provision_data JSON column.
     *
     * @param  array<string, mixed>  $provisionData
     * @return array<string, mixed>
     */
    public function sanitizeServiceProvisionData(array $provisionData): array
    {
        unset($provisionData['downstream_token'], $provisionData['ticket_callback_token']);

        return $provisionData;
    }

    /**
     * 缓存键必须带上 $providerKey。
     *
     * 只按 supplier_id 缓存会串味：同一供应商可同时绑定多个 provider（见
     * supplier_plugin_bindings 的 provider_key 列），先查 zjmf_finance 再查 kanghostx
     * 会拿到前者缓存的结果。null 与空串都表示「不限定 provider」，归一到同一个键。
     */
    private function supplierBinding(int $supplierId, ?string $providerKey = null): ?object
    {
        $normalizedProviderKey = trim((string) $providerKey);

        if (! $this->readScopeActive()) {
            return $this->fetchSupplierBinding($supplierId, $normalizedProviderKey);
        }

        $cacheKey = $supplierId.'|'.$normalizedProviderKey;

        if (array_key_exists($cacheKey, $this->supplierBindingCache)) {
            return $this->supplierBindingCache[$cacheKey];
        }

        return $this->supplierBindingCache[$cacheKey] = $this->fetchSupplierBinding($supplierId, $normalizedProviderKey);
    }

    private function fetchSupplierBinding(int $supplierId, string $providerKey = ''): ?object
    {
        if ($supplierId <= 0 || ! $this->hasTable('supplier_plugin_bindings')) {
            return null;
        }

        return DB::table('supplier_plugin_bindings')
            ->where('supplier_id', $supplierId)
            ->when($providerKey !== '', fn ($query) => $query->where('provider_key', $providerKey))
            ->orderByDesc('status')
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->first();
    }

    private function supplierBindingForSupplier(Supplier $supplier, ?string $providerKey = null): ?object
    {
        if ($providerKey !== null && trim($providerKey) !== '') {
            return $this->supplierBinding((int) $supplier->id, $providerKey);
        }

        if ($supplier->relationLoaded('pluginBindings')) {
            $binding = $this->supplierBindingFromLoadedRelation($supplier);
            if ($binding !== null) {
                return $binding;
            }
        }

        return $this->supplierBinding((int) $supplier->id);
    }

    private function supplierBindingFromLoadedRelation(Supplier $supplier): ?object
    {
        $bindings = $supplier->getRelation('pluginBindings');
        if (! $bindings instanceof Collection || $bindings->isEmpty()) {
            return null;
        }

        return $bindings
            ->sort(function (object $left, object $right): int {
                return [
                    (int) ($right->status ?? 0),
                    (int) ($right->priority ?? 0),
                    (int) ($right->id ?? 0),
                ] <=> [
                    (int) ($left->status ?? 0),
                    (int) ($left->priority ?? 0),
                    (int) ($left->id ?? 0),
                ];
            })
            ->first();
    }

    private function productBindingForProduct(Product $product): ?object
    {
        if ($product->relationLoaded('upstreamBindings')) {
            return $this->productBindingFromLoadedRelation($product);
        }

        return $this->productBinding((int) $product->id);
    }

    private function productBindingFromLoadedRelation(Product $product): ?object
    {
        $bindings = $product->getRelation('upstreamBindings');
        if (! $bindings instanceof Collection || $bindings->isEmpty()) {
            return null;
        }

        $binding = $bindings
            ->sort(function (object $left, object $right): int {
                return [
                    (int) ($right->status ?? 0),
                    (int) ($right->id ?? 0),
                ] <=> [
                    (int) ($left->status ?? 0),
                    (int) ($left->id ?? 0),
                ];
            })
            ->first();

        if (
            $binding instanceof ProductUpstreamBinding
            && $binding->relationLoaded('supplierPluginBinding')
            && $binding->supplierPluginBinding instanceof SupplierPluginBinding
        ) {
            $binding->setAttribute('supplier_id', (int) $binding->supplierPluginBinding->supplier_id);
        }

        return $binding;
    }

    private function productBinding(int $productId): ?object
    {
        if ($productId <= 0 || ! $this->hasTable('product_upstream_bindings')) {
            return null;
        }

        // 与 serviceBinding 等同一策略：只在只读作用域内缓存。
        // 本类改为容器 singleton 后，这份原本形同虚设的缓存（此前每个调用点都 new 新实例）
        // 会变成长命缓存；而 product_upstream_bindings 有 10 个写入方，其中多数是裸 SQL
        // （回填命令、迁移服务、绑定写入器），逐个挂失效钩子挂不全，漏一个就会让长驻进程
        // 拿着旧的供应商/上游商品去开通或续费。限定作用域可从根上消除这类脏读。
        if ($this->readScopeActive() && array_key_exists($productId, $this->productBindingCache)) {
            return $this->productBindingCache[$productId];
        }

        $query = DB::table('product_upstream_bindings as pub')
            ->where('pub.product_id', $productId)
            ->orderByDesc('pub.status')
            ->orderByDesc('pub.id');

        $columns = ['pub.*'];
        if ($this->hasTable('supplier_plugin_bindings')) {
            $query->leftJoin('supplier_plugin_bindings as spb', 'spb.id', '=', 'pub.supplier_plugin_binding_id');
            $columns[] = 'spb.supplier_id';
        }

        $binding = $query->first($columns);

        if ($this->readScopeActive()) {
            $this->productBindingCache[$productId] = $binding;
        }

        return $binding;
    }

    private function supplierIdFromProductBinding(?object $binding): ?int
    {
        $supplierId = (int) (($binding->supplier_id ?? 0) ?: 0);

        return $supplierId > 0 ? $supplierId : null;
    }

    private function serviceBinding(int $serviceId): ?object
    {
        if (! $this->readScopeActive()) {
            return $this->fetchServiceBinding($serviceId);
        }

        if (array_key_exists($serviceId, $this->serviceBindingCache)) {
            return $this->serviceBindingCache[$serviceId];
        }

        return $this->serviceBindingCache[$serviceId] = $this->fetchServiceBinding($serviceId);
    }

    private function fetchServiceBinding(int $serviceId): ?object
    {
        if ($serviceId <= 0 || ! $this->hasTable('service_upstream_bindings')) {
            return null;
        }

        return DB::table('service_upstream_bindings as sub')
            ->leftJoin('supplier_plugin_bindings as spb', 'spb.id', '=', 'sub.supplier_plugin_binding_id')
            ->leftJoin('product_upstream_bindings as pub', 'pub.id', '=', 'sub.product_upstream_binding_id')
            ->leftJoin('supplier_plugin_bindings as pub_spb', 'pub_spb.id', '=', 'pub.supplier_plugin_binding_id')
            ->where('sub.service_id', $serviceId)
            ->orderByDesc('sub.id')
            ->first([
                'sub.id',
                'sub.provider_key',
                'sub.upstream_service_id',
                'sub.product_upstream_binding_id',
                'sub.supplier_plugin_binding_id',
                'sub.plugin_id',
                'sub.upstream_account_id',
                'sub.runtime_snapshot_json',
                'sub.connection_snapshot_json',
                'sub.status_snapshot',
                'sub.last_synced_at',
                'sub.last_sync_error',
                DB::raw('COALESCE(spb.supplier_id, pub_spb.supplier_id) as supplier_id'),
                'pub.product_id',
                'pub.upstream_product_id',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceRuntimeSnapshot(int $serviceId): array
    {
        if (! $this->readScopeActive()) {
            return $this->fetchServiceRuntimeSnapshot($serviceId);
        }

        if (array_key_exists($serviceId, $this->serviceRuntimeSnapshotCache)) {
            return $this->serviceRuntimeSnapshotCache[$serviceId];
        }

        return $this->serviceRuntimeSnapshotCache[$serviceId] = $this->fetchServiceRuntimeSnapshot($serviceId);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchServiceRuntimeSnapshot(int $serviceId): array
    {
        if ($serviceId <= 0 || ! $this->hasTable('service_runtime_snapshots')) {
            return [];
        }

        $snapshot = DB::table('service_runtime_snapshots')
            ->where('service_id', $serviceId)
            ->orderByDesc('id')
            ->first([
                'provider_key',
                'status_key',
                'status_text',
                'resource_json',
                'metrics_json',
                'snapshot_json',
                'synced_at',
            ]);

        if ($snapshot === null) {
            return [];
        }

        $resource = $this->decodePayload($snapshot->resource_json ?? null);
        $metrics = $this->decodePayload($snapshot->metrics_json ?? null);
        $rawSnapshot = $this->decodePayload($snapshot->snapshot_json ?? null);

        return array_filter(array_replace($rawSnapshot, $resource, $metrics, [
            'provider_key' => $this->nullableString($snapshot->provider_key ?? null),
            'upstream_status' => $this->nullableString($snapshot->status_key ?? null),
            'runtime_status' => $this->nullableString($snapshot->status_key ?? null),
            'runtime_description' => $this->nullableString($snapshot->status_text ?? null),
            'last_synced_at' => $this->formatDateTime($snapshot->synced_at ?? null),
            'last_status_sync_at' => $this->formatDateTime($snapshot->synced_at ?? null),
        ]), static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceConnectionSnapshot(int $serviceId, bool $includeSecrets = false): array
    {
        if (! $this->readScopeActive()) {
            return $this->fetchServiceConnectionSnapshot($serviceId, $includeSecrets);
        }

        // includeSecrets 必须进缓存键：脱敏与含密版本是两份不同结果，混用会造成密钥意外外泄或缺失
        $cacheKey = $serviceId.':'.($includeSecrets ? '1' : '0');
        if (array_key_exists($cacheKey, $this->serviceConnectionSnapshotCache)) {
            return $this->serviceConnectionSnapshotCache[$cacheKey];
        }

        return $this->serviceConnectionSnapshotCache[$cacheKey] = $this->fetchServiceConnectionSnapshot($serviceId, $includeSecrets);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchServiceConnectionSnapshot(int $serviceId, bool $includeSecrets = false): array
    {
        if ($serviceId <= 0 || ! $this->hasTable('service_connection_snapshots')) {
            return [];
        }

        $snapshot = DB::table('service_connection_snapshots')
            ->where('service_id', $serviceId)
            ->where('connection_type', 'default')
            ->orderByDesc('id')
            ->first([
                'provider_key',
                'hostname',
                'ip_address',
                'port',
                'connection_json',
                'secret_json',
                'checked_at',
            ]);

        if ($snapshot === null) {
            return [];
        }

        $connection = $this->decodePayload($snapshot->connection_json ?? null);
        $connection = array_replace($connection, [
            'provider_key' => $this->nullableString($snapshot->provider_key ?? null),
            'connection_cached_hostname' => $this->nullableString($snapshot->hostname ?? null),
            'dedicated_ip' => $this->nullableString($snapshot->ip_address ?? null),
            'nat_remote_port' => $this->positiveInt($snapshot->port ?? null),
            'connection_cached_at' => $this->formatDateTime($snapshot->checked_at ?? null),
        ]);

        if ($includeSecrets) {
            $secrets = $this->decryptPayload($snapshot->secret_json ?? null);
            $connection = array_replace($connection, [
                'connection_secret' => $this->nullableString($secrets['connection_secret'] ?? null),
                'password' => $this->nullableString($secrets['password'] ?? null),
                'downstream_token' => $this->nullableString($secrets['downstream_token'] ?? null, 512),
                'ticket_callback_token' => $this->nullableString($secrets['ticket_callback_token'] ?? null, 512),
            ]);
        }

        return array_filter($connection, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $secrets
     * @return array<string, mixed>
     */
    private function providerConfigFromBinding(array $config, array $secrets): array
    {
        $providerConfig = [];

        if (is_array($config['provider_config'] ?? null)) {
            $providerConfig = array_replace($providerConfig, $config['provider_config']);
        }

        if (is_array($secrets['provider_config'] ?? null)) {
            $providerConfig = array_replace($providerConfig, $secrets['provider_config']);
        }

        return array_filter($providerConfig, static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     * @return array<string, bool>
     */
    private function providerSecretPresence(?string $providerKey, array $providerConfig): array
    {
        $presence = [];

        $descriptor = app(ProviderRegistry::class)->descriptor($providerKey);
        foreach ((array) ($descriptor?->supplierForm['fields'] ?? []) as $field) {
            if (! is_array($field) || ! (bool) ($field['secret'] ?? false)) {
                continue;
            }

            $key = trim((string) ($field['key'] ?? ''));
            if ($key === '' || $key === 'api_key') {
                continue;
            }

            if ($this->nullableString($providerConfig[$key] ?? null) !== null) {
                $presence[$key] = true;
            }
        }

        return $presence;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(mixed $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (is_string($payload) && trim($payload) !== '') {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @return array<string, mixed>
     */
    private function decryptPayload(mixed $payload): array
    {
        $encrypted = trim((string) ($payload ?? ''));
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
     * 失效某个服务的行级缓存。
     *
     * 本类现为容器 singleton，缓存跨请求/跨任务存活。写入绑定或快照后必须调用本方法，
     * 否则长驻进程（队列 Worker、状态同步命令）会继续读到写前的旧投影。
     */
    public function forgetService(int $serviceId): void
    {
        unset(
            $this->serviceBindingCache[$serviceId],
            $this->serviceRuntimeSnapshotCache[$serviceId],
            $this->serviceConnectionSnapshotCache[$serviceId.':0'],
            $this->serviceConnectionSnapshotCache[$serviceId.':1'],
        );
    }

    /**
     * 清空全部行级缓存（供测试与长任务分批之间使用）。
     */
    public function flushCaches(): void
    {
        $this->productBindingCache = [];
        $this->serviceBindingCache = [];
        $this->supplierBindingCache = [];
        $this->serviceRuntimeSnapshotCache = [];
        $this->serviceConnectionSnapshotCache = [];
    }

    private function hasTable(string $table): bool
    {
        try {
            return DatabaseSchema::hasTableOrView($table);
        } catch (\Throwable) {
            return false;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }

    private function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $integer = (int) $value;

        return $integer > 0 ? $integer : null;
    }

    private function formatDateTime(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : null;
    }
}
