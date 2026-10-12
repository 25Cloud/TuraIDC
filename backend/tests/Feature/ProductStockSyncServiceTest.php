<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Supplier;
use App\Services\ProductCatalog\ProductSyncService;
use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\Contracts\UpstreamDriver;
use App\Services\Upstream\ProviderKey;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductStockSyncServiceTest extends TestCase
{
    /** 同一用例内多个商品共用一套供应商与绑定，才能验证覆盖是按上游商品精确生效的 */
    private ?int $supplierId = null;

    private ?int $pluginId = null;

    private ?int $supplierBindingId = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSqliteInMemoryDatabase();
        $this->createTables();
    }

    protected function tearDown(): void
    {
        foreach ([
            'product_upstream_bindings',
            'supplier_plugin_bindings',
            'integration_plugins',
            'products',
            'suppliers',
        ] as $table) {
            Schema::connection('sqlite')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_sync_upstream_product_stocks_updates_only_zjmf_bound_products(): void
    {
        $catalog = new RecordingStockCatalog([
            1001 => 9,
        ]);
        $providerRegistry = new ProviderRegistry([
            new FakeStockCatalogDriver($catalog),
        ]);
        app()->instance(ProviderRegistry::class, $providerRegistry);
        $service = new ProductSyncService(new ProviderResolver($providerRegistry));

        $supplierId = $this->insertSupplier('Zjmf Supplier');
        $pluginId = $this->insertPlugin('zjmf_finance', ProviderKey::ZJMF_FINANCE_API);
        $supplierBindingId = $this->insertSupplierBinding($supplierId, $pluginId, ProviderKey::ZJMF_FINANCE_API);
        $zjmfProductId = $this->insertProduct(2);
        $this->insertProductBinding($zjmfProductId, $supplierBindingId, $pluginId, ProviderKey::ZJMF_FINANCE_API, '1001');

        $otherSupplierId = $this->insertSupplier('Hosting Supplier');
        $otherPluginId = $this->insertPlugin('hosting_panel_api', ProviderKey::HOSTING_PANEL_API);
        $otherSupplierBindingId = $this->insertSupplierBinding($otherSupplierId, $otherPluginId, ProviderKey::HOSTING_PANEL_API);
        $otherProductId = $this->insertProduct(4);
        $this->insertProductBinding($otherProductId, $otherSupplierBindingId, $otherPluginId, ProviderKey::HOSTING_PANEL_API, '2001');

        $result = $service->syncUpstreamProductStocks(ProviderKey::ZJMF_FINANCE_API);

        $this->assertSame(1, (int) ($result['matched_products'] ?? 0));
        $this->assertSame(1, (int) ($result['matched_suppliers'] ?? 0));
        $this->assertSame(1, (int) ($result['synced_products'] ?? 0));
        $this->assertSame([[1001]], $catalog->requestedProductIds);
        $this->assertSame(9, (int) Product::query()->findOrFail($zjmfProductId)->stock);
        $this->assertSame(4, (int) Product::query()->findOrFail($otherProductId)->stock);

        $snapshot = DB::table('product_upstream_bindings')
            ->where('product_id', $zjmfProductId)
            ->value('upstream_product_snapshot_json');
        $decoded = json_decode((string) $snapshot, true);

        $this->assertSame(9, (int) ($decoded['stock'] ?? -1));
        $this->assertSame('scheduled_stock_sync', $decoded['source'] ?? null);
    }

    /**
     * 上游加购/结算是唯一的真实库存闸门，返回的 qty 只是展示口径。
     * 被「库存不足」拒绝后必须立刻校准，否则用户会继续按虚高的陈旧快照付款，
     * 付完才发现开不出来。
     */
    public function test_upstream_stock_shortage_zeroes_snapshot_and_overrides_upstream_reads(): void
    {
        $service = $this->serviceWithUpstreamStocks([1001 => 9]);
        $productId = $this->bindZjmfProduct(1001, 9);

        $service->syncUpstreamProductStocks(ProviderKey::ZJMF_FINANCE_API);
        $this->assertSame(9, (int) Product::query()->findOrFail($productId)->stock, '前置：上游展示口径为 9');

        $service->markUpstreamStockShortage($productId);

        $this->assertSame(0, (int) Product::query()->findOrFail($productId)->stock, '快照必须归零');

        $snapshot = DB::table('product_upstream_bindings')
            ->where('product_id', $productId)
            ->value('upstream_product_snapshot_json');
        $decoded = json_decode((string) $snapshot, true);
        $this->assertSame(0, (int) ($decoded['stock'] ?? -1), '绑定快照中的库存也必须归零');

        // 模拟浏览回写把上游展示口径的陈旧 qty 又写回快照（覆盖窗口内这完全可能发生）。
        // 覆盖的意义正在于此：下一轮同步读到的上游值仍是 9，落库也必须是 0。
        DB::table('products')->where('id', $productId)->update(['stock' => 9]);
        $service->syncUpstreamProductStocks(ProviderKey::ZJMF_FINANCE_API);

        $this->assertSame(
            0,
            (int) Product::query()->findOrFail($productId)->stock,
            '售罄覆盖期内，上游有货也必须被压成 0'
        );
    }

    /**
     * 覆盖按「供应商 + 上游商品」精确生效：同供应商的其它在售商品不能被连累归零，
     * 否则一次售罄会把整条商品线误伤成不可售。
     */
    public function test_stock_shortage_override_only_affects_the_rejected_upstream_product(): void
    {
        $service = $this->serviceWithUpstreamStocks([1001 => 9, 1002 => 4]);
        $soldOutProductId = $this->bindZjmfProduct(1001, 9);
        $healthyProductId = $this->bindZjmfProduct(1002, 4);

        $service->markUpstreamStockShortage($soldOutProductId);
        $service->syncUpstreamProductStocks(ProviderKey::ZJMF_FINANCE_API);

        $this->assertSame(0, (int) Product::query()->findOrFail($soldOutProductId)->stock);
        $this->assertSame(
            4,
            (int) Product::query()->findOrFail($healthyProductId)->stock,
            '同供应商其它在售商品不得被连累归零'
        );
    }

    /**
     * 校准必须 best-effort：调用方在失败分支之后还有留痕逻辑，
     * 校准自身抛异常不得中断留痕，也不得把原始异常换成别的。
     */
    public function test_stock_shortage_calibration_never_breaks_the_caller(): void
    {
        $service = $this->serviceWithUpstreamStocks([1001 => 9]);

        // 商品不存在：不应抛异常
        $service->markUpstreamStockShortage(999999);

        $this->assertTrue(true, '校准失败必须被吞掉并记录日志，不得向上抛出');
    }

    private function serviceWithUpstreamStocks(array $stocks): ProductSyncService
    {
        $providerRegistry = new ProviderRegistry([
            new FakeStockCatalogDriver(new RecordingStockCatalog($stocks)),
        ]);
        app()->instance(ProviderRegistry::class, $providerRegistry);

        return new ProductSyncService(new ProviderResolver($providerRegistry));
    }

    private function bindZjmfProduct(int $upstreamProductId, int $stock): int
    {
        // 多个用例需要把商品挂到同一供应商下，才能验证覆盖是按上游商品精确生效的
        $this->supplierId ??= $this->insertSupplier('Zjmf Supplier');
        $this->pluginId ??= $this->insertPlugin('zjmf_finance', ProviderKey::ZJMF_FINANCE_API);
        $this->supplierBindingId ??= $this->insertSupplierBinding($this->supplierId, $this->pluginId, ProviderKey::ZJMF_FINANCE_API);

        $productId = $this->insertProduct($stock);
        $this->insertProductBinding($productId, $this->supplierBindingId, $this->pluginId, ProviderKey::ZJMF_FINANCE_API, (string) $upstreamProductId);

        return $productId;
    }

    private function useSqliteInMemoryDatabase(): void
    {
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        DB::purge('sqlite');
        app('db')->setDefaultConnection('sqlite');
        DB::connection('sqlite')->getPdo();
    }

    private function createTables(): void
    {
        Schema::connection('sqlite')->create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('product_type')->nullable();
            $table->json('pricing')->nullable();
            $table->decimal('setup_fee', 10, 2)->default(0);
            $table->json('config_options')->nullable();
            $table->json('purchase_requires')->nullable();
            $table->integer('stock')->default(-1);
            $table->unsignedTinyInteger('status')->default(1);
            $table->integer('auto_setup')->default(1);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('integration_plugins', function (Blueprint $table): void {
            $table->id();
            $table->string('domain', 32);
            $table->string('slug', 120);
            $table->string('plugin_key', 120);
            $table->string('name', 120);
            $table->string('version', 32)->default('1.0.0');
            $table->string('entry_class', 255);
            $table->unsignedTinyInteger('status')->default(0);
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('supplier_plugin_bindings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('supplier_id');
            $table->unsignedBigInteger('plugin_id');
            $table->string('provider_key', 120);
            $table->string('environment', 30)->default('production');
            $table->unsignedTinyInteger('status')->default(1);
            // 绑定写入方会落工单投递开关（工单上游投递功能引入），缺列会使同步更新失败
            $table->unsignedTinyInteger('ticket_delivery_enabled')->default(0);
            $table->integer('priority')->default(0);
            $table->string('base_url', 255)->nullable();
            $table->string('account_name', 120)->nullable();
            $table->json('config_json')->nullable();
            $table->longText('secret_json')->nullable();
            $table->json('has_secret_json')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->string('last_check_status', 30)->nullable();
            $table->string('last_check_error', 500)->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('product_upstream_bindings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('supplier_plugin_binding_id');
            $table->unsignedBigInteger('plugin_id');
            $table->string('provider_key', 120);
            $table->string('upstream_product_id', 120);
            $table->json('upstream_product_snapshot_json')->nullable();
            $table->json('option_schema_json')->nullable();
            $table->json('provision_policy_json')->nullable();
            $table->boolean('auto_setup')->default(false);
            $table->unsignedTinyInteger('status')->default(1);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_error', 500)->nullable();
            $table->timestamps();
        });
    }

    private function insertSupplier(string $name): int
    {
        return (int) DB::table('suppliers')->insertGetId([
            'name' => $name,
            'code' => strtolower(str_replace(' ', '-', $name)),
            'status' => 1,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertPlugin(string $slug, string $providerKey): int
    {
        return (int) DB::table('integration_plugins')->insertGetId([
            'domain' => 'upstream',
            'slug' => $slug,
            'plugin_key' => $providerKey,
            'name' => $slug,
            'version' => '1.0.0',
            'entry_class' => FakeStockCatalogDriver::class,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSupplierBinding(int $supplierId, int $pluginId, string $providerKey): int
    {
        return (int) DB::table('supplier_plugin_bindings')->insertGetId([
            'supplier_id' => $supplierId,
            'plugin_id' => $pluginId,
            'provider_key' => $providerKey,
            'environment' => 'production',
            'status' => 1,
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertProduct(int $stock): int
    {
        return (int) DB::table('products')->insertGetId([
            'name' => 'Stock Product',
            'product_type' => 'server',
            'pricing' => json_encode(['monthly' => '99.00']),
            'setup_fee' => '0.00',
            'config_options' => json_encode([]),
            'purchase_requires' => json_encode([]),
            'stock' => $stock,
            'status' => 1,
            'auto_setup' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertProductBinding(
        int $productId,
        int $supplierBindingId,
        int $pluginId,
        string $providerKey,
        string $upstreamProductId,
    ): void {
        DB::table('product_upstream_bindings')->insert([
            'product_id' => $productId,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'plugin_id' => $pluginId,
            'provider_key' => $providerKey,
            'upstream_product_id' => $upstreamProductId,
            'auto_setup' => 1,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

final class RecordingStockCatalog implements ProvidesConsoleCatalog
{
    /**
     * @var list<list<int>>
     */
    public array $requestedProductIds = [];

    /**
     * @param  array<int, int>  $stocks
     */
    public function __construct(private readonly array $stocks) {}

    public function fetchBatchProductStocks(Supplier $supplier, array $productIds, int $chunkSize = 8): array
    {
        $this->requestedProductIds[] = array_values($productIds);

        return collect($productIds)
            ->mapWithKeys(fn (int $productId): array => [
                $productId => ['stock' => $this->stocks[$productId] ?? null],
            ])
            ->all();
    }

    /**
     * 缺价自动补价链路会在库存同步前拉取上游配置项；
     * 本用例只关心库存，返回空表即可（同步器对空结果按「无缺价」处理）。
     */
    public function fetchBatchProductConfigOptions(Supplier $supplier, array $productIds, int $chunkSize = 8, ?float $deadline = null): array
    {
        unset($supplier, $chunkSize, $deadline);

        return collect($productIds)
            ->mapWithKeys(fn (int $productId): array => [
                $productId => [],
            ])
            ->all();
    }

    public function getProductCatalog(Supplier $supplier): array
    {
        return ['products' => []];
    }
}

final class FakeStockCatalogDriver implements UpstreamDriver
{
    public function __construct(private readonly RecordingStockCatalog $catalog) {}

    public function key(): string
    {
        return ProviderKey::ZJMF_FINANCE_API;
    }

    public function label(): string
    {
        return 'Fake Stock Catalog';
    }

    public function capabilities(): array
    {
        return [ProvidesConsoleCatalog::class];
    }

    public function supports(string $capability): bool
    {
        return $capability === ProvidesConsoleCatalog::class;
    }

    public function resolve(string $capability): ?object
    {
        return $this->supports($capability) ? $this->catalog : null;
    }
}
