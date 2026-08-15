<?php

declare(strict_types=1);

namespace App\Services\Admin\V2;

use App\Exceptions\BusinessException;
use App\Http\Requests\Admin\V2\IntegrationPlugin\RunIntegrationPluginTaskRequest;
use App\Http\Resources\Admin\V2\AdminIntegrationPluginDetailResource;
use App\Http\Resources\Admin\V2\AdminIntegrationPluginSchemaFieldResource;
use App\Http\Resources\Admin\V2\AdminIntegrationPluginSummaryResource;
use App\Http\Resources\Admin\V2\AdminSettingResource;
use App\Http\Resources\Admin\V2\AdminSupplierResource;
use App\Models\AdminUser;
use App\Models\IntegrationPlugin;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Integrations\Plugins\IntegrationPluginService;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Integrations\Plugins\PluginRuntimeRegistry;
use App\Services\Integrations\Plugins\SupplierPluginCardRenderer;
use App\Services\Integrations\Plugins\UpstreamBindingWriter;
use App\Services\ProductCatalog\ProductDisplayNameResolver;
use App\Services\System\SettingService;
use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\Contracts\ProvidesRenewal;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AdminConfigurationV2QueryService
{
    public function __construct(
        private readonly IntegrationPluginService $pluginService,
        private readonly SettingService $settingService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function plugins(?string $domain, int $page, int $pageSize): array
    {
        $items = collect($this->pluginService->list($domain))->values();

        return $this->arrayPage(
            $items,
            $page,
            $pageSize,
            fn (array $item): array => AdminIntegrationPluginSummaryResource::make($item)->resolve()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function pluginDetail(IntegrationPlugin $plugin): array
    {
        return [
            'plugin' => AdminIntegrationPluginDetailResource::make($this->pluginService->detail($plugin))->resolve(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pluginSchema(IntegrationPlugin $plugin): array
    {
        $detail = $this->pluginService->detail($plugin);
        $schema = is_array($detail['config_schema'] ?? null) ? $detail['config_schema'] : [];

        return [
            'plugin_id' => (int) $plugin->id,
            'domain' => (string) $plugin->domain,
            'slug' => (string) $plugin->slug,
            'schema' => AdminIntegrationPluginSchemaFieldResource::collection($schema)->resolve(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function pluginSecret(IntegrationPlugin $plugin, string $key): array
    {
        return $this->pluginService->revealConfigSecret($plugin, $key);
    }

    /**
     * @return array<string, mixed>
     */
    public function scanPlugins(?string $domain): array
    {
        $items = $this->pluginService->list($domain);

        return $this->actionResult('integration-plugin-scan', 'completed', '插件目录扫描完成', [
            'domain' => $domain,
            'total' => count($items),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function installPlugin(string $domain, string $slug): array
    {
        return [
            'plugin' => AdminIntegrationPluginDetailResource::make(
                $this->pluginService->install($domain, $slug)
            )->resolve(),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function updatePluginConfig(IntegrationPlugin $plugin, array $config, ?AdminUser $admin): array
    {
        return [
            'plugin' => AdminIntegrationPluginDetailResource::make(
                $this->pluginService->updateConfig($plugin, $config, $admin)
            )->resolve(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function uninstallPlugin(IntegrationPlugin $plugin, bool $force = false): array
    {
        $pluginId = (int) $plugin->id;
        $result = $this->pluginService->uninstall($plugin, $force);

        return $this->actionResult($pluginId, 'deleted', '插件已删除', [
            'deleted' => (bool) ($result['deleted'] ?? false),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function updatePluginStatus(IntegrationPlugin $plugin, bool $enabled): array
    {
        $detail = $enabled
            ? $this->pluginService->enable($plugin)
            : $this->pluginService->disable($plugin);

        return $this->actionResult((int) $plugin->id, $enabled ? 'enabled' : 'disabled', $enabled ? '插件已启用' : '插件已停用', [
            'plugin' => AdminIntegrationPluginSummaryResource::make($detail)->resolve(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function runPluginTask(IntegrationPlugin $plugin, string $type, array $payload): array
    {
        $result = match ($type) {
            RunIntegrationPluginTaskRequest::TYPE_HEALTH_CHECK => $this->pluginService->healthCheck($plugin),
            RunIntegrationPluginTaskRequest::TYPE_TEST_EMAIL => $this->pluginService->testEmail($plugin, $payload),
            RunIntegrationPluginTaskRequest::TYPE_TEST_SMS => $this->pluginService->testSms($plugin, $payload),
            default => throw new BusinessException('不支持的插件任务', 42200),
        };

        return $this->actionResult((int) $plugin->id, 'completed', $this->pluginTaskMessage($type, $result), [
            'type' => $type,
            'result' => $this->compactPluginTaskResult($result),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function suppliers(array $filters, int $pageSize): array
    {
        $query = Supplier::query();
        if (Schema::hasTable('supplier_plugin_bindings')) {
            $query->with('pluginBindings');
        }

        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(function ($builder) use ($keyword): void {
                $builder
                    ->where('name', 'like', "%{$keyword}%")
                    ->orWhere('code', 'like', "%{$keyword}%");
            });
        }

        $status = $filters['status'] ?? null;
        if ($status !== null && $status !== '') {
            $query->where('status', (int) $status);
        }

        $paginator = $query
            ->orderByDesc('status')
            ->orderBy('sort_order')
            ->orderByDesc('id')
            ->paginate($pageSize);

        return [
            'list' => AdminSupplierResource::collection($paginator->items())->resolve(),
            'total' => $paginator->total(),
            'page' => $paginator->currentPage(),
            'page_size' => $paginator->perPage(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function supplierDetail(Supplier $supplier): array
    {
        return [
            'supplier' => AdminSupplierResource::make($supplier)->resolve(),
        ];
    }

    /**
     * @param  array<string, mixed>  $supplierPayload
     * @param  array<string, mixed>  $bindingPayload
     * @return array<string, mixed>
     */
    public function createSupplier(array $supplierPayload, array $bindingPayload): array
    {
        $supplierPayload['code'] = $this->generateSupplierInternalCode((string) ($bindingPayload['provider_key'] ?? ''));

        $supplier = DB::transaction(function () use ($supplierPayload, $bindingPayload): Supplier {
            $supplier = Supplier::query()->create($supplierPayload);
            app(UpstreamBindingWriter::class)->syncSupplierBinding($supplier, $bindingPayload);

            return $supplier;
        });

        return [
            'supplier' => AdminSupplierResource::make($supplier->refresh())->resolve(),
        ];
    }

    /**
     * @param  array<string, mixed>  $supplierPayload
     * @param  array<string, mixed>  $bindingPayload
     * @return array<string, mixed>
     */
    public function updateSupplier(Supplier $supplier, array $supplierPayload, array $bindingPayload): array
    {
        $supplierPayload['code'] = $supplier->code ?: $this->generateSupplierInternalCode(
            (string) ($bindingPayload['provider_key'] ?? ''),
            (int) $supplier->id
        );

        $updated = DB::transaction(function () use ($supplier, $supplierPayload, $bindingPayload): bool {
            $updated = $supplier->update($supplierPayload);
            app(UpstreamBindingWriter::class)->syncSupplierBinding($supplier->refresh(), $bindingPayload);

            return $updated;
        });

        if (! $updated) {
            throw new BusinessException('接口更新失败', 50000, 500);
        }

        return [
            'supplier' => AdminSupplierResource::make($supplier->refresh())->resolve(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteSupplier(Supplier $supplier): array
    {
        $supplierId = (int) $supplier->id;

        return DB::transaction(function () use ($supplierId): array {
            $lockedSupplier = Supplier::query()
                ->lockForUpdate()
                ->findOrFail($supplierId);
            $bindingIds = $this->supplierPluginBindingIds($supplierId, lockForUpdate: true);
            $usage = $this->supplierUsageSummary($lockedSupplier, $bindingIds);

            if (($usage['total'] ?? 0) > 0) {
                throw new BusinessException('供应商已被商品或服务引用，请先解绑或停用', 40900, 409);
            }

            if ($bindingIds !== []) {
                DB::table('supplier_plugin_bindings')
                    ->whereIn('id', $bindingIds)
                    ->delete();
            }

            $lockedSupplier->delete();

            return $this->actionResult($supplierId, 'deleted', '删除成功');
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function supplierBalance(Supplier $supplier): array
    {
        if (! $this->canQueryProvider($supplier)) {
            throw new BusinessException('接口配置不完整，暂时无法查询余额', 42200);
        }

        try {
            $runtimeSupplier = $this->supplierWithRuntimeCredentials($supplier);
            $provider = app(ProviderResolver::class)->resolveForSupplier($runtimeSupplier);
            $renewal = $provider->require(ProvidesRenewal::class, '当前供应商暂不支持余额查询');
            $result = $renewal->getBalance($runtimeSupplier);
        } catch (BusinessException $exception) {
            $this->recordSupplierConnectionCheck($supplier, 'failed', $exception->getMessage());

            throw $exception;
        }

        $resultPayload = is_array($result['data'] ?? null) ? array_replace($result, $result['data']) : $result;
        $connectionStatus = $this->normalizeSupplierConnectionStatus($resultPayload['connection_status'] ?? null);
        $this->recordSupplierConnectionCheck(
            $supplier,
            $connectionStatus === 'failed' ? 'failed' : 'success',
            $connectionStatus === 'failed' ? (string) ($resultPayload['connection_message'] ?? '') : null
        );

        return [
            'supplier_id' => (int) $supplier->id,
            'supplier_name' => (string) $supplier->name,
            'balance' => (string) ($resultPayload['balance'] ?? '0.00'),
            'client' => is_array($resultPayload['client'] ?? null) ? $resultPayload['client'] : [],
            'connection_status' => $resultPayload['connection_status'] ?? null,
            'connection_message' => $resultPayload['connection_message'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function supplierProducts(Supplier $supplier): array
    {
        if (! $this->canQueryProvider($supplier)) {
            throw new BusinessException('接口配置不完整，暂时无法同步供应商商品', 42200);
        }

        $runtimeSupplier = $this->supplierWithRuntimeCredentials($supplier);
        $provider = app(ProviderResolver::class)->resolveForSupplier($runtimeSupplier);
        $catalogCapability = $provider->require(ProvidesConsoleCatalog::class, '当前供应商暂不支持商品同步');
        $catalog = $this->appendLocalProductMappings($supplier, $catalogCapability->getProductCatalog($runtimeSupplier));

        return [
            'supplier_id' => (int) $supplier->id,
            'supplier_name' => (string) $supplier->name,
            'groups' => is_array($catalog['groups'] ?? null) ? $catalog['groups'] : [],
            'products' => is_array($catalog['products'] ?? null) ? $catalog['products'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function supplierProductConfigTemplate(Supplier $supplier, int $productId): array
    {
        if (! $this->canQueryProvider($supplier)) {
            throw new BusinessException('接口配置不完整，暂时无法拉取商品配置', 42200);
        }

        $runtimeSupplier = $this->supplierWithRuntimeCredentials($supplier);
        $provider = app(ProviderResolver::class)->resolveForSupplier($runtimeSupplier);
        $catalogCapability = $provider->require(ProvidesConsoleCatalog::class, '当前供应商暂不支持拉取商品配置');
        $template = $catalogCapability->getProductConfigTemplate($runtimeSupplier, $productId);

        return [
            'supplier_id' => (int) $supplier->id,
            'supplier_name' => (string) $supplier->name,
            'upstream_product_id' => $productId,
            'product' => is_array($template['product'] ?? null) ? $template['product'] : [],
            'config_options' => is_array($template['config_options'] ?? null) ? $template['config_options'] : [],
            'auto_filled_fields' => is_array($template['auto_filled_fields'] ?? null) ? $template['auto_filled_fields'] : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function supplierSecret(Supplier $supplier, string $key): array
    {
        $secretKey = trim($key);
        $binding = app(PluginBindingResolver::class)->supplierBindingProjection($supplier, includeSecrets: true);

        if ($secretKey === 'api_key') {
            $value = trim((string) ($binding['api_key'] ?? ''));
            if ($value === '') {
                throw new BusinessException('接口密钥尚未配置', 42200);
            }

            return ['key' => $secretKey, 'value' => $value];
        }

        $providerKey = trim((string) ($binding['provider_key'] ?? ''));
        $descriptor = app(ProviderRegistry::class)->descriptor($providerKey);
        $fields = (array) ($descriptor?->supplierForm['fields'] ?? []);
        $field = collect($fields)->first(function (mixed $item) use ($secretKey): bool {
            return is_array($item)
                && trim((string) ($item['key'] ?? '')) === $secretKey
                && (bool) ($item['secret'] ?? false);
        });

        if (! is_array($field)) {
            throw new BusinessException('敏感字段不存在', 42200);
        }

        $config = (array) ($binding['provider_config'] ?? []);
        $value = trim((string) ($config[$secretKey] ?? ''));
        if ($value === '') {
            throw new BusinessException('敏感字段尚未配置', 42200);
        }

        return ['key' => $secretKey, 'value' => $value];
    }

    /**
     * @return array<string, mixed>
     */
    public function updateSupplierStatus(Supplier $supplier, bool $enabled): array
    {
        $supplier->update(['status' => $enabled ? 1 : 0]);

        return $this->actionResult((int) $supplier->id, $enabled ? 'enabled' : 'disabled', '状态已更新', [
            'supplier' => AdminSupplierResource::make($supplier->refresh())->resolve(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function runSupplierTask(Supplier $supplier, string $type, array $payload): array
    {
        $binding = app(PluginBindingResolver::class)->supplierBindingProjection($supplier, includeSecrets: true);
        $providerKey = trim((string) ($binding['provider_key'] ?? ''));
        if ($providerKey === '') {
            throw new BusinessException('供应商未绑定插件，无法执行插件动作', 42200);
        }

        try {
            $result = app(PluginRuntimeRegistry::class)->execute(
                'upstream',
                $providerKey,
                $type,
                $payload,
                [
                    'supplier' => app(PluginBindingResolver::class)->supplierWithRuntimeCredentials($supplier),
                    'supplier_id' => (int) $supplier->id,
                    'binding_id' => (int) ($binding['id'] ?? 0),
                    'binding' => $binding,
                ]
            );
        } catch (BusinessException $exception) {
            if ($this->isSupplierCardRefreshTask($type)) {
                $this->recordSupplierConnectionCheck($supplier, 'failed', $exception->getMessage());
            }

            throw $exception;
        }

        if (! (bool) ($result['success'] ?? true)) {
            $message = trim((string) ($result['message'] ?? '插件暂不支持该操作'));

            throw new BusinessException($message !== '' ? $message : '插件暂不支持该操作', 42200);
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        if ($this->isSupplierCardRefreshTask($type)) {
            $remote = is_array($data['remote'] ?? null) ? $data['remote'] : [];
            $status = $this->normalizeSupplierConnectionStatus($remote['connection_status'] ?? 'success');
            $this->recordSupplierConnectionCheck(
                $supplier,
                $status === 'failed' ? 'failed' : 'success',
                $status === 'failed' ? (string) ($remote['connection_message'] ?? '') : null
            );

            if (! is_array($data['card'] ?? null)) {
                $data['card'] = app(SupplierPluginCardRenderer::class)->render($supplier->refresh(), [
                    'binding' => app(PluginBindingResolver::class)->supplierBindingProjection($supplier),
                    'remote' => $remote,
                ]);
            }
        }

        $message = trim((string) ($result['message'] ?? '插件操作完成'));

        return $this->actionResult((int) $supplier->id, 'completed', $message !== '' ? $message : '插件操作完成', [
            'type' => $type,
            'result' => $this->compactSupplierTaskResult($data),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(string $group, int $page, int $pageSize): array
    {
        return $this->arrayPage(
            $this->settingService->getGroupSettings($group)->values(),
            $page,
            $pageSize,
            fn (array $item): array => AdminSettingResource::make($item)->resolve()
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function settingSecret(string $group, string $key): array
    {
        return $this->settingService->revealSensitiveSetting($group, $key);
    }

    /**
     * @template TKey of array-key
     * @template TValue
     *
     * @param  Collection<TKey, TValue>  $items
     * @param  callable(TValue): array<string, mixed>  $map
     * @return array<string, mixed>
     */
    private function arrayPage(Collection $items, int $page, int $pageSize, callable $map): array
    {
        $page = max(1, $page);
        $pageSize = max(1, $pageSize);
        $total = $items->count();

        return [
            'list' => $items
                ->slice(($page - 1) * $pageSize, $pageSize)
                ->map($map)
                ->values()
                ->all(),
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function actionResult(int|string $id, string $status, string $message, array $detail = []): array
    {
        return array_filter([
            'id' => $id,
            'status' => $status,
            'message' => $message,
            'detail' => $detail,
        ], static fn (mixed $value): bool => $value !== null && $value !== []);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function pluginTaskMessage(string $type, array $result): string
    {
        $message = trim((string) ($result['message'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        return match ($type) {
            RunIntegrationPluginTaskRequest::TYPE_HEALTH_CHECK => '插件健康检查完成',
            RunIntegrationPluginTaskRequest::TYPE_TEST_EMAIL => '测试邮件发送成功',
            RunIntegrationPluginTaskRequest::TYPE_TEST_SMS => '测试短信发送成功',
            default => '插件任务执行完成',
        };
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function compactPluginTaskResult(array $result): array
    {
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $source = array_replace($data, $result);
        $allowed = [
            'healthy',
            'success',
            'sent',
            'status',
            'message',
            'provider',
            'entry_class',
            'provider_class',
            'trace_id',
        ];

        return array_filter(
            array_intersect_key($source, array_fill_keys($allowed, true)),
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function compactSupplierTaskResult(array $result): array
    {
        $allowed = [
            'card',
            'created_count',
            'updated_count',
            'skipped_count',
            'failed_count',
            'total_count',
        ];

        return array_filter(
            array_intersect_key($result, array_fill_keys($allowed, true)),
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }

    private function isSupplierCardRefreshTask(string $type): bool
    {
        return str_ends_with($type, '.refresh_card');
    }

    private function canQueryProvider(Supplier $supplier): bool
    {
        $binding = $this->supplierBindingProjection($supplier, includeSecrets: true);
        $providerKey = trim((string) ($binding['provider_key'] ?? ''));
        $descriptor = app(ProviderRegistry::class)->descriptor($providerKey);
        $fields = (array) ($descriptor?->supplierForm['fields'] ?? []);
        $providerConfig = (array) ($binding['provider_config'] ?? []);

        foreach ($fields as $field) {
            if (! is_array($field) || ! (bool) ($field['required'] ?? false)) {
                continue;
            }

            $key = trim((string) ($field['key'] ?? ''));
            $value = match ($key) {
                'api_url' => $binding['base_url'] ?? null,
                'api_username' => $binding['account_name'] ?? null,
                'api_key' => $binding['api_key'] ?? null,
                default => $providerConfig[$key] ?? null,
            };

            if (trim((string) ($value ?? '')) === '') {
                return false;
            }
        }

        return $providerKey !== '';
    }

    private function supplierWithRuntimeCredentials(Supplier $supplier): Supplier
    {
        return app(PluginBindingResolver::class)->supplierWithRuntimeCredentials($supplier);
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierBindingProjection(Supplier $supplier, bool $includeSecrets = false): array
    {
        return app(PluginBindingResolver::class)->supplierBindingProjection($supplier, $includeSecrets);
    }

    private function generateSupplierInternalCode(string $interfaceType, ?int $ignoreId = null): string
    {
        $base = trim($interfaceType) !== '' ? trim($interfaceType) : 'interface';
        $code = $base;
        $suffix = 1;

        while (
            Supplier::query()
                ->when($ignoreId, fn ($query) => $query->where('id', '!=', $ignoreId))
                ->where('code', $code)
                ->exists()
        ) {
            $suffix++;
            $code = "{$base}_{$suffix}";
        }

        return $code;
    }

    /**
     * @param  array<string, mixed>  $catalog
     * @return array<string, mixed>
     */
    private function appendLocalProductMappings(Supplier $supplier, array $catalog): array
    {
        $products = is_array($catalog['products'] ?? null) ? $catalog['products'] : [];
        $productIds = collect($products)
            ->map(fn (array $item) => (int) ($item['id'] ?? 0))
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();

        if ($productIds === []) {
            return $catalog;
        }

        $localProducts = $this->localProductsByUpstreamProductIds($supplier, $productIds);

        $mapProduct = function (array $item) use ($localProducts): array {
            $localProduct = $localProducts->get((int) ($item['id'] ?? 0));
            $displayName = $localProduct instanceof Product
                ? trim((string) (app(ProductDisplayNameResolver::class)->resolveForProduct($localProduct)['product_display_name'] ?? ''))
                : '';
            $thirdGroup = $localProduct?->productGroup;
            $secondGroup = $thirdGroup?->secondProductGroup;
            $firstGroup = $secondGroup?->firstProductGroup;
            $groupNameSegments = array_values(array_filter([
                trim((string) ($firstGroup?->name ?? '')),
                trim((string) ($secondGroup?->name ?? '')),
                trim((string) ($thirdGroup?->name ?? '')),
            ], static fn (string $name): bool => $name !== ''));

            return array_merge($item, [
                'local_product_id' => $localProduct instanceof Product ? (int) $localProduct->id : null,
                'local_product_name' => $displayName !== '' ? $displayName : null,
                'local_product_full_path' => $displayName !== '' ? $displayName : null,
                'local_group_path' => $groupNameSegments,
                'is_bound' => $localProduct instanceof Product,
            ]);
        };

        $catalog['products'] = collect($products)
            ->map(fn (array $item): array => $mapProduct($item))
            ->values()
            ->all();

        $catalog['groups'] = collect(is_array($catalog['groups'] ?? null) ? $catalog['groups'] : [])
            ->map(function (array $group) use ($mapProduct): array {
                $group['items'] = array_map($mapProduct, is_array($group['items'] ?? null) ? $group['items'] : []);

                return $group;
            })
            ->values()
            ->all();

        return $catalog;
    }

    /**
     * @param  array<int, int>  $productIds
     * @return Collection<int, Product>
     */
    private function localProductsByUpstreamProductIds(Supplier $supplier, array $productIds): Collection
    {
        $normalizedIds = collect($productIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $bindingProducts = collect();
        $bindingIds = $this->supplierPluginBindingIds((int) $supplier->id);
        if (Schema::hasTable('product_upstream_bindings') && Schema::hasTable('supplier_plugin_bindings')) {
            if ($normalizedIds !== [] && $bindingIds !== []) {
                $bindingProducts = Product::withTrashed()
                    ->with(['productGroup.secondProductGroup.firstProductGroup'])
                    ->select('products.*', 'pub.upstream_product_id as binding_upstream_product_id')
                    ->join('product_upstream_bindings as pub', 'pub.product_id', '=', 'products.id')
                    ->whereIn('pub.supplier_plugin_binding_id', $bindingIds)
                    ->whereIn('pub.upstream_product_id', array_map(static fn (int $id): string => (string) $id, $normalizedIds))
                    ->orderByDesc('pub.status')
                    ->orderByDesc('pub.id')
                    ->get()
                    ->keyBy(fn (Product $product) => (int) $product->getAttribute('binding_upstream_product_id'));
            }
        }

        return $bindingProducts;
    }

    /**
     * @return array<string, int>
     */
    private function supplierUsageSummary(Supplier $supplier, ?array $bindingIds = null): array
    {
        $supplierId = (int) $supplier->id;
        $bindingIds ??= $this->supplierPluginBindingIds($supplierId);
        $productCount = $this->countBoundProducts($bindingIds);
        $serviceCount = $this->countBoundServices($bindingIds);
        $serviceInstanceCount = $this->countBoundServiceInstances($supplierId);

        return [
            'products' => $productCount,
            'services' => $serviceCount,
            'service_instances' => $serviceInstanceCount,
            'total' => $productCount + $serviceCount + $serviceInstanceCount,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function supplierPluginBindingIds(int $supplierId, bool $lockForUpdate = false): array
    {
        if ($supplierId <= 0 || ! Schema::hasTable('supplier_plugin_bindings')) {
            return [];
        }

        $query = DB::table('supplier_plugin_bindings')
            ->where('supplier_id', $supplierId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $bindingIds
     */
    private function countBoundProducts(array $bindingIds): int
    {
        $productIds = collect();

        if ($bindingIds !== [] && Schema::hasTable('product_upstream_bindings')) {
            $productIds = $productIds->merge(
                DB::table('product_upstream_bindings')
                    ->whereIn('supplier_plugin_binding_id', $bindingIds)
                    ->pluck('product_id')
                    ->map(fn ($id) => (int) $id)
            );
        }

        return $productIds
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->count();
    }

    /**
     * @param  array<int, int>  $bindingIds
     */
    private function countBoundServices(array $bindingIds): int
    {
        if ($bindingIds === [] || ! Schema::hasTable('service_upstream_bindings')) {
            return 0;
        }

        return (int) DB::table('service_upstream_bindings')
            ->whereIn('supplier_plugin_binding_id', $bindingIds)
            ->distinct()
            ->count('service_id');
    }

    private function countBoundServiceInstances(int $supplierId): int
    {
        if (! Schema::hasTable('service_instances') || ! Schema::hasColumn('service_instances', 'supplier_id')) {
            return 0;
        }

        return (int) DB::table('service_instances')
            ->where('supplier_id', $supplierId)
            ->count();
    }

    private function recordSupplierConnectionCheck(Supplier $supplier, string $status, ?string $error = null): void
    {
        if (! Schema::hasTable('supplier_plugin_bindings')) {
            return;
        }

        $binding = $this->supplierBindingProjection($supplier);
        $bindingId = (int) ($binding['id'] ?? 0);
        if ($bindingId <= 0) {
            return;
        }

        DB::table('supplier_plugin_bindings')
            ->where('id', $bindingId)
            ->update([
                'last_checked_at' => now(),
                'last_check_status' => $status,
                'last_check_error' => $error === null ? null : Str::limit($error, 500, ''),
                'updated_at' => now(),
            ]);
    }

    private function normalizeSupplierConnectionStatus(mixed $status): string
    {
        return in_array(strtolower(trim((string) $status)), ['failed', 'failure', 'error', 'invalid', 'unhealthy', 'disconnected'], true)
            ? 'failed'
            : 'success';
    }
}
