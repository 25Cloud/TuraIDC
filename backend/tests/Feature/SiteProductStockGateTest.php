<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\OrderStatus;
use App\Constants\OrderType;
use App\Constants\ProductType;
use App\Exceptions\BusinessException;
use App\Jobs\RunHeartbeatTaskJob;
use App\Models\FirstProductGroup;
use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\ThirdProductGroup;
use App\Services\Automation\Heartbeat\HeartbeatTaskRegistry;
use App\Services\ProductCatalog\ProductCatalogService;
use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\Contracts\UpstreamDriver;
use App\Services\Upstream\ProviderKey;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use App\Support\UpstreamStockCache;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * 浏览路径的库存闸门（StockGate）。
 *
 * 背景：上游库存缓存最短 15 秒，一次真实拉取实测 1.5~3 秒。此前 `/stock` 在缓存未命中时
 * 同步打上游，商品详情页的库存区就长期停在「加载中」；上游挂掉时更糟——每个请求都去
 * 重试一次，页面越刷越慢，还会被上游当成攻击。
 *
 * 本用例锁死三条口径：
 * 1. 浏览路径（商品目录 / 详情 / `/stock`）在上游必然抛错时仍能正常渲染，且**一次上游都不打**；
 * 2. 缓存过期后浏览路径只回退本地快照，仍然零上游调用；
 * 3. 快照回写落在上游原始值上，不写已扣过在途预留的净值（否则预留会被扣两次）。
 */
