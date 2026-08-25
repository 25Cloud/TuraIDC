<?php

declare(strict_types=1);

namespace App\Services\ProductCatalog;

use App\Constants\BillingCycle;
use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\ProductType;
use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\FirstProductGroup;
use App\Models\Order;
use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\Supplier;
use App\Models\ThirdProductGroup;
use App\Services\Integrations\Plugins\PluginBindingResolver;
use App\Services\Integrations\Plugins\UpstreamBindingWriter;
use App\Services\ProductCatalog\Concerns\HandlesProductCatalogHelpers;
use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\ProviderResolver;
use App\Support\ProductGroupHierarchyFields;
use App\Support\TextSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProductSyncService
{
    use HandlesProductCatalogHelpers;

    private const IMPORT_PRICING_MONTHS = BillingCycle::RENEWABLE_MONTHS;

    private const REMOTE_STOCK_CACHE_TTL_SECONDS = 15;

    /** 上游商品配置定时同步整体时间预算（秒），需小于任务超时 3600s。 */
    private const UPSTREAM_SYNC_DEADLINE_SECONDS = 2700;

    /** 单个供应商拉取配置的时间预算（秒），防止单个慢供应商拖垮整批。 */
    private const UPSTREAM_SUPPLIER_BUDGET_SECONDS = 240;

    private readonly ProductGroupHierarchyService $hierarchyService;

    /** `products.stock_synced_at` 列存在性缓存，避免逐商品重复查元数据 */
    private ?bool $hasStockSyncedAtColumn = null;

    public function __construct(
        private readonly ProviderResolver $providerResolver,
        ?ProductGroupHierarchyService $hierarchyService = null,
        private readonly ?ProductDisplayNameResolver $productDisplayNameResolver = null,
        private ?UpstreamBindingWriter $upstreamBindingWriter = null,
        private ?PluginBindingResolver $bindingResolver = null,
    ) {
        $this->hierarchyService = $hierarchyService ?? app(ProductGroupHierarchyService::class);
    }

    public function batchSyncProducts(array $data): array
    {
        $productIds = collect($data['product_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        throw_if($productIds->isEmpty(), new BusinessException('请至少选择一个商品'));

        $syncPricing = (int) ($data['sync_pricing'] ?? 0) === 1;
        $syncConfigOptions = (int) ($data['sync_config_options'] ?? 0) === 1;
        $syncConfigPricing = (int) ($data['sync_config_pricing'] ?? 0) === 1;

        throw_if(
            ! $syncPricing && ! $syncConfigOptions && ! $syncConfigPricing,
            new BusinessException('请至少选择一个同步项')
        );

        $products = Product::query()
            ->with(['productGroup.secondProductGroup.firstProductGroup', 'supplier'])
            ->whereIn('id', $productIds->all())
            ->get()
            ->keyBy(fn (Product $product) => (int) $product->id);

        $items = [];
        $validProductsBySupplier = [];

        foreach ($productIds as $productId) {
            $product = $products->get($productId);

            if (! $product instanceof Product) {
                $items[] = $this->buildBatchSyncSkippedItem($productId, null, '商品不存在或已删除');

                continue;
            }

            $supplier = $this->resolveProductSupplier($product);

            if (! $supplier instanceof Supplier) {
                $items[] = $this->buildBatchSyncSkippedItem($productId, $product, '商品未绑定供应商');

                continue;
            }

            if ($this->resolveProductUpstreamProductId($product) <= 0) {
                $items[] = $this->buildBatchSyncSkippedItem($productId, $product, '商品未绑定上游商品');

                continue;
            }

            if (! $this->providerResolver->resolveForSupplier($supplier)->supports(ProvidesConsoleCatalog::class)) {
                $items[] = $this->buildBatchSyncSkippedItem($productId, $product, '当前供应商暂不支持批量同步');

                continue;
            }

            $validProductsBySupplier[(int) $supplier->id][] = $product;
        }

        foreach ($validProductsBySupplier as $supplierProducts) {
            $supplier = $this->resolveProductSupplier($supplierProducts[0]);

            if (! $supplier instanceof Supplier) {
                continue;
            }

            try {
                $catalogProducts = [];
                $remoteConfigOptions = [];
                $catalogCapability = $this->resolveCatalogCapability($supplier);

                if ($syncPricing) {
                    $catalog = $catalogCapability->getProductCatalog($supplier);
                    $catalogProducts = collect($catalog['products'] ?? [])
                        ->filter(fn ($item) => is_array($item) && (int) ($item['id'] ?? 0) > 0)
                        ->keyBy(fn (array $item) => (int) ($item['id'] ?? 0))
                        ->all();
                }

                if ($syncConfigOptions || $syncConfigPricing) {
                    $remoteConfigOptions = $this->prefetchImportedConfigOptions(
                        $supplier,
                        collect($supplierProducts)
                            ->map(fn (Product $product) => $this->resolveProductUpstreamProductId($product))
                            ->filter(fn (int $supplierProductId) => $supplierProductId > 0)
                            ->values()
                            ->all()
                    );
                }

                foreach ($supplierProducts as $product) {
                    $syncResult = $this->syncSingleSupplierProduct(
                        $product,
                        $catalogProducts,
                        $remoteConfigOptions,
                        $syncPricing,
                        $syncConfigOptions,
                        $syncConfigPricing
                    );

                    $items[] = array_merge($syncResult, [
                        'product_id' => (int) $product->id,
                        'product_name' => (string) $product->name,
                        'supplier_id' => (int) $supplier->id,
                        'supplier_name' => (string) ($supplier->name ?? ''),
                    ]);
                }
            } catch (\Throwable $exception) {
                Log::warning('[商品批量同步] 供应商同步失败', [
                    'supplier_id' => $supplier->id,
                    'supplier_name' => $supplier->name,
                    'message' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);

                foreach ($supplierProducts as $product) {
                    $items[] = $this->buildBatchSyncSkippedItem(
                        (int) $product->id,
                        $product,
                        '供应商同步失败：'.$exception->getMessage()
                    );
                }
            }
        }

        $syncedCount = collect($items)->where('status', 'synced')->count();
        $skippedCount = collect($items)->where('status', 'skipped')->count();

        if ($syncedCount > 0) {
            $this->forgetSiteCatalogCache();
        }

        return [
            'requested_count' => $productIds->count(),
            'synced_count' => $syncedCount,
            'skipped_count' => $skippedCount,
            'sync_pricing' => $syncPricing,
            'sync_config_options' => $syncConfigOptions,
            'sync_config_pricing' => $syncConfigPricing,
            'items' => $items,
        ];
    }

    public function bulkConnectSupplierProducts(Supplier $supplier, array $data): array
    {
        $firstGroupCode = trim((string) ($data['first_product_group_code'] ?? ''));
        throw_if($firstGroupCode === '', new BusinessException('请选择所属一级菜单'));

        $supplierProductIds = collect($data['product_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        throw_if($supplierProductIds->isEmpty(), new BusinessException('请选择至少一个上游商品'));

        $catalogCapability = $this->resolveCatalogCapability($supplier);
        $catalog = $catalogCapability->getProductCatalog($supplier);
        $catalogProducts = collect($catalog['products'] ?? [])
            ->filter(fn ($item) => is_array($item) && (int) ($item['id'] ?? 0) > 0)
            ->keyBy(fn (array $item) => (int) ($item['id'] ?? 0));

        // ZJMF 等上游的列表接口不返回价格，价格需要按选中的商品单独补充，
        // 避免批量对接时因缺少可导入价格而全部跳过。
        if (method_exists($catalogCapability, 'hydrateSelectedPricing')) {
            $catalog['products'] = $catalogCapability->hydrateSelectedPricing(
                $supplier,
                $catalog['products'] ?? [],
                $supplierProductIds->all(),
            );
            $catalogProducts = collect($catalog['products'] ?? [])
                ->filter(fn ($item) => is_array($item) && (int) ($item['id'] ?? 0) > 0)
                ->keyBy(fn (array $item) => (int) ($item['id'] ?? 0));
        }

        $existingProducts = $this->findExistingProductsBySupplierUpstreamIds($supplier, $supplierProductIds->all());

        $defaultStatus = (int) ($data['default_status'] ?? 1) === 1 ? 1 : 0;
        $defaultAutoSetup = (int) ($data['default_auto_setup'] ?? 1) === 1 ? 1 : 0;
        $syncConfigOptions = (int) ($data['sync_config_options'] ?? 0) === 1;

        $firstGroup = $this->resolveImportedFirstProductGroup(
            $firstGroupCode,
            (int) ($data['first_product_group_id'] ?? 0)
        );
        $productType = ProductType::businessValueForFirstGroup($firstGroup, $firstGroupCode);
        $targetSecondGroup = $this->resolveImportedSecondProductGroup(
            $firstGroup,
            (int) ($data['second_product_group_id'] ?? 0),
            TextSanitizer::nullable((string) ($data['second_product_group_name'] ?? ''))
        );
        $targetThirdGroup = $this->resolveImportedThirdProductGroup(
            $targetSecondGroup,
            (int) ($data['third_product_group_id'] ?? 0),
            TextSanitizer::nullable((string) ($data['third_product_group_name'] ?? ''))
        ) ?? $this->resolveOrCreateImportedThirdProductGroup($targetSecondGroup, '默认分类');

        $importedProducts = [];
        $skippedItems = [];
        $createdCount = 0;
        $updatedCount = 0;

        foreach ($supplierProductIds as $supplierProductId) {
            $supplierProduct = $catalogProducts->get($supplierProductId);
            if (! is_array($supplierProduct)) {
                $skippedItems[] = $this->buildBulkConnectSkippedItem(
                    $supplierProductId,
                    null,
                    '未找到对应的上游商品'
                );

                continue;
            }

            $pricing = $this->buildImportedPricing($supplierProduct);
            if ($pricing === []) {
                $skippedItems[] = $this->buildBulkConnectSkippedItem(
                    $supplierProductId,
                    $supplierProduct,
                    '上游商品缺少可导入价格'
                );

                continue;
            }

            $targetHierarchy = $this->buildImportedTargetHierarchy(
                $firstGroup,
                $targetSecondGroup,
                $targetThirdGroup
            );

            $configOptions = $this->resolveImportedBatchConfigOptions(
                $supplier,
                $supplierProduct,
                $syncConfigOptions,
                $existingProducts->get($supplierProductId)?->config_options ?? []
            );

            $payload = $this->buildBulkConnectProductPayload(
                $supplier,
                $targetHierarchy,
                $supplierProduct,
                $productType,
                $pricing,
                $defaultStatus,
                $defaultAutoSetup,
                $configOptions
            );

            $localProduct = $existingProducts->get($supplierProductId);
            if ($localProduct instanceof Product) {
                $payload['sort_order'] = (int) ($localProduct->sort_order ?? 0);
                $localProduct = DB::transaction(
                    fn () => $this->persistProductWithStructuredSync($localProduct, $payload)
                );
                $updatedCount++;
                $action = 'updated';
            } else {
                $localProduct = DB::transaction(
                    fn () => $this->createProductWithStructuredSync($payload)
                );
                $createdCount++;
                $action = 'created';
            }

            $importedProducts[] = $this->buildBulkConnectImportedItem(
                $localProduct,
                $supplierProductId,
                $supplierProduct,
                $action
            );
        }

        if (($createdCount + $updatedCount) > 0) {
            $this->forgetSiteCatalogCache();
        }

        return [
            'selected_count' => $supplierProductIds->count(),
            'created_count' => $createdCount,
            'updated_count' => $updatedCount,
            'skipped_count' => count($skippedItems),
            'imported_products' => $importedProducts,
            'skipped_items' => $skippedItems,
        ];
    }

    public function finalizeUpstreamBindings(array $data = []): array
    {
        $productIds = collect(explode(',', (string) ($data['product_ids'] ?? '')))
            ->map(fn ($id) => (int) trim((string) $id))
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values();

        $forceAll = (bool) ($data['force_all'] ?? false);
        $syncConfigOptions = ! (bool) ($data['skip_config'] ?? false);
        $dryRun = (bool) ($data['dry_run'] ?? false);

        $products = Product::query()
            ->when(
                $productIds->isNotEmpty(),
                fn ($query) => $query->whereIn('id', $productIds->all())
            )
            ->tap(fn (Builder $query) => $this->applyHasUpstreamProductBindingScope($query))
            ->orderBy('id')
            ->get();

        $items = [];
        $eligibleProducts = $products->filter(
            fn (Product $product) => $forceAll || $this->productNeedsUpstreamFinalize($product, $syncConfigOptions)
        );

        foreach ($eligibleProducts->groupBy(fn (Product $product) => (int) ($this->resolveProductSupplier($product)?->id ?? 0)) as $supplierProducts) {
            $firstProduct = $supplierProducts->first();
            $supplier = $firstProduct instanceof Product ? $this->resolveProductSupplier($firstProduct) : null;

            if (! $supplier instanceof Supplier) {
                foreach ($supplierProducts as $product) {
                    $items[] = $this->buildFinalizeUpstreamSkippedItem($product, '商品未找到有效供应商');
                }

                continue;
            }

            if (! $this->providerResolver->resolveForSupplier($supplier)->supports(ProvidesConsoleCatalog::class)) {
                foreach ($supplierProducts as $product) {
                    $items[] = $this->buildFinalizeUpstreamSkippedItem($product, '当前供应商接口不支持批量固化');
                }

                continue;
            }

            $remoteConfigOptions = [];

            if ($syncConfigOptions) {
                $remoteConfigOptions = $this->prefetchImportedConfigOptions(
                    $supplier,
                    $supplierProducts
                        ->map(fn (Product $product) => $this->resolveProductUpstreamProductId($product))
                        ->filter(fn (int $supplierProductId) => $supplierProductId > 0)
                        ->values()
                        ->all()
                );
            }

            foreach ($supplierProducts as $product) {
                $payload = [];
                $updatedFields = [];

                if ((int) ($product->auto_setup ?? 0) !== 1) {
                    $payload['auto_setup'] = 1;
                    $updatedFields[] = 'auto_setup';
                }

                if ($syncConfigOptions) {
                    $supplierProductId = $this->resolveProductUpstreamProductId($product);
                    $normalizedRemoteConfigOptions = $this->normalizeImportedConfigOptions(
                        $remoteConfigOptions[$supplierProductId] ?? []
                    );
                    $localConfigOptions = $this->normalizeConfigOptions($product->config_options);

                    if ($normalizedRemoteConfigOptions !== []) {
                        $mergedConfigOptions = $this->mergeConfigOptionsPreservingPricing(
                            $localConfigOptions,
                            $normalizedRemoteConfigOptions
                        );

                        if ($mergedConfigOptions !== $localConfigOptions) {
                            $payload['config_options'] = $mergedConfigOptions;
                            $updatedFields[] = 'config_options';
                        }
                    }
                }

                if ($payload === []) {
                    $items[] = array_merge(
                        $this->buildFinalizeUpstreamSkippedItem($product, '当前商品无需更新'),
                        ['updated_fields' => []]
                    );

                    continue;
                }

                if (! $dryRun) {
                    $product = $this->persistProductWithStructuredSync($product, $payload);
                }

                $items[] = [
                    'status' => 'updated',
                    'product_id' => (int) $product->id,
                    'product_name' => (string) $product->name,
                    'supplier_id' => (int) $supplier->id,
                    'supplier_name' => (string) ($supplier->name ?? ''),
                    'upstream_product_id' => $this->resolveProductUpstreamProductId($product),
                    'updated_fields' => $updatedFields,
                ];
            }
        }

        $updatedCount = collect($items)->where('status', 'updated')->count();
        $skippedCount = collect($items)->where('status', 'skipped')->count();

        return [
            'requested_count' => $productIds->isNotEmpty() ? $productIds->count() : $products->count(),
            'matched_count' => $products->count(),
            'eligible_count' => $eligibleProducts->count(),
            'updated_count' => $updatedCount,
            'skipped_count' => $skippedCount,
            'sync_config_options' => $syncConfigOptions,
            'force_all' => $forceAll,
            'dry_run' => $dryRun,
            'items' => $items,
        ];
    }

    public function syncUpstreamProductConfigOptions(): array
    {
        $summary = [
            'matched_products' => 0,
            'matched_suppliers' => 0,
            'synced_products' => 0,
            'skipped_products' => 0,
            'failed_products' => 0,
        ];

        $products = Product::query()
            ->tap(fn (Builder $query) => $this->applyHasUpstreamProductBindingScope($query))
            ->orderBy('id')
            ->get();

        $summary['matched_products'] = $products->count();

        if ($products->isEmpty()) {
            return $summary;
        }

        // 整体时间预算：上游拉取慢时宁可部分跳过也要保证在任务超时前正常收尾，
        // 避免运行记录被队列超时强杀后永久卡在 running（自愈兜底见 HeartbeatScheduler）。
        $syncDeadline = microtime(true) + self::UPSTREAM_SYNC_DEADLINE_SECONDS;

        $hasChanges = false;

        foreach ($products->groupBy(fn (Product $product) => (int) ($this->resolveProductSupplier($product)?->id ?? 0)) as $supplierProducts) {
            $firstProduct = $supplierProducts->first();
            $supplier = $firstProduct instanceof Product ? $this->resolveProductSupplier($firstProduct) : null;

            if (! $supplier instanceof Supplier) {
                $summary['skipped_products'] += $supplierProducts->count();

                continue;
            }

            if ((int) ($supplier->status ?? 0) !== 1) {
                $summary['skipped_products'] += $supplierProducts->count();

                Log::info('[定时任务] 上游产品配置同步跳过：供应商未启用', [
                    'supplier_id' => $supplier->id,
                    'product_ids' => $supplierProducts->pluck('id')->values()->all(),
                ]);

                continue;
            }

            if (! $this->providerResolver->resolveForSupplier($supplier)->supports(ProvidesConsoleCatalog::class)) {
                $summary['skipped_products'] += $supplierProducts->count();

                continue;
            }

            if (microtime(true) >= $syncDeadline) {
                $summary['skipped_products'] += $supplierProducts->count();

                Log::info('[定时任务] 上游产品配置同步跳过：整体时间预算已耗尽', [
                    'supplier_id' => $supplier->id,
                    'product_ids' => $supplierProducts->pluck('id')->values()->all(),
                ]);

                continue;
            }

            $summary['matched_suppliers']++;

            try {
                $catalogCapability = $this->resolveCatalogCapability($supplier);
                $supplierProductIds = $supplierProducts
                    ->map(fn (Product $product) => $this->resolveProductUpstreamProductId($product))
                    ->filter(fn (int $supplierProductId) => $supplierProductId > 0)
                    ->unique()
                    ->values()
                    ->all();
                // 单个供应商最多占用剩余预算（上限 240s），超时立即停止拉取，避免拖垮后续供应商。
                $supplierDeadline = microtime(true) + min(self::UPSTREAM_SUPPLIER_BUDGET_SECONDS, max(1.0, $syncDeadline - microtime(true)));
                $remoteConfigOptions = $catalogCapability->fetchBatchProductConfigOptions(
                    $supplier,
                    $supplierProductIds,
                    8,
                    $supplierDeadline,
                );
            } catch (\Throwable $exception) {
                $summary['failed_products'] += $supplierProducts->count();

                Log::error('[定时任务] 上游产品配置同步失败：供应商拉取异常', [
                    'supplier_id' => $supplier->id,
                    'supplier_name' => $supplier->name,
                    'product_ids' => $supplierProducts->pluck('id')->values()->all(),
                    'message' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);

                continue;
            }

            foreach ($supplierProducts as $product) {
                $supplierProductId = $this->resolveProductUpstreamProductId($product);
                $normalizedRemoteConfigOptions = $this->normalizeImportedConfigOptions(
                    $remoteConfigOptions[$supplierProductId] ?? []
                );

                if ($normalizedRemoteConfigOptions === []) {
                    $summary['skipped_products']++;

                    continue;
                }

                $localConfigOptions = is_array($product->config_options) ? $product->config_options : [];
                $mergedConfigOptions = $this->mergeConfigOptionsPreservingPricing(
                    $localConfigOptions,
                    $normalizedRemoteConfigOptions
                );

                if ($mergedConfigOptions === $localConfigOptions) {
                    $summary['skipped_products']++;

                    continue;
                }

                $this->persistProductWithStructuredSync($product, [
                    'config_options' => $mergedConfigOptions,
                ]);

                $summary['synced_products']++;
                $hasChanges = true;
            }
        }

        if ($hasChanges) {
            $this->forgetSiteCatalogCache();
        }

        return $summary;
    }

    /** 定时同步时每批之间的等待毫秒数，避免持续高频请求被上游风控拦截 */
    public const STOCK_SYNC_PACING_MS = 500;

    /** 定时同步时每批并发查询的商品数 */
    public const STOCK_SYNC_CHUNK_SIZE = 8;

    /**
     * 批量同步上游库存。
     *
     * 上游库存必须逐商品查询——实测某方 /cart/all 列表里的 stock_control 不可信
     * （24 个抽样中 14 个与商品详情接口不一致，2 个实际售罄却报"不限量"），
     * 所以请求数等于商品数，无法用一次列表请求代替。
     *
     * 为此在批与批之间插入固定等待，把脉冲式的并发打散成平缓的涓流：
     * 473 个商品 ≈ 60 批，每批 8 并发约 1 秒 + 等待 0.5 秒 ≈ 88 秒跑完，
     * 平均约 5 请求/秒，远低于会触发风控的水平。
     *
     * @param  int  $batchSize  单轮最多同步多少个商品，<=0 表示全量
     * @param  int|null  $pacingMs  批间等待毫秒，null 取默认值；实时查询路径不走本方法，不受影响
     */
    public function syncUpstreamProductStocks(
        ?string $providerKey = null,
        int $batchSize = 0,
        ?int $pacingMs = null
    ): array {
        $pacingMs = max(0, $pacingMs ?? self::STOCK_SYNC_PACING_MS);
        $normalizedProviderKey = trim((string) $providerKey);
        $summary = [
            'matched_products' => 0,
            'matched_suppliers' => 0,
            'synced_products' => 0,
            'skipped_products' => 0,
            'failed_products' => 0,
            'batch_size' => $batchSize,
        ];

        $products = Product::query()
            ->tap(fn (Builder $query) => $this->applyHasUpstreamProductBindingScope(
                $query,
                $normalizedProviderKey !== '' ? $normalizedProviderKey : null,
            ))
            ->tap(fn (Builder $query) => $this->applyStockSyncPriority($query, $batchSize))
            ->get();

        $summary['matched_products'] = $products->count();

        if ($products->isEmpty()) {
            return $summary;
        }

        $hasChanges = false;

        foreach ($products->groupBy(fn (Product $product) => (int) ($this->resolveProductSupplier($product)?->id ?? 0)) as $supplierProducts) {
            $firstProduct = $supplierProducts->first();
            $supplier = $firstProduct instanceof Product ? $this->resolveProductSupplier($firstProduct) : null;

            if (! $supplier instanceof Supplier) {
                $summary['skipped_products'] += $supplierProducts->count();

                continue;
            }

            if ((int) ($supplier->status ?? 0) !== 1) {
                $summary['skipped_products'] += $supplierProducts->count();

                Log::info('[定时任务] 上游商品库存同步跳过：供应商未启用', [
                    'supplier_id' => $supplier->id,
                    'product_ids' => $supplierProducts->pluck('id')->values()->all(),
                ]);

                continue;
            }

            if (! $this->providerResolver->resolveForSupplier($supplier)->supports(ProvidesConsoleCatalog::class)) {
                $summary['skipped_products'] += $supplierProducts->count();

                continue;
            }

            $supplierProductIds = $supplierProducts
                ->map(fn (Product $product) => $this->resolveProductUpstreamProductId($product))
                ->filter(fn (int $supplierProductId) => $supplierProductId > 0)
                ->unique()
                ->values()
                ->all();

            if ($supplierProductIds === []) {
                $summary['skipped_products'] += $supplierProducts->count();

                continue;
            }

            $summary['matched_suppliers']++;

            try {
                $remoteStocks = $this->fetchStocksWithPacing($supplier, $supplierProductIds, $pacingMs);
            } catch (\Throwable $exception) {
                $summary['failed_products'] += $supplierProducts->count();

                Log::error('[定时任务] 上游商品库存同步失败：供应商拉取异常', [
                    'supplier_id' => $supplier->id,
                    'supplier_name' => $supplier->name,
                    'product_ids' => $supplierProducts->pluck('id')->values()->all(),
                    'message' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);

                continue;
            }

            foreach ($supplierProducts as $product) {
                $supplierProductId = $this->resolveProductUpstreamProductId($product);
                $remoteStock = array_key_exists($supplierProductId, $remoteStocks)
                    ? $remoteStocks[$supplierProductId]
                    : null;

                if ($remoteStock === null) {
                    $summary['skipped_products']++;

                    continue;
                }

                if ($remoteStock === (int) ($product->stock ?? 0)) {
                    // 值没变也要盖同步时间戳，否则该商品会永远排在轮转队首，
                    // 把后面的商品饿死。
                    $this->touchStockSyncedAt($product);
                    $this->recordProductStockSnapshot($product, $supplier, $supplierProductId, $remoteStock);
                    $summary['skipped_products']++;

                    continue;
                }

                $this->persistProductWithStructuredSync($product, [
                    'stock' => $remoteStock,
                ]);
                $this->touchStockSyncedAt($product);
                $this->recordProductStockSnapshot($product->fresh() ?? $product, $supplier, $supplierProductId, $remoteStock);

                $summary['synced_products']++;
                $hasChanges = true;
            }
        }

        if ($hasChanges) {
            $this->forgetSiteCatalogCache();
        }

        return $summary;
    }

    public function siteProductStock(int $productId): ?array
    {
        $cacheKey = 'site_product_stock:'.$productId;
        $cached = Cache::store('redis_volatile')->get($cacheKey);

        if ($cached !== null) {
            return $cached === false ? null : $cached;
        }

        $product = $this->findSaleProductForStock($productId);

        if (! $product instanceof Product) {
            Cache::store('redis_volatile')->put($cacheKey, false, now()->addSeconds(10));

            return null;
        }

        $product = $this->applyLiveStockToProduct($product->loadMissing('supplier'));

        $result = [
            'product_id' => (int) $product->id,
            'stock' => (int) ($product->live_stock ?? $product->stock),
        ];

        Cache::store('redis_volatile')->put($cacheKey, $result, now()->addSeconds(15));

        return $result;
    }

    public function assertProductCanBeProvisioned(Product $product, int $requiredQuantity = 1): void
    {
        $product = $this->applyLiveStockToProduct($product->loadMissing('supplier'), true);
        $availableStock = (int) ($product->getAttribute('live_stock') ?? $product->stock ?? 0);

        throw_if(
            $availableStock >= 0 && $availableStock < max($requiredQuantity, 1),
            new BusinessException('该商品库存不足，无法继续下单')
        );
    }

    public function applyLiveStockToProduct(Product $product, bool $strict = false): Product
    {
        $products = $this->applyLiveStockToProducts(new Collection([$product]), $strict);

        return $products->first() ?? $product;
    }

    public function applyLiveStockToProducts(Collection $products, bool $strict = false): Collection
    {
        if ($products->isEmpty()) {
            return $products;
        }

        $products->loadMissing('supplier');

        $reservedCounts = $this->queryOpenStockReservations(
            $products->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->filter(fn (int $id) => $id > 0)
                ->values()
                ->all()
        );

        $liveStockMap = [];
        $upstreamProducts = $products->filter(function (Product $product) {
            $supplier = $this->resolveProductSupplier($product);

            return $this->resolveProductUpstreamProductId($product) > 0
                && $supplier instanceof Supplier
                && $this->providerResolver->resolveForSupplier($supplier)->supports(ProvidesConsoleCatalog::class);
        });

        foreach ($upstreamProducts->groupBy(fn (Product $product) => (int) ($this->resolveProductSupplier($product)?->id ?? 0)) as $supplierProducts) {
            $firstProduct = $supplierProducts->first();
            $supplier = $firstProduct instanceof Product ? $this->resolveProductSupplier($firstProduct) : null;

            if (! $supplier instanceof Supplier) {
                continue;
            }

            $supplierProductIds = $supplierProducts
                ->map(fn (Product $product) => $this->resolveProductUpstreamProductId($product))
                ->filter(fn (int $supplierProductId) => $supplierProductId > 0)
                ->values()
                ->all();

            try {
                $remoteStocks = $this->resolveSupplierRemoteStocks($supplier, $supplierProductIds);
            } catch (\Throwable $exception) {
                if ($strict) {
                    throw new BusinessException('暂时无法获取上游库存，请稍后重试');
                }

                $throttleKey = 'stock_log:detail_fail:'.$supplier->id;
                if (! Cache::store('redis_volatile')->has($throttleKey)) {
                    Cache::store('redis_volatile')->put($throttleKey, true, now()->addSeconds(60));
                    Log::warning('[商品库存] 拉取上游明细库存失败', [
                        'supplier_id' => $supplier->id,
                        'supplier_name' => $supplier->name,
                        'message' => $exception->getMessage(),
                        'exception' => $exception::class,
                    ]);
                }

                $remoteStocks = [];
            }

            foreach ($supplierProducts as $product) {
                $supplierProductId = $this->resolveProductUpstreamProductId($product);
                $remoteStock = array_key_exists($supplierProductId, $remoteStocks)
                    ? $remoteStocks[$supplierProductId]
                    : null;

                if ($remoteStock === null) {
                    if ($strict) {
                        throw new BusinessException('未找到上游库存信息，请稍后重试');
                    }

                    $notFoundThrottleKey = 'stock_log:not_found:'.$product->id;
                    if (! Cache::store('redis_volatile')->has($notFoundThrottleKey)) {
                        Cache::store('redis_volatile')->put($notFoundThrottleKey, true, now()->addSeconds(60));
                        Log::warning('[商品库存] 未找到对应上游商品库存', [
                            'product_id' => (int) $product->id,
                            'supplier_id' => (int) $supplier->id,
                            'upstream_product_id' => $supplierProductId,
                        ]);
                    }

                    continue;
                }

                $reservedCount = (int) ($reservedCounts[(int) $product->id] ?? 0);
                $liveStockMap[(int) $product->id] = $this->resolveLiveStockValue(
                    (int) ($product->stock ?? -1),
                    $remoteStock,
                    $reservedCount,
                    true
                );

                // 把这次实时查到的上游库存落库。下单校验与商品详情页本来就在实时查，
                // 却从不保存结果，于是 products.stock 长期停留在导入时的旧值。顺手回写
                // 等于把用户流量变成免费的同步机会：热门商品自动保持新鲜，定时轮转
                // 只需兜底无人问津的冷门商品。
                $this->persistSyncedStock($product, $remoteStock);
            }
        }

        foreach ($products as $product) {
            $productId = (int) $product->id;
            $product->setAttribute(
                'live_stock',
                $liveStockMap[$productId] ?? $this->resolveLiveStockValue(
                    (int) ($product->stock ?? -1),
                    null,
                    (int) ($reservedCounts[$productId] ?? 0),
                    false
                )
            );
        }

        return $products;
    }

    /**
     * 分批拉取上游库存，批与批之间等待固定时长。
     *
     * 切块放在调用层而不是插件里：插件的 fetchBatchProductStocks 同时服务于下单时的
     * 实时校验，在那里加等待会让用户下单直接卡住数秒。这里只影响定时同步。
     *
     * @param  int[]  $supplierProductIds
     * @return array<int, int|null>
     */
    private function fetchStocksWithPacing(Supplier $supplier, array $supplierProductIds, int $pacingMs): array
    {
        $chunks = array_chunk($supplierProductIds, self::STOCK_SYNC_CHUNK_SIZE);
        $remoteStocks = [];

        foreach ($chunks as $index => $chunk) {
            if ($index > 0 && $pacingMs > 0) {
                usleep($pacingMs * 1000);
            }

            $remoteStocks += $this->resolveSupplierRemoteStocks($supplier, $chunk);
        }

        return $remoteStocks;
    }

    /**
     * `products.stock_synced_at` 是否已存在（按实例缓存）。
     *
     * 全量同步会对每个商品走一次该判断，而 hasColumn() 在 Laravel 12 下会真去查
     * 列信息（getColumnListing），逐商品调用等于把元数据查询乘上商品数。
     * 该列由本次迁移新增、进程生命周期内不会变，缓存一次即可。
     */
    private function hasStockSyncedAtColumn(): bool
    {
        return $this->hasStockSyncedAtColumn ??= Schema::hasColumn('products', 'stock_synced_at');
    }

    /**
     * 盖上库存同步时间戳。
     *
     * 直接走查询构造器而不是模型 save()：这里只关心一个时间列，不需要触发模型事件、
     * 也不该把 updated_at 一起改掉（那会让"商品最近修改时间"被同步任务污染）。
     */
    private function touchStockSyncedAt(Product $product): void
    {
        if (! $this->hasStockSyncedAtColumn()) {
            return;
        }

        DB::table('products')->where('id', (int) $product->id)->update(['stock_synced_at' => now()]);
    }

    /**
     * 库存同步的取数优先级与批量限制。
     *
     * 实测：上游库存必须逐商品查询（列表接口的 stock_control 不可信），473 个商品
     * 全量刷一次就是 473 个请求，容易被上游风控判成攻击。因此分批轮转，并让最需要
     * 新鲜度的商品排在前面：
     *   1. 从未同步过（stock_synced_at 为空）——必须先建立基线
     *   2. 已售罄或余量吃紧（0 <= stock <= 5）——最容易卖超，必须最勤
     *   3. 其它限量商品（stock > 5）
     *   4. 不限量商品（stock < 0）——值永远是 -1，最不需要刷
     * 同档内按最久未同步优先，保证轮转覆盖不会饿死任何商品。
     */
    private function applyStockSyncPriority(Builder $query, int $batchSize): void
    {
        if ($this->hasStockSyncedAtColumn()) {
            $query->orderByRaw('CASE WHEN stock_synced_at IS NULL THEN 0 ELSE 1 END')
                ->orderByRaw('CASE WHEN stock >= 0 AND stock <= 5 THEN 0 WHEN stock >= 0 THEN 1 ELSE 2 END')
                ->orderByRaw('CASE WHEN stock_synced_at IS NULL THEN 0 ELSE 1 END, stock_synced_at ASC');
        }

        $query->orderBy('id');

        if ($batchSize > 0) {
            $query->limit($batchSize);
        }
    }

    /**
     * 回写实时查询到的上游库存。
     *
     * 用 afterCommit 延迟：下单校验发生在事务内且商品已 lockForUpdate，直接 UPDATE
     * 会延长行锁持有时间、增加与库存扣减的冲突面；而在无事务的只读路径（商品详情页）
     * 上 afterCommit 会立即执行，两种场景都安全。
     *
     * 只在值真变化时写，避免每次浏览都产生无谓 UPDATE。
     */
    private function persistSyncedStock(Product $product, ?int $remoteStock): void
    {
        if ($remoteStock === null || ! $this->hasStockSyncedAtColumn()) {
            return;
        }

        $productId = (int) $product->id;
        $currentStock = (int) ($product->stock ?? -1);
        $changed = $currentStock !== $remoteStock;

        DB::afterCommit(function () use ($productId, $remoteStock, $changed): void {
            try {
                $payload = ['stock_synced_at' => now()];
                if ($changed) {
                    $payload['stock'] = $remoteStock;
                }

                DB::table('products')->where('id', $productId)->update($payload);
            } catch (\Throwable $exception) {
                // 回写是旁路优化，失败不能影响下单或页面渲染
                Log::warning('[商品库存] 回写上游库存失败', [
                    'product_id' => $productId,
                    'message' => $exception->getMessage(),
                ]);
            }
        });
    }

    private function resolveSupplierRemoteStocks(Supplier $supplier, array $supplierProductIds): array
    {
        $normalizedSupplierProductIds = collect($supplierProductIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $supplierProductId) => $supplierProductId > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();

        if ($normalizedSupplierProductIds === []) {
            return [];
        }

        $cacheKey = $this->supplierRemoteStockCacheKey($supplier, $normalizedSupplierProductIds);
        $cached = Cache::store('redis_volatile')->get($cacheKey);

        if (is_array($cached)) {
            return $cached;
        }

        $remoteStocks = $this->fetchSupplierRemoteStocks($supplier, $normalizedSupplierProductIds);

        Cache::store('redis_volatile')->put($cacheKey, $remoteStocks, now()->addSeconds(self::REMOTE_STOCK_CACHE_TTL_SECONDS));

        return $remoteStocks;
    }

    private function fetchSupplierRemoteStocks(Supplier $supplier, array $supplierProductIds): array
    {
        $configStocks = $this->resolveCatalogCapability($supplier)->fetchBatchProductStocks($supplier, $supplierProductIds);
        $catalogProducts = [];
        $missingCatalogIds = collect($supplierProductIds)
            ->filter(fn (int $supplierProductId) => ! is_array($configStocks[$supplierProductId] ?? null))
            ->values()
            ->all();

        if ($missingCatalogIds !== []) {
            $missingCatalogLookup = array_fill_keys($missingCatalogIds, true);
            $catalog = $this->resolveCatalogCapability($supplier)->getProductCatalog($supplier);
            $catalogProducts = collect($catalog['products'] ?? [])
                ->filter(fn ($item) => is_array($item) && isset($missingCatalogLookup[(int) ($item['id'] ?? 0)]))
                ->keyBy(fn (array $item) => (int) ($item['id'] ?? 0))
                ->all();
        }

        $remoteStocks = [];

        foreach ($supplierProductIds as $supplierProductId) {
            $remoteStocks[$supplierProductId] = $this->resolvePreferredRemoteStock(
                $configStocks[$supplierProductId] ?? null,
                $catalogProducts[$supplierProductId] ?? null
            );
        }

        return $remoteStocks;
    }

    private function supplierRemoteStockCacheKey(Supplier $supplier, array $supplierProductIds): string
    {
        return 'product_remote_stock:'.$supplier->id.':'.sha1(implode(',', $supplierProductIds));
    }

    private function findSaleProductForStock(int $productId): ?Product
    {
        return $this->saleProductQuery()
            ->select([
                'id',
                'product_group_id',
                'stock',
            ])
            ->whereKey($productId)
            ->first();
    }

    private function resolveRemoteCatalogStock(array $remoteProduct): int
    {
        if (array_key_exists('stock', $remoteProduct)) {
            return (int) $remoteProduct['stock'];
        }

        if ((int) ($remoteProduct['stock_control'] ?? 0) !== 1) {
            return -1;
        }

        $qty = $remoteProduct['qty'] ?? null;
        if ($qty === null || $qty === '' || ! is_numeric($qty)) {
            return 0;
        }

        return max((int) $qty, 0);
    }

    private function resolvePreferredRemoteStock(?array $configStock, mixed $catalogProduct): ?int
    {
        if (is_array($configStock) && array_key_exists('stock', $configStock)) {
            return (int) $configStock['stock'];
        }

        if (is_array($catalogProduct)) {
            return $this->resolveRemoteCatalogStock($catalogProduct);
        }

        return null;
    }

    private function resolveLiveStockValue(int $localStock, ?int $remoteStock, int $reservedCount, bool $preferUpstream): int
    {
        if (! $preferUpstream || $remoteStock === null) {
            return $localStock;
        }

        if ($remoteStock < 0) {
            return $localStock >= 0 ? $localStock : -1;
        }

        return max($remoteStock - max($reservedCount, 0), 0);
    }

    private function buildBatchSyncSkippedItem(int $productId, ?Product $product, string $reason): array
    {
        $supplier = $product instanceof Product ? $this->resolveProductSupplier($product) : null;

        return [
            'status' => 'skipped',
            'product_id' => $productId,
            'product_name' => $product instanceof Product ? (string) $product->name : '',
            'supplier_id' => $supplier instanceof Supplier ? (int) $supplier->id : 0,
            'supplier_name' => $supplier instanceof Supplier ? (string) ($supplier->name ?? '') : '',
            'updated_fields' => [],
            'reason' => $reason,
        ];
    }

    private function prefetchImportedConfigOptions(Supplier $supplier, array $supplierProductIds): array
    {
        $normalizedSupplierProductIds = collect($supplierProductIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $supplierProductId) => $supplierProductId > 0)
            ->unique()
            ->values()
            ->all();

        if ($normalizedSupplierProductIds === []) {
            return [];
        }

        try {
            $templates = $this->resolveCatalogCapability($supplier)->fetchBatchProductConfigOptions($supplier, $normalizedSupplierProductIds);
        } catch (\Throwable $exception) {
            Log::warning('[商品同步] 批量拉取上游配置项失败', [
                'supplier_id' => (int) $supplier->id,
                'upstream_product_ids' => $normalizedSupplierProductIds,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return [];
        }

        $result = [];

        foreach ($normalizedSupplierProductIds as $supplierProductId) {
            $result[$supplierProductId] = $this->normalizeImportedConfigOptions($templates[$supplierProductId] ?? []);
        }

        return $result;
    }

    private function syncSingleSupplierProduct(
        Product $product,
        array $catalogProducts,
        array $remoteConfigOptions,
        bool $syncPricing,
        bool $syncConfigOptions,
        bool $syncConfigPricing,
    ): array {
        $supplierProductId = $this->resolveProductUpstreamProductId($product);
        $supplier = $this->resolveProductSupplier($product);

        if (! $supplier instanceof Supplier) {
            return $this->buildBatchSyncSkippedItem((int) $product->id, $product, '商品未绑定供应商');
        }

        $payload = [];
        $updatedFields = [];

        if ($syncPricing) {
            $remoteProduct = $catalogProducts[$supplierProductId] ?? null;
            if (! is_array($remoteProduct)) {
                return $this->buildBatchSyncSkippedItem((int) $product->id, $product, '未找到对应的上游商品');
            }

            $pricing = $this->buildImportedPricing($remoteProduct);
            if ($pricing === []) {
                return $this->buildBatchSyncSkippedItem((int) $product->id, $product, '上游商品缺少可导入价格');
            }

            if ($pricing !== $this->normalizePricing($product->pricing)) {
                $payload['pricing'] = $pricing;
                $updatedFields[] = 'pricing';
            }

            $remoteStock = $this->resolveRemoteCatalogStock($remoteProduct);
            if ($remoteStock !== (int) ($product->stock ?? 0)) {
                $payload['stock'] = $remoteStock;
                $updatedFields[] = 'stock';
            }
        }

        if ($syncConfigOptions || $syncConfigPricing) {
            $normalizedRemoteConfigOptions = $this->normalizeImportedConfigOptions(
                $remoteConfigOptions[$supplierProductId] ?? []
            );
            $localConfigOptions = $this->normalizeConfigOptions($product->config_options);

            if ($normalizedRemoteConfigOptions !== []) {
                $mergedConfigOptions = $syncConfigPricing
                    ? $normalizedRemoteConfigOptions
                    : $this->mergeConfigOptionsPreservingPricing($localConfigOptions, $normalizedRemoteConfigOptions);

                if ($mergedConfigOptions !== $localConfigOptions) {
                    $payload['config_options'] = $mergedConfigOptions;
                    $updatedFields[] = 'config_options';
                }
            }
        }

        if ($payload === []) {
            return [
                'status' => 'skipped',
                'updated_fields' => [],
                'reason' => '当前商品无需更新',
            ];
        }

        $this->persistProductWithStructuredSync($product, $payload);

        return [
            'status' => 'synced',
            'updated_fields' => $updatedFields,
            'reason' => '',
        ];
    }

    private function productNeedsUpstreamFinalize(Product $product, bool $syncConfigOptions): bool
    {
        $supplier = $this->resolveProductSupplier($product);
        if (! $supplier instanceof Supplier) {
            return false;
        }

        if ((int) ($product->auto_setup ?? 0) !== 1) {
            return true;
        }

        if (! $syncConfigOptions) {
            return false;
        }

        return true;
    }

    private function buildFinalizeUpstreamSkippedItem(Product $product, string $reason): array
    {
        $supplier = $this->resolveProductSupplier($product);

        return [
            'status' => 'skipped',
            'product_id' => (int) $product->id,
            'product_name' => (string) $product->name,
            'supplier_id' => $supplier instanceof Supplier ? (int) $supplier->id : 0,
            'supplier_name' => $supplier instanceof Supplier ? (string) ($supplier->name ?? '') : '',
            'upstream_product_id' => $this->resolveProductUpstreamProductId($product),
            'reason' => $reason,
        ];
    }

    private function mergeConfigOptionsPreservingPricing(array $localConfigOptions, array $remoteConfigOptions): array
    {
        $localMap = collect($localConfigOptions)
            ->filter(fn ($item) => is_array($item))
            ->keyBy(fn (array $item, int $index) => $this->resolveConfigOptionKey($item, $index));

        return collect($remoteConfigOptions)
            ->filter(fn ($item) => is_array($item))
            ->map(function (array $remoteOption, int $index) use ($localMap) {
                $key = $this->resolveConfigOptionKey($remoteOption, $index);
                $localOption = $localMap->get($key);

                if (! is_array($localOption)) {
                    return $remoteOption;
                }

                $mergedOption = $remoteOption;

                if (array_key_exists('pricing', $localOption)) {
                    $mergedOption['pricing'] = $localOption['pricing'];
                }

                if (array_key_exists('default_value', $localOption)) {
                    $mergedOption['default_value'] = $localOption['default_value'];
                }

                $localSubMap = collect($localOption['sub'] ?? [])
                    ->filter(fn ($sub) => is_array($sub))
                    ->keyBy(fn (array $sub, int $subIndex) => $this->resolveConfigSubOptionKey($sub, $subIndex));

                $mergedOption['sub'] = collect($remoteOption['sub'] ?? [])
                    ->filter(fn ($sub) => is_array($sub))
                    ->map(function (array $remoteSub, int $subIndex) use ($localSubMap) {
                        $localSub = $localSubMap->get($this->resolveConfigSubOptionKey($remoteSub, $subIndex));
                        if (! is_array($localSub)) {
                            return $remoteSub;
                        }

                        if (array_key_exists('pricing', $localSub)) {
                            $remoteSub['pricing'] = $localSub['pricing'];
                        }

                        return $remoteSub;
                    })
                    ->values()
                    ->all();

                return $mergedOption;
            })
            ->values()
            ->all();
    }

    private function buildImportedPricing(array $supplierProduct): array
    {
        $monthlyBasePrice = $this->resolveMonthlyBaseAmount(
            $supplierProduct['monthly_price'] ?? null,
            $supplierProduct['product_price'] ?? null,
            $supplierProduct['billingcycle'] ?? ''
        );

        if ($monthlyBasePrice === null) {
            return [];
        }

        // 上游金额是两位小数字符串（如 '19.99'）。周期换算先转为“分”整数再相乘，
        // 避免浮点乘法（如 19.99 * 12 = 239.87999...）在边界产生一分钱误差。
        $monthlyBaseCents = (int) round(((float) $monthlyBasePrice) * 100);
        $pricing = [];

        foreach (self::IMPORT_PRICING_MONTHS as $cycle => $months) {
            $pricing[$cycle] = number_format($monthlyBaseCents * $months / 100, 2, '.', '');
        }

        return $pricing;
    }

    private function resolveConfigOptionKey(array $option, int $index): string
    {
        $field = trim((string) ($option['field'] ?? ''));
        if ($field !== '') {
            return 'field:'.$field;
        }

        $name = trim((string) ($option['name'] ?? ''));
        if ($name !== '') {
            return 'name:'.$name;
        }

        return 'index:'.$index;
    }

    private function resolveConfigSubOptionKey(array $subOption, int $index): string
    {
        $id = (int) ($subOption['id'] ?? 0);
        if ($id > 0) {
            return 'id:'.$id;
        }

        $value = trim((string) ($subOption['option_name_first'] ?? $subOption['option_name'] ?? ''));
        if ($value !== '') {
            return 'value:'.$value;
        }

        return 'index:'.$index;
    }

    private function normalizeImportedAmount(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $amount = (float) $value;

        return $amount > 0 ? number_format($amount, 2, '.', '') : null;
    }

    private function resolveMonthlyBaseAmount(mixed $monthlyCandidate, mixed $productPriceCandidate, mixed $billingCycle): ?float
    {
        $monthlyAmount = $this->normalizeImportedAmount($monthlyCandidate);
        if ($monthlyAmount !== null) {
            return (float) $monthlyAmount;
        }

        $productPrice = $this->normalizeImportedAmount($productPriceCandidate);
        if ($productPrice === null) {
            return null;
        }

        $months = $this->resolveBillingCycleMonths($billingCycle);
        if ($months <= 0) {
            return null;
        }

        return round(((float) $productPrice) / $months, 2);
    }

    private function mapSupplierBillingCycle(mixed $billingCycle): string
    {
        return match (strtolower(trim((string) $billingCycle))) {
            'free', 'monthly' => 'monthly',
            'quarterly' => 'quarterly',
            'semiannually', 'semi-annually', 'semi' => 'semiannually',
            'annually', 'yearly' => 'annually',
            'biennially', 'biennial' => 'biennially',
            'triennially', 'triennial' => 'triennially',
            'onetime', 'one_time', 'once' => 'onetime',
            default => 'monthly',
        };
    }

    private function resolveBillingCycleMonths(mixed $billingCycle): int
    {
        $cycle = $this->mapSupplierBillingCycle($billingCycle);

        return self::IMPORT_PRICING_MONTHS[$cycle] ?? 1;
    }

    private function resolveImportedChildGroupName(array $supplierProduct): string
    {
        foreach (['group_label', 'group_name', 'first_group_name'] as $key) {
            $value = trim((string) ($supplierProduct[$key] ?? ''));
            if ($value !== '') {
                return mb_substr($value, 0, 100);
            }
        }

        return '默认子菜单';
    }

    private function resolveImportedConfigOptions(Supplier $supplier, array $supplierProduct, array $fallback = []): array
    {
        $supplierProductId = (int) ($supplierProduct['id'] ?? 0);
        if ($supplierProductId <= 0) {
            return $fallback;
        }

        try {
            $template = $this->resolveCatalogCapability($supplier)->getProductConfigTemplate($supplier, $supplierProductId);
            $configOptions = $this->normalizeImportedConfigOptions($template['config_options'] ?? []);

            return $configOptions !== [] ? $configOptions : $fallback;
        } catch (\Throwable $exception) {
            Log::warning('[商品批量对接] 自动拉取配置项失败', [
                'supplier_id' => $supplier->id,
                'upstream_product_id' => $supplierProductId,
                'message' => $exception->getMessage(),
            ]);

            return $fallback;
        }
    }

    private function resolveCatalogCapability(Supplier $supplier): object
    {
        $this->upstreamBindingWriter()->syncSupplierBinding($supplier);

        return $this->providerResolver
            ->resolveForSupplier($supplier)
            ->require(ProvidesConsoleCatalog::class, '当前供应商不支持商品目录同步');
    }

    private function normalizeImportedConfigOptions(mixed $configOptions): array
    {
        $items = $this->normalizeConfigOptions($configOptions);

        return collect($items)
            ->map(function (array $item, int $index) {
                $normalized = $item;
                $normalized['sort_order'] = (int) ($item['sort_order'] ?? $item['order'] ?? ($index + 1));
                $normalized['required'] = (int) ($item['required'] ?? 0);
                $normalized['hidden'] = (int) ($item['hidden'] ?? 0);
                $normalized['allow_upgrade'] = (int) ($item['allow_upgrade'] ?? 0);
                $normalized['allow_promo_code'] = array_key_exists('allow_promo_code', $item)
                    ? (int) $item['allow_promo_code']
                    : 1;
                $normalized['parameter'] = trim((string) ($item['parameter'] ?? $this->buildImportedConfigParameter($item)));
                $normalized['sub'] = $this->normalizeImportedConfigSubOptions($item['sub'] ?? []);

                return $normalized;
            })
            ->values()
            ->all();
    }

    private function normalizeImportedConfigSubOptions(mixed $subOptions): array
    {
        if (! is_array($subOptions)) {
            return [];
        }

        return collect($subOptions)
            ->filter(fn ($sub) => is_array($sub))
            ->map(function (array $sub, int $index) {
                $pricing = $this->normalizeImportedSubPricing($sub['pricing'] ?? $sub['pricings'] ?? []);
                $optionName = trim((string) ($sub['option_name'] ?? $sub['version'] ?? ''));
                $optionNameFirst = trim((string) ($sub['option_name_first'] ?? ''));

                if ($optionNameFirst === '') {
                    $optionNameFirst = $optionName !== '' ? $optionName : (string) ($sub['id'] ?? '');
                }

                return array_merge($sub, [
                    'option_name' => $optionName,
                    'option_name_first' => $optionNameFirst,
                    'hidden' => (int) ($sub['hidden'] ?? 0),
                    'sort_order' => (int) ($sub['sort_order'] ?? $sub['order'] ?? $index),
                    'qty_minimum' => (int) ($sub['qty_minimum'] ?? 0),
                    'qty_maximum' => (int) ($sub['qty_maximum'] ?? 0),
                    'pricing' => $pricing,
                ]);
            })
            ->values()
            ->all();
    }

    private function normalizeImportedSubPricing(mixed $pricing): array
    {
        $pricingData = [];

        if (is_array($pricing)) {
            $pricingData = isset($pricing[0]) && is_array($pricing[0])
                ? (array) $pricing[0]
                : $pricing;
        }

        $directPricing = $this->normalizePricing($this->normalizeImportedPricingKeys($pricingData));
        if ($directPricing !== []) {
            return $directPricing;
        }

        $monthlyBasePrice = $this->resolveMonthlyBaseAmount(
            $pricingData['monthly'] ?? null,
            $this->resolveFirstAvailablePricingValue($pricingData),
            $this->resolveFirstAvailablePricingCycle($pricingData)
        );

        if ($monthlyBasePrice === null) {
            return [];
        }

        $normalized = [];
        foreach (self::IMPORT_PRICING_MONTHS as $cycle => $months) {
            $normalized[$cycle] = number_format($monthlyBasePrice * $months, 2, '.', '');
        }

        return $normalized;
    }

    private function normalizeImportedPricingKeys(array $pricingData): array
    {
        $normalized = [];
        $cycleMap = [
            'hour' => 'hour',
            'day' => 'day',
            'ontrial' => 'ontrial',
            'monthly' => 'monthly',
            'quarterly' => 'quarterly',
            'semiannually' => 'semiannually',
            'annually' => 'annually',
            'biennially' => 'biennially',
            'triennially' => 'triennially',
            'fourly' => 'fourly',
            'fively' => 'fively',
            'sixly' => 'sixly',
            'sevenly' => 'sevenly',
            'eightly' => 'eightly',
            'ninely' => 'ninely',
            'tenly' => 'tenly',
            'onetime' => 'one_time',
            'one_time' => 'one_time',
        ];

        foreach ($cycleMap as $source => $target) {
            if (! array_key_exists($source, $pricingData)) {
                continue;
            }

            $normalized[$target] = $pricingData[$source];
        }

        return $normalized;
    }

    private function resolveFirstAvailablePricingValue(array $pricingData): mixed
    {
        foreach (array_keys(self::IMPORT_PRICING_MONTHS) as $cycle) {
            if (($pricingData[$cycle] ?? null) !== null && $pricingData[$cycle] !== '') {
                return $pricingData[$cycle];
            }
        }

        foreach (['biennially', 'triennially', 'onetime', 'onetime_fee', 'yearly'] as $cycle) {
            if (($pricingData[$cycle] ?? null) !== null && $pricingData[$cycle] !== '') {
                return $pricingData[$cycle];
            }
        }

        return null;
    }

    private function resolveFirstAvailablePricingCycle(array $pricingData): string
    {
        foreach (array_keys(self::IMPORT_PRICING_MONTHS) as $cycle) {
            if (($pricingData[$cycle] ?? null) !== null && $pricingData[$cycle] !== '') {
                return $cycle;
            }
        }

        foreach (['biennially', 'triennially', 'onetime', 'onetime_fee', 'yearly'] as $cycle) {
            if (($pricingData[$cycle] ?? null) !== null && $pricingData[$cycle] !== '') {
                return $cycle;
            }
        }

        return 'monthly';
    }

    private function buildImportedConfigParameter(array $item): string
    {
        if (! is_array($item['sub'] ?? null)) {
            return '';
        }

        return collect($item['sub'])
            ->filter(fn ($sub) => is_array($sub) && (int) ($sub['hidden'] ?? 0) !== 1)
            ->map(function (array $sub) {
                $value = trim((string) ($sub['option_name_first'] ?? $sub['option_name'] ?? $sub['id'] ?? ''));
                $label = trim((string) ($sub['version'] ?? $sub['option_name'] ?? $sub['option_name_first'] ?? $sub['id'] ?? ''));

                return $value !== '' ? "{$value}|{$label}" : '';
            })
            ->filter()
            ->implode(',');
    }

    private function resolveImportedFirstProductGroup(string $productType, int $firstProductGroupId): FirstProductGroup
    {
        $query = FirstProductGroup::query();
        $group = $firstProductGroupId > 0
            ? $query->whereKey($firstProductGroupId)->first()
            : $query->where('code', $productType)->first();

        throw_if(! $group instanceof FirstProductGroup, new BusinessException('目标一级菜单不存在'));
        throw_if(
            trim((string) $group->code) !== $productType,
            new BusinessException('目标一级菜单与商品类型不匹配')
        );

        return $group;
    }

    private function resolveImportedSecondProductGroup(
        FirstProductGroup $firstGroup,
        int $secondProductGroupId,
        ?string $secondProductGroupName
    ): SecondProductGroup {
        if ($secondProductGroupId > 0) {
            $group = SecondProductGroup::query()->whereKey($secondProductGroupId)->first();
            throw_if(! $group instanceof SecondProductGroup, new BusinessException('目标二级分类不存在'));
            throw_if(
                (int) $group->first_product_group_id !== (int) $firstGroup->id,
                new BusinessException('目标二级分类不属于所选一级菜单')
            );

            return $group;
        }

        $name = TextSanitizer::nullable((string) $secondProductGroupName) ?: '默认分类';

        return $this->resolveOrCreateImportedSecondProductGroup($firstGroup, $name);
    }

    private function resolveImportedThirdProductGroup(
        SecondProductGroup $secondGroup,
        int $thirdProductGroupId,
        ?string $thirdProductGroupName
    ): ?ThirdProductGroup {
        if ($thirdProductGroupId > 0) {
            $group = ThirdProductGroup::query()->whereKey($thirdProductGroupId)->first();
            throw_if(! $group instanceof ThirdProductGroup, new BusinessException('目标三级分类不存在'));
            throw_if(
                (int) $group->second_product_group_id !== (int) $secondGroup->id,
                new BusinessException('目标三级分类不属于所选二级分类')
            );

            return $group;
        }

        $name = TextSanitizer::nullable((string) $thirdProductGroupName);

        return $name !== null ? $this->resolveOrCreateImportedThirdProductGroup($secondGroup, $name) : null;
    }

    /**
     * @return array{product_type:string,service_type_code:string,product_group_id:int}
     */
    private function buildImportedTargetHierarchy(
        FirstProductGroup $firstGroup,
        SecondProductGroup $secondGroup,
        ThirdProductGroup $thirdGroup
    ): array {
        $productType = ProductType::businessValueForFirstGroup($firstGroup, $firstGroup->code);

        return [
            'product_type' => $productType,
            'service_type_code' => $productType,
            'product_group_id' => (int) $thirdGroup->id,
        ];
    }

    private function resolveImportedBatchConfigOptions(
        Supplier $supplier,
        array $supplierProduct,
        bool $syncConfigOptions,
        mixed $fallbackConfigOptions
    ): array {
        $fallback = $this->normalizeConfigOptions($fallbackConfigOptions);
        if (! $syncConfigOptions) {
            return $fallback;
        }

        return $this->resolveImportedConfigOptions($supplier, $supplierProduct, $fallback);
    }

    private function buildBulkConnectProductPayload(
        Supplier $supplier,
        array $targetHierarchy,
        array $supplierProduct,
        string $productType,
        array $pricing,
        int $defaultStatus,
        int $defaultAutoSetup,
        array $configOptions
    ): array {
        $name = TextSanitizer::nullable((string) ($supplierProduct['name'] ?? ''));
        throw_if($name === null, new BusinessException('上游商品名称不能为空'));
        $purchaseRequires = $this->buildImportedPurchaseRequires($name);
        $this->upstreamBindingWriter()->syncSupplierBinding($supplier);
        $providerKey = $this->providerResolver->resolveForSupplier($supplier)->key();

        return [
            'name' => $name,
            'product_type' => ProductType::normalizeBusinessValue($productType),
            'service_type_code' => (string) $targetHierarchy['service_type_code'],
            'product_group_id' => (int) $targetHierarchy['product_group_id'],
            'pricing' => $pricing,
            'setup_fee' => $this->normalizeImportedAmount($supplierProduct['setup_fee'] ?? null) ?? '0.00',
            'config_options' => $configOptions,
            'purchase_requires' => $purchaseRequires,
            'stock' => $this->resolveRemoteCatalogStock($supplierProduct),
            'status' => $defaultStatus,
            'sort_order' => 0,
            'auto_setup' => $defaultAutoSetup,
            'upstream_binding' => [
                'provider_key' => $providerKey !== null && trim($providerKey) !== '' ? trim($providerKey) : null,
                'supplier_id' => (int) $supplier->id,
                'upstream_product_id' => (int) ($supplierProduct['id'] ?? 0),
            ],
        ];
    }

    private function buildImportedPurchaseRequires(string $name): array
    {
        [$cpu, $memory] = $this->extractCpuMemoryDefaultsFromName($name);
        if ($cpu === null && $memory === null) {
            return [];
        }

        $defaultConfig = [];
        if ($cpu !== null) {
            $defaultConfig['cpu'] = $cpu;
        }
        if ($memory !== null) {
            $defaultConfig['memory'] = $memory;
        }

        return [
            'upstream_default_config' => $defaultConfig,
        ];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function extractCpuMemoryDefaultsFromName(string $name): array
    {
        $normalizedName = trim($name);
        if ($normalizedName === '') {
            return [null, null];
        }

        $patterns = [
            '/(\d+(?:\.\d+)?)\s*(?:v?cpu|核|c|h)\s*[-_\/ ]*(\d+(?:\.\d+)?)\s*(g|gb|m|mb)\b/iu',
            '/(\d+(?:\.\d+)?)\s*(?:c|h|核)\s*(\d+(?:\.\d+)?)\s*(g|gb|m|mb)\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalizedName, $matches) !== 1) {
                continue;
            }

            $cpu = $this->normalizeImportedConfigNumeric($matches[1]);
            $memory = $this->normalizeImportedMemoryConfigValue($matches[2], $matches[3] ?? '');

            return [$cpu, $memory];
        }

        return [null, null];
    }

    private function normalizeImportedConfigNumeric(string $value): ?string
    {
        $number = (float) $value;
        if ($number <= 0) {
            return null;
        }

        if (floor($number) === $number) {
            return (string) ((int) $number);
        }

        return rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }

    private function normalizeImportedMemoryConfigValue(string $number, string $unit): ?string
    {
        $numeric = (float) $number;
        if ($numeric <= 0) {
            return null;
        }

        $normalizedUnit = strtolower(trim($unit));
        if (in_array($normalizedUnit, ['g', 'gb'], true)) {
            return $this->normalizeImportedConfigNumeric($number);
        }

        if (in_array($normalizedUnit, ['m', 'mb'], true)) {
            return (string) ((int) round($numeric));
        }

        return $this->normalizeImportedConfigNumeric($number);
    }

    private function buildBulkConnectImportedItem(
        Product $product,
        int $supplierProductId,
        array $supplierProduct,
        string $action
    ): array {
        $hierarchyFields = ProductGroupHierarchyFields::fromProduct($product);
        $groupNameSegments = array_values(array_filter([
            trim((string) ($hierarchyFields['first_product_group_name'] ?? '')),
            trim((string) ($hierarchyFields['second_product_group_name'] ?? '')),
            trim((string) ($hierarchyFields['third_product_group_name'] ?? '')),
        ], static fn (string $name): bool => $name !== ''));

        return [
            'action' => $action,
            'product_id' => (int) $product->id,
            'upstream_product_id' => $supplierProductId,
            'supplier_display_name' => (string) ($supplierProduct['name'] ?? ''),
            'local_display_name' => $this->resolveProductDisplayName($product),
            'first_product_group_id' => $hierarchyFields['first_product_group_id'],
            'first_product_group_name' => $hierarchyFields['first_product_group_name'],
            'second_product_group_id' => $hierarchyFields['second_product_group_id'],
            'second_product_group_name' => $hierarchyFields['second_product_group_name'],
            'third_product_group_id' => $hierarchyFields['third_product_group_id'],
            'third_product_group_name' => $hierarchyFields['third_product_group_name'],
            'effective_product_group_id' => $hierarchyFields['effective_product_group_id'],
            'effective_product_group_level' => $hierarchyFields['effective_product_group_level'],
            'effective_product_group_full_name' => implode(' / ', $groupNameSegments),
        ];
    }

    private function buildBulkConnectSkippedItem(int $supplierProductId, ?array $supplierProduct, string $reason): array
    {
        return [
            'upstream_product_id' => $supplierProductId,
            'supplier_display_name' => (string) ($supplierProduct['name'] ?? ''),
            'reason' => $reason,
        ];
    }

    private function resolveProductDisplayName(Product $product): string
    {
        $resolver = $this->productDisplayNameResolver ?? new ProductDisplayNameResolver;
        $displayName = trim((string) ($resolver->resolveForProduct($product)['product_display_name'] ?? ''));

        return $displayName !== '' ? $displayName : ('未配置规格 #'.(int) $product->id);
    }

    private function generateUniqueSecondProductGroupSlug(FirstProductGroup $firstGroup, string $source): string
    {
        return $this->generateUniqueProductGroupSlug(
            SecondProductGroup::query()->where('first_product_group_id', (int) $firstGroup->id),
            $source,
            'second-group'
        );
    }

    private function generateUniqueThirdProductGroupSlug(SecondProductGroup $secondGroup, string $source): string
    {
        return $this->generateUniqueProductGroupSlug(
            ThirdProductGroup::query()->where('second_product_group_id', (int) $secondGroup->id),
            $source,
            'third-group'
        );
    }

    private function generateUniqueProductGroupSlug(Builder $query, string $source, string $fallback): string
    {
        $slug = Str::slug(trim($source)) ?: $fallback;
        $candidate = $slug;
        $suffix = 1;

        while ((clone $query)->where('slug', $candidate)->exists()) {
            $suffix++;
            $candidate = $slug.'-'.$suffix;
        }

        return $candidate;
    }

    private function resolveOrCreateImportedSecondProductGroup(FirstProductGroup $firstGroup, string $name): SecondProductGroup
    {
        $existing = SecondProductGroup::query()
            ->where('first_product_group_id', (int) $firstGroup->id)
            ->where('name', $name)
            ->first();

        if ($existing instanceof SecondProductGroup) {
            return $existing;
        }

        /** @var SecondProductGroup $group */
        $group = SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => $name,
            'slug' => $this->generateUniqueSecondProductGroupSlug($firstGroup, $name),
            'description' => null,
            'banner_image' => null,
            'sort_order' => 0,
            'is_visible' => 1,
        ]);

        return $group;
    }

    private function resolveOrCreateImportedThirdProductGroup(SecondProductGroup $secondGroup, string $name): ThirdProductGroup
    {
        $existing = ThirdProductGroup::query()
            ->where('second_product_group_id', (int) $secondGroup->id)
            ->where('name', $name)
            ->first();

        if ($existing instanceof ThirdProductGroup) {
            return $existing;
        }

        /** @var ThirdProductGroup $group */
        $group = ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $secondGroup->id,
            'name' => $name,
            'slug' => $this->generateUniqueThirdProductGroupSlug($secondGroup, $name),
            'description' => null,
            'sort_order' => 0,
            'is_visible' => 1,
        ]);

        return $group;
    }

    private function createProductWithStructuredSync(array $payload): Product
    {
        $bindingPayload = $this->extractUpstreamBindingPayload($payload);
        unset($payload['upstream_binding']);

        /** @var Product $product */
        $product = Product::withoutEvents(fn () => Product::create($payload));
        $product = $product->fresh() ?? $product;
        $this->syncProductBindingFromPayload($product, $bindingPayload);

        return $product->fresh([
            'productGroup.secondProductGroup.firstProductGroup',
        ]);
    }

    private function persistProductWithStructuredSync(Product $product, array $payload): Product
    {
        $bindingPayload = $this->extractUpstreamBindingPayload($payload);
        unset($payload['upstream_binding']);

        Product::withoutEvents(function () use ($product, $payload): void {
            $product->fill($payload)->save();
        });

        if ($product->trashed()) {
            $product->restore();
        }

        $product->refresh();
        $this->syncProductBindingFromPayload($product, $bindingPayload);

        return $product->fresh([
            'productGroup.secondProductGroup.firstProductGroup',
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{supplier_id: int, upstream_product_id: string}|null
     */
    private function extractUpstreamBindingPayload(array $payload): ?array
    {
        $binding = is_array($payload['upstream_binding'] ?? null) ? $payload['upstream_binding'] : [];
        $supplierId = (int) (($binding['supplier_id'] ?? 0) ?: 0);
        $upstreamProductId = trim((string) ($binding['upstream_product_id'] ?? ''));

        if ($supplierId <= 0 || $upstreamProductId === '') {
            return null;
        }

        return [
            'supplier_id' => $supplierId,
            'upstream_product_id' => $upstreamProductId,
        ];
    }

    private function syncProductBindingFromPayload(Product $product, ?array $bindingPayload): void
    {
        if ($bindingPayload === null) {
            return;
        }

        $supplier = Supplier::query()->find($bindingPayload['supplier_id']);
        if (! $supplier instanceof Supplier) {
            return;
        }

        $this->upstreamBindingWriter()->syncProductBinding(
            $product,
            $supplier,
            $bindingPayload['upstream_product_id']
        );
    }

    private function applyHasUpstreamProductBindingScope(Builder $query, ?string $providerKey = null): void
    {
        if (Schema::hasTable('product_upstream_bindings')) {
            $normalizedProviderKey = trim((string) $providerKey);

            $query->whereExists(function ($subQuery) use ($normalizedProviderKey): void {
                $subQuery
                    ->selectRaw('1')
                    ->from('product_upstream_bindings as pub')
                    ->whereColumn('pub.product_id', 'products.id')
                    ->where('pub.status', 1);

                if ($normalizedProviderKey !== '') {
                    $subQuery->where('pub.provider_key', $normalizedProviderKey);
                }
            });

            return;
        }

        $query->whereRaw('0 = 1');
    }

    private function findExistingProductsBySupplierUpstreamIds(Supplier $supplier, array $supplierProductIds)
    {
        $normalizedIds = collect($supplierProductIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($normalizedIds !== [] && Schema::hasTable('product_upstream_bindings') && Schema::hasTable('supplier_plugin_bindings')) {
            return Product::withTrashed()
                ->with(['productGroup.secondProductGroup.firstProductGroup'])
                ->select('products.*', 'pub.upstream_product_id as binding_upstream_product_id')
                ->join('product_upstream_bindings as pub', 'pub.product_id', '=', 'products.id')
                ->join('supplier_plugin_bindings as spb', 'spb.id', '=', 'pub.supplier_plugin_binding_id')
                ->where('spb.supplier_id', (int) $supplier->id)
                ->whereIn('pub.upstream_product_id', array_map(static fn (int $id): string => (string) $id, $normalizedIds))
                ->orderByDesc('pub.status')
                ->orderByDesc('pub.id')
                ->get()
                ->keyBy(fn (Product $product) => (int) ($product->getAttribute('binding_upstream_product_id') ?: $this->resolveProductUpstreamProductId($product)));
        }

        return collect();
    }

    private function resolveProductSupplier(Product $product): ?Supplier
    {
        $supplier = $this->bindingResolver()->supplierForProduct($product);
        if ($supplier instanceof Supplier) {
            return $this->bindingResolver()->supplierWithRuntimeCredentials($supplier);
        }

        return null;
    }

    private function resolveProductUpstreamProductId(Product $product): int
    {
        $bindingValue = $this->bindingResolver()->upstreamProductIdForProduct($product);
        if ($bindingValue !== null && trim((string) $bindingValue) !== '') {
            return (int) $bindingValue;
        }

        return 0;
    }

    private function upstreamBindingWriter(): UpstreamBindingWriter
    {
        return $this->upstreamBindingWriter ??= app(UpstreamBindingWriter::class);
    }

    private function recordProductStockSnapshot(Product $product, Supplier $supplier, int $supplierProductId, int $remoteStock): void
    {
        $this->upstreamBindingWriter()->syncProductBinding($product, $supplier, (string) $supplierProductId, [
            'stock' => $remoteStock,
            'source' => 'scheduled_stock_sync',
            'synced_at' => now()->format('Y-m-d H:i:s'),
        ]);
    }

    private function bindingResolver(): PluginBindingResolver
    {
        return $this->bindingResolver ??= app(PluginBindingResolver::class);
    }

    private function queryOpenStockReservations(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return Order::query()
            ->selectRaw('product_id, SUM(CASE WHEN quantity IS NULL OR quantity < 1 THEN 1 ELSE quantity END) as aggregate')
            ->whereIn('product_id', $productIds)
            ->where('type', OrderType::NEW)
            ->whereIn('status', [
                OrderStatus::PENDING,
                OrderStatus::PAID,
                OrderStatus::PROCESSING,
            ])
            ->where(function ($query) {
                $query->whereNull('service_id')
                    // 服务已挂单但仍在开通中时，库存仍然需要继续占用。
                    ->orWhereHas('service', function ($serviceQuery) {
                        $serviceQuery->where('status', ServiceStatus::PENDING);
                    });
            })
            ->groupBy('product_id')
            ->pluck('aggregate', 'product_id')
            ->map(fn ($count) => (int) $count)
            ->all();
    }

    private function saleProductQuery(): Builder
    {
        $visibleProductTypes = ProductType::visibleValues();

        if ($visibleProductTypes === []) {
            return Product::query()->whereRaw('1 = 0');
        }

        return Product::query()
            ->onSale()
            ->whereNotNull('product_group_id')
            ->withVisibleProductGroupPath($visibleProductTypes);
    }
}
