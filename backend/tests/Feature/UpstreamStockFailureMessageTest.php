<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * 上游库存拉取失败的归类文案与留痕。
 *
 * 修复前 strict（前台下单）路径直接抛固定文案「暂时无法获取上游库存，请稍后重试」
 * 且不写日志：一次前台失败在日志里没有任何痕迹，运维只能靠猜。
 */
class UpstreamStockFailureMessageTest extends TestCase
{
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
            'orders',
            'services',
        ] as $table) {
            Schema::connection('sqlite')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_it_exposes_typed_upstream_failure_reason_instead_of_one_generic_message(): void
    {
        $cases = [
            '供应商接口返回异常页面，未解析到有效数据（HTTP 404）' => '商品的上游接口暂时不可用，请稍后重试',
            '供应商接口返回异常页面，未解析到有效数据（HTTP 500）' => '暂时无法获取上游库存，请稍后重试',
            '未授权（HTTP 401）' => '商品的上游供应商认证失败，暂时无法查询库存，请联系管理员',
            '供应商接口地址未配置' => '商品的上游供应商尚未完成配置，暂时无法查询库存，请联系管理员',
            'Connection timed out after 25000 ms' => '商品的上游供应商响应超时，请稍后重试',
            'HTTP 429' => '商品的上游供应商请求过于频繁，请稍后重试',
        ];

        $service = app(ProductSyncService::class);

        foreach ($cases as $upstreamMessage => $expected) {
            $this->assertSame(
                $expected,
                $this->failureMessageFor($service, new \RuntimeException($upstreamMessage)),
                "Unexpected message for upstream error [{$upstreamMessage}]."
            );
        }
    }

    public function test_it_never_leaks_upstream_endpoint_or_credential_details_to_users(): void
    {
        $service = app(ProductSyncService::class);
        $leaky = new \RuntimeException(
            'cURL error 7: Failed to connect to https://10.0.0.5:6379 port, api_key=sk-secret-value rejected'
        );

        $message = $this->failureMessageFor($service, $leaky);

        $this->assertStringNotContainsString('10.0.0.5', $message);
        $this->assertStringNotContainsString('sk-secret-value', $message);
        $this->assertStringNotContainsString('redis', $message);
    }

    public function test_it_records_the_upstream_failure_reason_in_logs_even_on_strict_path(): void
    {
        $upstreamMessage = '供应商接口返回异常页面，未解析到有效数据（HTTP 404）';

        // strict 路径此前抛异常且不写日志：一次前台失败在日志里没有任何痕迹。
        Log::shouldReceive('warning')
            ->atLeast()->once()
            ->with('[商品库存] 拉取上游明细库存失败', Mockery::on(
                fn (array $context): bool => str_contains((string) ($context['message'] ?? ''), 'HTTP 404')
                    && ($context['strict'] ?? false) === true
                    && ($context['supplier_name'] ?? '') === 'MD'
            ));

        $providerRegistry = new ProviderRegistry([
            new StockFailureTestDriver(new StockFailureThrowingCatalog($upstreamMessage)),
        ]);
        app()->instance(ProviderRegistry::class, $providerRegistry);
        $service = new ProductSyncService(new ProviderResolver($providerRegistry));

        $supplierId = $this->insertSupplier('MD');
        $pluginId = $this->insertPlugin('zjmf_finance');
        $supplierBindingId = $this->insertSupplierBinding($supplierId, $pluginId);
        $productId = $this->insertProduct();
        $this->insertProductBinding($productId, $supplierBindingId, $pluginId, '161');

        $thrown = null;

        try {
            $service->applyLiveStockToProduct(Product::query()->findOrFail($productId), true);
        } catch (BusinessException $exception) {
            $thrown = $exception;
        }

        $this->assertNotNull($thrown, 'Strict stock check must fail when upstream is unavailable.');
        $this->assertSame('商品的上游接口暂时不可用，请稍后重试', $thrown->getMessage());
    }

    private function failureMessageFor(ProductSyncService $service, \Throwable $exception): string
    {
        $method = new \ReflectionMethod(ProductSyncService::class, 'buildUpstreamStockFailureMessage');
        $method->setAccessible(true);

        return (string) $method->invoke($service, $exception);
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

        // 下单占用校验会统计同商品的在途订单，这里只需要空表让查询能跑通。
        Schema::connection('sqlite')->create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->unsignedBigInteger('service_id')->nullable();
            $table->unsignedTinyInteger('type')->default(0);
            $table->unsignedTinyInteger('status')->default(0);
            $table->integer('quantity')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('services', function (Blueprint $table): void {
            $table->id();
            $table->unsignedTinyInteger('status')->default(0);
            $table->timestamps();
        });
    }

    private function insertSupplier(string $name): int
    {
        return (int) DB::table('suppliers')->insertGetId([
            'name' => $name,
            'code' => 'md',
            'status' => 1,
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
            'entry_class' => StockFailureTestDriver::class,
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
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertProduct(): int
    {
        return (int) DB::table('products')->insertGetId([
            'name' => 'US 9929 2C4G',
            'product_type' => 'server',
            'pricing' => json_encode(['monthly' => '99.00']),
            'setup_fee' => '0.00',
            'config_options' => json_encode([]),
            'purchase_requires' => json_encode([]),
            'stock' => 1,
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
        string $upstreamProductId
    ): void {
        DB::table('product_upstream_bindings')->insert([
            'product_id' => $productId,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'upstream_product_id' => $upstreamProductId,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

final class StockFailureThrowingCatalog implements ProvidesConsoleCatalog
{
    public function __construct(private readonly string $message) {}

    public function fetchBatchProductStocks(Supplier $supplier, array $productIds, int $chunkSize = 8): array
    {
        unset($supplier, $productIds, $chunkSize);

        throw new BusinessException($this->message);
    }

    public function fetchBatchProductConfigOptions(Supplier $supplier, array $productIds, int $chunkSize = 8, ?float $deadline = null): array
    {
        unset($supplier, $productIds, $chunkSize, $deadline);

        return [];
    }

    public function getProductCatalog(Supplier $supplier): array
    {
        unset($supplier);

        return ['groups' => [], 'products' => []];
    }

    public function supports(string $capability): bool
    {
        unset($capability);

        return true;
    }
}

final class StockFailureTestDriver implements UpstreamDriver
{
    public function __construct(private readonly ProvidesConsoleCatalog $catalog) {}

    public function key(): string
    {
        return ProviderKey::ZJMF_FINANCE_API;
    }

    public function label(): string
    {
        return 'Stock Failure Test Driver';
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