class SiteProductStockGateTest extends TestCase
{
    private const UPSTREAM_PRODUCT_ID = 1001;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useSqliteInMemoryDatabase();
        $this->createSchema();
        $this->resetModelCaches();
    }

    protected function tearDown(): void
    {
        foreach ([
            'services',
            'orders',
            'product_upstream_bindings',
            'supplier_plugin_bindings',
            'integration_plugins',
            'suppliers',
            'personal_access_tokens',
            'users',
            'agent_group_discounts',
            'agent_groups',
            'product_discount_groups',
            'products',
            'third_product_groups',
            'second_product_groups',
            'first_product_groups',
            'settings',
        ] as $table) {
            Schema::connection('sqlite')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_browse_renders_and_never_calls_upstream_when_provider_always_fails(): void
    {
        $catalog = new GateRecordingCatalog(failure: new \RuntimeException('Connection timed out after 25000 ms'));
        $this->bindUpstreamCatalog($catalog);

        [$productId, $groupId] = $this->createSaleProduct(stock: 4);

        // 详情：上游必然抛错，详情照样 200 且关键字段齐全
        $detail = $this->getJson('/api/v2/site/products/'.$productId)
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertSame($productId, (int) $detail->json('data.product.id'));
        $this->assertSame(4, (int) $detail->json('data.product.stock'));
        $this->assertSame('monthly', (string) $detail->json('data.product.pricing_entries.0.cycle'));
        $this->assertIsArray($detail->json('data.product.config_options'));
        $this->assertArrayHasKey('description_html', (array) $detail->json('data.product'));

        // 目录：同样 200，列表里能看到这台商品
        $list = $this->getJson('/api/v2/site/products?effective_product_group_id='.$groupId.'&page=1&page_size=20')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $listedIds = collect((array) $list->json('data.list'))->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->assertContains($productId, $listedIds, '目录必须照常渲染出上架商品');

        // /stock：上游不可用时回退本地快照，不报错
        $this->getJson('/api/v2/site/products/'.$productId.'/stock')
            ->assertOk()
            ->assertJsonPath('data.product_id', $productId)
            ->assertJsonPath('data.stock', 4);

        $this->assertSame(0, $catalog->stockCalls, '浏览路径（目录 / 详情 / stock）不得发起任何上游调用');
    }

    public function test_stock_endpoint_serves_stale_cache_then_falls_back_to_snapshot_after_ttl(): void
    {
        $catalog = new GateRecordingCatalog(stocks: [self::UPSTREAM_PRODUCT_ID => 5]);
        $this->bindUpstreamCatalog($catalog);

        [$productId] = $this->createSaleProduct(stock: 5);

        // 后台刷新（严格路径）打一次上游并写入新鲜 / 陈旧两层缓存
        $this->strictLiveStock($productId);
        $this->assertSame(1, $catalog->stockCalls);

        // 快照被人为改旧，用来证明「命中缓存时用的是缓存，不是快照」
        DB::table('products')->where('id', $productId)->update(['stock' => 3]);

        // 新鲜层（15s）与展示层（60s）过期，陈旧层（900s）仍在 → 用陈旧值 5，不打上游
        $this->travel(UpstreamStockCache::FRESH_TTL_SECONDS + 120)->seconds();
        $this->getJson('/api/v2/site/products/'.$productId.'/stock')
            ->assertOk()
            ->assertJsonPath('data.stock', 5);
        $this->assertSame(1, $catalog->stockCalls, '陈旧层命中时不得回打上游');

        // 陈旧层也过期 → 只剩本地快照，依旧零上游调用。
        // 上一步的回写把快照刷成了 5，这里再改旧一次，才能区分「回退快照」与「命中缓存」。
        $this->travel(UpstreamStockCache::STALE_TTL_SECONDS + 60)->seconds();
        DB::table('products')->where('id', $productId)->update(['stock' => 3]);
        $this->getJson('/api/v2/site/products/'.$productId.'/stock')
            ->assertOk()
            ->assertJsonPath('data.stock', 3);
        $this->assertSame(1, $catalog->stockCalls, '缓存全过期后浏览路径只能回退快照，不得发起上游调用');
    }

    public function test_stock_endpoint_writes_raw_snapshot_without_double_deducting_reservations(): void
    {
        $catalog = new GateRecordingCatalog(stocks: [self::UPSTREAM_PRODUCT_ID => 6]);
        $this->bindUpstreamCatalog($catalog);

        [$productId] = $this->createSaleProduct(stock: 9);
        // 一笔在途订单占 2 台：可售量必须是 6 - 2 = 4
        $this->insertOpenOrder($productId, 2);

        $this->strictLiveStock($productId);
        $this->assertSame(1, $catalog->stockCalls);

        // 模拟快照再次漂移，让 /stock 真的走一次回写
        DB::table('products')->where('id', $productId)->update(['stock' => 9]);
        $this->travel(120)->seconds();

        $this->getJson('/api/v2/site/products/'.$productId.'/stock')
            ->assertOk()
            ->assertJsonPath('data.stock', 4);

        $this->assertSame(1, $catalog->stockCalls, '/stock 命中缓存时不得回打上游');
        $this->assertSame(
            6,
            (int) Product::query()->findOrFail($productId)->stock,
            '快照回写的必须是上游原始值；写净值会把在途预留扣两次'
        );

        // 再取一次：既不再打上游，也不把快照再减一次
        $this->getJson('/api/v2/site/products/'.$productId.'/stock')
            ->assertOk()
            ->assertJsonPath('data.stock', 4);
        $this->assertSame(1, $catalog->stockCalls);
        $this->assertSame(6, (int) Product::query()->findOrFail($productId)->stock);
    }

    public function test_strict_checkout_fails_fast_inside_failure_backoff_window(): void
    {
        $catalog = new GateRecordingCatalog(failure: new \RuntimeException('Connection timed out after 25000 ms'));
        $this->bindUpstreamCatalog($catalog);

        [$productId] = $this->createSaleProduct(stock: 5);

        // 第一次：真的打了一次上游并失败，下单必须被拦
        $first = $this->strictStockFailure($productId);
        $this->assertSame('商品的上游供应商响应超时，请稍后重试', $first->getMessage());
        $this->assertSame(1, $catalog->stockCalls);

        // 退避窗口内：不再发请求，但同样不许放行
        $second = $this->strictStockFailure($productId);
        $this->assertSame('暂时无法获取上游库存，请稍后重试', $second->getMessage());
        $this->assertSame(1, $catalog->stockCalls, '失败退避窗口内不得重复发起上游请求');

        // 退避窗口过去后允许重试
        $this->travel(UpstreamStockCache::FAILURE_BACKOFF_SECONDS + 1)->seconds();
        $this->strictStockFailure($productId);
        $this->assertSame(2, $catalog->stockCalls);
    }

    /**
     * 陈旧命中要「立刻返回 + 推一次后台刷新」：响应不等上游，任务走队列。
     */
    public function test_stock_endpoint_serving_stale_dispatches_exactly_one_refresh_job(): void
    {
        $catalog = new GateRecordingCatalog(stocks: [self::UPSTREAM_PRODUCT_ID => 5]);
        $this->bindUpstreamCatalog($catalog);
        $this->fakeHeartbeatRegistry();

        [$productId] = $this->createSaleProduct(stock: 5);

        // 后台刷新打一次上游，写入新鲜/陈旧两层并盖上「成功同步」时间
        $this->strictLiveStock($productId);
        $this->assertSame(1, $catalog->stockCalls);

        // 测试环境默认 QUEUE_CONNECTION=sync，这里显式换成真队列驱动：
        // 同步驱动下代码会主动放弃推送（见下一个用例），那样测不到去重锁。
        config()->set('queue.default', 'database');
        Queue::fake();

        // 新鲜层（15s）与展示层（60s）过期，陈旧层仍在：这就是要补刷新的场景
        $this->travel(UpstreamStockCache::FRESH_TTL_SECONDS + 120)->seconds();

        $this->getJson('/api/v2/site/products/'.$productId.'/stock')
            ->assertOk()
            ->assertJsonPath('data.product_id', $productId)
            ->assertJsonPath('data.stock', 5);

        $this->assertSame(1, $catalog->stockCalls, '/stock 命中陈旧缓存时不得在请求内打上游');
        Queue::assertPushed(RunHeartbeatTaskJob::class, 1);
        Queue::assertPushed(
            RunHeartbeatTaskJob::class,
            fn (RunHeartbeatTaskJob $job): bool => $job->taskKey === 'supplier-product-stock-sync'
        );
    }

    /**
     * 同一供应商 60 秒内只推一次：第二个请求被去重锁挡住，不再进队列。
     */
    public function test_repeated_stale_reads_only_dispatch_once_per_supplier_window(): void
    {
        $catalog = new GateRecordingCatalog(stocks: [self::UPSTREAM_PRODUCT_ID => 5]);
        $this->bindUpstreamCatalog($catalog);
        $this->fakeHeartbeatRegistry();

        [$productId] = $this->createSaleProduct(stock: 5);

        $this->strictLiveStock($productId);
        config()->set('queue.default', 'database');
        Queue::fake();

        $this->travel(UpstreamStockCache::FRESH_TTL_SECONDS + 120)->seconds();
        $this->getJson('/api/v2/site/products/'.$productId.'/stock')->assertOk();
        Queue::assertPushed(RunHeartbeatTaskJob::class, 1);

        // 把 60 秒「成品响应」缓存清掉，强制第二个请求重新走一次库存叠加，
        // 否则它会停在展示缓存里，测不到去重锁。
        Cache::store('redis_volatile')->forget('site_product_stock:'.$productId);
        $this->travel(5)->seconds();

        $this->getJson('/api/v2/site/products/'.$productId.'/stock')
            ->assertOk()
            ->assertJsonPath('data.stock', 5);

        Queue::assertPushed(RunHeartbeatTaskJob::class, 1);
        $this->assertSame(1, $catalog->stockCalls, '两次浏览都不得在请求内打上游');
    }

    /**
     * 退避窗口内不推：上游刚失败，推了也只会立刻再失败一次。
     */
    public function test_stale_read_does_not_dispatch_inside_failure_backoff_window(): void
    {
        $catalog = new GateRecordingCatalog(stocks: [self::UPSTREAM_PRODUCT_ID => 5]);
        $this->bindUpstreamCatalog($catalog);

        [$productId] = $this->createSaleProduct(stock: 5);

        // 先成功取一次，留下可用的陈旧值
        $this->strictLiveStock($productId);
        $this->travel(UpstreamStockCache::FRESH_TTL_SECONDS + 120)->seconds();

        // 让上游开始失败，并走一次严格校验把退避标记打上
        $catalog->failWith(new \RuntimeException('Connection timed out after 25000 ms'));
        $this->strictStockFailure($productId);
        $this->assertSame(2, $catalog->stockCalls);

        Queue::fake();
        Cache::store('redis_volatile')->forget('site_product_stock:'.$productId);

        $this->getJson('/api/v2/site/products/'.$productId.'/stock')
            ->assertOk()
            ->assertJsonPath('data.stock', 5);

        Queue::assertNothingPushed();
        $this->assertSame(2, $catalog->stockCalls, '退避窗口内浏览路径不得打上游');
    }

    /**
     * 同步队列驱动下 dispatch 会在请求内直接跑整批同步：这条路必须直接放弃，宁可等心跳。
     */
    public function test_stock_endpoint_skips_dispatch_when_queue_driver_is_sync(): void
    {
        $catalog = new GateRecordingCatalog(stocks: [self::UPSTREAM_PRODUCT_ID => 5]);
        $this->bindUpstreamCatalog($catalog);

        [$productId] = $this->createSaleProduct(stock: 5);

        $this->strictLiveStock($productId);
        Queue::fake();
        config()->set('queue.default', 'sync');

        $this->travel(UpstreamStockCache::FRESH_TTL_SECONDS + 120)->seconds();
        $this->getJson('/api/v2/site/products/'.$productId.'/stock')
            ->assertOk()
            ->assertJsonPath('data.stock', 5);

        Queue::assertNothingPushed();
        $this->assertSame(1, $catalog->stockCalls);
    }

    private function strictStockFailure(int $productId): BusinessException
    {
        $product = Product::query()->findOrFail($productId);

        try {
            app(ProductCatalogService::class)->assertProductCanBeProvisioned($product, 1);
        } catch (BusinessException $exception) {
            return $exception;
        }

        $this->fail('上游库存不可用时，严格校验必须抛业务异常');
    }

    private function strictLiveStock(int $productId): void
    {
        app(ProductCatalogService::class)->applyLiveStockToProduct(
            Product::query()->findOrFail($productId),
            true
        );
    }

    private function insertOpenOrder(int $productId, int $quantity): void
    {
        DB::table('orders')->insert([
            'product_id' => $productId,
            'service_id' => null,
            'type' => OrderType::NEW,
            'status' => OrderStatus::PENDING,
            'quantity' => $quantity,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{0: int, 1: int} [商品 id, 三级分组 id]
     */
    private function createSaleProduct(int $stock): array
    {
        $suffix = bin2hex(random_bytes(4));
        $rootGroup = $this->createSiteRootGroup('vps', 'Stock Gate Root '.$suffix, 'stock-gate-root-'.$suffix);
        $childGroup = $this->createSiteChildGroup($rootGroup, 'Stock Gate Leaf '.$suffix, 'stock-gate-leaf-'.$suffix);

        $product = Product::query()->create([
            ...$this->productGroupFields($childGroup),
            'name' => '库存闸门商品 '.$suffix,
            'product_type' => 'vps',
            'pricing' => ['monthly' => '20.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => $stock,
            'status' => 1,
            'sort_order' => 0,
            'provision_module' => null,
            'auto_setup' => 0,
        ]);

        $supplierId = $this->insertSupplier('Stock Gate Supplier');
        $pluginId = $this->insertPlugin('zjmf_finance');
        $bindingId = $this->insertSupplierBinding($supplierId, $pluginId);

        DB::table('product_upstream_bindings')->insert([
            'product_id' => (int) $product->id,
            'supplier_plugin_binding_id' => $bindingId,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'upstream_product_id' => (string) self::UPSTREAM_PRODUCT_ID,
            'auto_setup' => 1,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [(int) $product->id, (int) $childGroup->id];
    }

    private function bindUpstreamCatalog(GateRecordingCatalog $catalog): void
    {
        $registry = new ProviderRegistry([new GateCatalogDriver($catalog)]);
        // ProviderResolver 是单例：两个都换成假实例，避免解析顺序影响用例
        app()->instance(ProviderRegistry::class, $registry);
        app()->instance(ProviderResolver::class, new ProviderResolver($registry));
    }

    /**
     * 任务注册表替身。
     *
     * 真实的 HeartbeatTaskRegistry 会去装配核心任务 Provider，那需要 settings / automation
     * 一整套表；本用例只关心「浏览路径要不要推、推几次」，任务键是否注册用替身表达。
     */
    private function fakeHeartbeatRegistry(): void
    {
        $registry = Mockery::mock(HeartbeatTaskRegistry::class);
        $registry->shouldReceive('supports')
            ->andReturnUsing(fn (string $taskKey): bool => $taskKey === 'supplier-product-stock-sync');

        app()->instance(HeartbeatTaskRegistry::class, $registry);
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
        DB::reconnect('sqlite');
        app('db')->setDefaultConnection('sqlite');

        DB::connection('sqlite')->getPdo();
    }

    private function resetModelCaches(): void
    {
        Cache::flush();
        ProductType::resetCache();
        $this->resetStaticProperty(Setting::class, 'tableExistsCache', []);
    }

    private function createSchema(): void
    {
        Schema::connection('sqlite')->create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group_key', 100);
            $table->string('item_key', 100);
            $table->text('item_value')->nullable();
            $table->unique(['group_key', 'item_key'], 'settings_group_item_unique');
        });

        Schema::connection('sqlite')->create('first_product_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->nullable();
            $table->string('product_type', 50)->nullable();
            $table->string('name', 100);
            $table->string('slug', 100)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('icon', 100)->nullable();
            $table->string('banner_image', 255)->nullable();
            $table->unsignedTinyInteger('is_visible')->default(1);
            $table->unsignedTinyInteger('is_system')->default(0);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('second_product_groups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('first_product_group_id');
            $table->string('name', 100);
            $table->string('slug', 100)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('banner_image', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->unsignedTinyInteger('is_visible')->default(1);
            $table->string('product_type', 50)->nullable();
            $table->unsignedTinyInteger('level')->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('third_product_groups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('second_product_group_id');
            $table->string('name', 100);
            $table->string('slug', 100)->nullable();
            $table->string('description', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->unsignedTinyInteger('is_visible')->default(1);
            $table->string('product_type', 50)->nullable();
            $table->unsignedTinyInteger('level')->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_group_id')->nullable();
            $table->unsignedBigInteger('product_discount_group_id')->nullable();
            $table->string('service_type_code', 50)->nullable();
            $table->string('name', 190)->nullable();
            $table->string('product_type', 50)->nullable();
            $table->string('custom_display_name', 190)->nullable();
            $table->text('remark')->nullable();
            $table->json('pricing')->nullable();
            $table->decimal('setup_fee', 12, 2)->default(0);
            $table->json('config_options')->nullable();
            $table->json('purchase_requires')->nullable();
            $table->integer('stock')->default(0);
            $table->unsignedTinyInteger('status')->default(1);
            $table->integer('sort_order')->default(0);
            $table->string('provision_module', 100)->nullable();
            $table->unsignedTinyInteger('auto_setup')->default(0);
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->unsignedBigInteger('supplier_product_id')->nullable();
            $table->string('supplier_product_name', 190)->nullable();
            // 快照回写会写这一列（存在性按 Schema::hasColumn 判断）
            $table->timestamp('stock_synced_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        // 站点资源层会经 AgentDiscountService 触碰代理折扣三表
        Schema::connection('sqlite')->create('product_discount_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 50)->nullable();
            $table->decimal('min_discount_rate', 5, 2)->nullable();
            $table->decimal('cost_rate', 5, 2)->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            $table->integer('sort_order')->default(0);
            $table->string('remark', 255)->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('agent_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->unsignedTinyInteger('status')->default(1);
            $table->decimal('default_discount_rate', 5, 2)->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('agent_group_discounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('agent_group_id');
            $table->unsignedBigInteger('product_discount_group_id');
            $table->decimal('discount_rate', 5, 2);
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('code')->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            $table->integer('sort_order')->default(0);
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

        // 在途预留统计会扫这两张表
        Schema::connection('sqlite')->create('services', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('status')->default(0);
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->unsignedTinyInteger('type')->default(0);
            $table->unsignedTinyInteger('status')->default(0);
            $table->integer('quantity')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        // 站点资源层对已登录用户会读用户表；游客路径也要保证 morph 表存在
        Schema::connection('sqlite')->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('email', 191)->nullable();
            $table->string('password', 255);
            $table->string('phone', 50)->nullable();
            $table->unsignedTinyInteger('status')->default(1);
            $table->decimal('balance', 12, 2)->default(0);
            $table->string('nickname', 100)->nullable();
            $table->string('company', 100)->nullable();
            $table->string('qq', 50)->nullable();
            $table->text('admin_note')->nullable();
            $table->string('referral_code', 50)->nullable();
            $table->unsignedBigInteger('referrer_user_id')->nullable();
            $table->timestamp('referred_at')->nullable();
            $table->unsignedBigInteger('member_level_id')->nullable();
            $table->decimal('total_sales_amount', 12, 2)->default(0);
            $table->unsignedTinyInteger('is_verified')->default(0);
            $table->string('real_name', 100)->nullable();
            $table->text('id_card')->nullable();
            $table->unsignedTinyInteger('verification_status')->default(0);
            $table->text('verification_message')->nullable();
            $table->string('verification_certify_id', 100)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('alipay_real_name', 100)->nullable();
            $table->string('alipay_account', 190)->nullable();
            $table->unsignedTinyInteger('login_email_alert')->default(0);
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    private function createSiteRootGroup(string $productType, string $name, string $slug): SecondProductGroup
    {
        $firstGroup = FirstProductGroup::query()->create([
            'code' => $productType,
            'product_type' => ProductType::normalizeBusinessValueFromMenuCode($productType),
            'name' => ProductType::labelOf($productType),
            'slug' => $productType,
            'description' => '',
            'sort_order' => 0,
            'is_visible' => 1,
            'is_system' => 1,
        ]);

        return SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => $name,
            'slug' => $slug,
            'description' => '',
            'banner_image' => null,
            'sort_order' => 0,
            'is_visible' => 1,
        ]);
    }

    private function createSiteChildGroup(SecondProductGroup $parent, string $name, string $slug): ThirdProductGroup
    {
        return ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $parent->id,
            'name' => $name,
            'slug' => $slug,
            'description' => '',
            'sort_order' => 0,
            'is_visible' => 1,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function productGroupFields(ThirdProductGroup $group): array
    {
        $firstGroup = $group->secondProductGroup?->firstProductGroup;

        return [
            'product_group_id' => (int) $group->id,
            'service_type_code' => ProductType::businessValueForFirstGroup($firstGroup, $firstGroup?->product_type),
        ];
    }

    private function insertSupplier(string $name): int
    {
        return (int) DB::table('suppliers')->insertGetId([
            'name' => $name,
            'code' => 'stock-gate',
            'status' => 1,
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertPlugin(string $slug): int
    {
        return (int) DB::table('integration_plugins')->insertGetId([
            'domain' => 'upstream',
            'slug' => $slug,
            'plugin_key' => ProviderKey::ZJMF_FINANCE_API,
            'name' => $slug,
            'version' => '1.0.0',
            'entry_class' => GateCatalogDriver::class,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSupplierBinding(int $supplierId, int $pluginId): int
    {
        return (int) DB::table('supplier_plugin_bindings')->insertGetId([
            'supplier_id' => $supplierId,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'environment' => 'production',
            'status' => 1,
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function resetStaticProperty(string $className, string $propertyName, mixed $value): void
    {
        $reflection = new \ReflectionClass($className);
        $property = $reflection->getProperty($propertyName);
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }
}

/**
 * 只记账、不联网的上游库存能力：断言「上游到底被调了几次」。
 */
final class GateRecordingCatalog implements ProvidesConsoleCatalog
{
    public int $stockCalls = 0;

    /**
     * @var list<list<int>>
     */
    public array $requestedProductIds = [];

    /**
     * @param  array<int, int>  $stocks
     */
    public function __construct(
        private readonly array $stocks = [],
        private ?\Throwable $failure = null,
    ) {}

    /** 让后续调用开始失败（模拟上游中途挂掉） */
    public function failWith(\Throwable $failure): void
    {
        $this->failure = $failure;
    }

    public function fetchBatchProductStocks(Supplier $supplier, array $productIds, int $chunkSize = 8): array
    {
        unset($supplier, $chunkSize);

        $this->stockCalls++;
        $this->requestedProductIds[] = array_values($productIds);

        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }

        return collect($productIds)
            ->mapWithKeys(fn (int $productId): array => [
                $productId => ['stock' => $this->stocks[$productId] ?? null],
            ])
            ->all();
    }

    public function fetchBatchProductConfigOptions(Supplier $supplier, array $productIds, int $chunkSize = 8, ?float $deadline = null): array
    {
        unset($supplier, $productIds, $chunkSize, $deadline);

        return [];
    }

    public function getProductCatalog(Supplier $supplier): array
    {
        unset($supplier);

        return ['products' => []];
    }
}

final class GateCatalogDriver implements UpstreamDriver
{
    public function __construct(private readonly GateRecordingCatalog $catalog) {}

    public function key(): string
    {
        return ProviderKey::ZJMF_FINANCE_API;
    }

    public function label(): string
    {
        return 'Stock Gate Test Driver';
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
