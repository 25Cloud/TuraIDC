<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FirstProductGroup;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Admin\V2\AdminConfigurationV2QueryService;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\ProductCatalog\InstanceSpecCatalogService;
use App\Services\ProductCatalog\ProductDisplayNameResolver;
use App\Services\ProductCatalog\ProductSyncService;
use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\Contracts\UpstreamDriver;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use App\Services\Upstream\ProviderKey;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SupplierBatchConnectRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(PluginFileLoader::class)->ensureLoaded(
            app(PluginScanner::class)->requireManifest('upstream', 'zjmf_finance')
        );
        $this->activateIntegrationPluginForTest('upstream', 'zjmf_finance');
    }

    public function test_bulk_connect_supplier_products_creates_local_products_from_upstream_catalog(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $supplier = Supplier::query()->create([
            'name' => 'Batch Connect Supplier '.$suffix,
            'code' => 'batch-connect-'.$suffix,
            'interface_type' => ProviderKey::ZJMF_FINANCE_API,
            'api_url' => 'https://supplier-'.$suffix.'.example.com',
            'api_username' => 'demo',
            'api_key' => 'secret',
            'status' => 1,
            'sort_order' => 1,
        ]);
        $this->bindSupplierToZjmf($supplier);

        $firstGroup = FirstProductGroup::query()->firstOrCreate(
            ['code' => 'vps'],
            [
                'name' => 'VPS',
                'slug' => 'batch-connect-first-vps',
                'sort_order' => 0,
                'is_visible' => 1,
                'is_system' => 0,
                'legacy_product_type' => 'vps',
                'product_type' => 'cloud_server',
            ]
        );

        $supplierProductId = random_int(10000, 99999);
        $transport = $this->createMock(HostingPanelApiTransport::class);
        $transport->expects($this->once())
            ->method('getProductCatalog')
            ->with($this->callback(fn (Supplier $candidate): bool => (int) $candidate->id === (int) $supplier->id))
            ->willReturn([
                'groups' => [],
                'products' => [[
                    'id' => $supplierProductId,
                    'name' => '香港 CN2 2C4G '.$suffix,
                    'group_label' => '香港云服务器 / CN2',
                    'group_name' => 'CN2',
                    'first_group_name' => '香港云服务器',
                    'billingcycle' => 'monthly',
                    'product_price' => '99.00',
                    'monthly_price' => '99.00',
                    'setup_fee' => '10.00',
                    'stock' => 8,
                ]],
            ]);

        $service = new ProductSyncService($this->makeProviderResolver($transport));

        $result = $service->bulkConnectSupplierProducts($supplier, [
            'first_product_group_code' => 'vps',
            'first_product_group_id' => (int) $firstGroup->id,
            'second_product_group_name' => '香港云服务器 / CN2',
            'product_ids' => [$supplierProductId],
            'default_status' => 1,
            'default_auto_setup' => 1,
            'sync_config_options' => 0,
        ]);

        $this->assertSame(1, (int) ($result['created_count'] ?? 0));
        $this->assertSame(0, (int) ($result['updated_count'] ?? 0));
        $this->assertSame(0, (int) ($result['skipped_count'] ?? 0));
        $this->assertCount(1, $result['imported_products'] ?? []);

        /** @var Product|null $product */
        $product = Product::query()
            ->with('productGroup.secondProductGroup.firstProductGroup')
            ->join('product_upstream_bindings as pub', 'pub.product_id', '=', 'products.id')
            ->join('supplier_plugin_bindings as spb', 'spb.id', '=', 'pub.supplier_plugin_binding_id')
            ->where('spb.supplier_id', (int) $supplier->id)
            ->where('pub.upstream_product_id', (string) $supplierProductId)
            ->select('products.*')
            ->first();

        $this->assertNotNull($product);
        $this->assertSame('99.00', $product->pricing['monthly'] ?? null);
        $this->assertSame('cloud_server', $product->product_type);
        $this->assertSame('cloud_server', $product->service_type_code);
        $this->assertSame(1, (int) $product->status);
        $this->assertSame(1, (int) $product->auto_setup);
        $this->assertSame('2', (string) (($product->purchase_requires['upstream_default_config'] ?? [])['cpu'] ?? ''));
        $this->assertSame('4', (string) (($product->purchase_requires['upstream_default_config'] ?? [])['memory'] ?? ''));
        $this->assertNotNull($product->productGroup);
        $this->assertNotNull($product->productGroup?->secondProductGroup);
        $this->assertNotNull($product->productGroup?->secondProductGroup?->firstProductGroup);
        $this->assertSame((int) $firstGroup->id, (int) $product->productGroup?->secondProductGroup?->firstProductGroup?->id);
        $this->assertSame('香港云服务器 / CN2', $product->productGroup?->secondProductGroup?->name);

        $display = (new ProductDisplayNameResolver)->resolveForProduct($product);
        $this->assertSame('2 vCPU 4G', $display['product_display_name'] ?? null);
        $this->assertSame('2 vCPU 4G', $display['product_spec_display'] ?? null);
        $this->assertSame('2 vCPU 4G', $display['cpu_memory_display'] ?? null);
    }

    public function test_deleted_bound_product_releases_upstream_product_for_reconnect(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $supplier = Supplier::query()->create([
            'name' => 'Reconnect Supplier '.$suffix,
            'code' => 'reconnect-'.$suffix,
            'interface_type' => ProviderKey::ZJMF_FINANCE_API,
            'api_url' => 'https://supplier-'.$suffix.'.example.com',
            'api_username' => 'demo',
            'api_key' => 'secret',
            'status' => 1,
            'sort_order' => 1,
        ]);
        $this->bindSupplierToZjmf($supplier);

        $bindingId = (int) DB::table('supplier_plugin_bindings')
            ->where('supplier_id', (int) $supplier->id)
            ->value('id');
        $this->assertGreaterThan(0, $bindingId);

        // 供应商商品列表入口会校验 api_url / api_username / api_key 必填，补齐绑定凭据
        DB::table('supplier_plugin_bindings')->where('id', $bindingId)->update([
            'base_url' => 'https://supplier-'.$suffix.'.example.com',
            'account_name' => 'demo',
            'secret_json' => Crypt::encryptString((string) json_encode(['api_key' => 'secret'])),
            'has_secret_json' => json_encode(['api_key' => true]),
            'updated_at' => now(),
        ]);

        $pluginId = (int) DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where('plugin_key', ProviderKey::ZJMF_FINANCE_API)
            ->value('id');
        $this->assertGreaterThan(0, $pluginId);

        $supplierProductId = random_int(10000, 99999);
        $product = Product::query()->create([
            'name' => '高质量云电脑 2核 2GB A型 '.$suffix,
            'product_type' => 'cloud_server',
            'pricing' => ['monthly' => '99.00'],
            'status' => 1,
        ]);
        DB::table('product_upstream_bindings')->insert([
            'product_id' => (int) $product->id,
            'supplier_plugin_binding_id' => $bindingId,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'upstream_product_id' => (string) $supplierProductId,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transport = $this->createMock(HostingPanelApiTransport::class);
        $transport->method('getProductCatalog')->willReturn([
            'groups' => [],
            'products' => [[
                'id' => $supplierProductId,
                'name' => '高质量云电脑 2核 2GB A型',
                'group_label' => '云服务器',
                'billingcycle' => 'monthly',
                'product_price' => '99.00',
                'monthly_price' => '99.00',
                'setup_fee' => '0.00',
                'stock' => 8,
            ]],
        ]);
        $this->app->instance(ProviderResolver::class, $this->makeProviderResolver($transport));

        $service = app(AdminConfigurationV2QueryService::class);
        $connected = $service->supplierProducts($supplier);
        $this->assertSame(true, $connected['products'][0]['is_bound'] ?? null);
        $this->assertSame((int) $product->id, $connected['products'][0]['local_product_id'] ?? null);

        // 软删除本地商品后，上游商品应释放为「未对接」，允许重新导入/对接
        $product->delete();
        $released = $service->supplierProducts($supplier);
        $this->assertSame(false, $released['products'][0]['is_bound'] ?? null);
        $this->assertNull($released['products'][0]['local_product_id'] ?? null);

        // 重新对接应复活并更新同一软删商品，而不是新建重复商品
        $firstGroup = FirstProductGroup::query()->firstOrCreate(
            ['code' => 'vps'],
            [
                'name' => 'VPS',
                'slug' => 'reconnect-first-vps-'.$suffix,
                'sort_order' => 0,
                'is_visible' => 1,
                'is_system' => 0,
                'legacy_product_type' => 'vps',
                'product_type' => 'cloud_server',
            ]
        );
        $syncService = new ProductSyncService($this->makeProviderResolver($transport));
        $reconnected = $syncService->bulkConnectSupplierProducts($supplier, [
            'first_product_group_code' => 'vps',
            'first_product_group_id' => (int) $firstGroup->id,
            'second_product_group_name' => '云服务器',
            'product_ids' => [$supplierProductId],
            'default_status' => 1,
            'default_auto_setup' => 1,
            'sync_config_options' => 0,
        ]);

        $this->assertSame(0, (int) ($reconnected['created_count'] ?? -1));
        $this->assertSame(1, (int) ($reconnected['updated_count'] ?? 0));
        $this->assertSame(0, (int) ($reconnected['skipped_count'] ?? -1));
        $this->assertFalse($product->refresh()->trashed());
        $this->assertSame('cloud_server', $product->refresh()->product_type);
        $this->assertSame('99.00', $product->refresh()->pricing['monthly'] ?? null);
    }

    public function test_display_name_resolver_reads_cpu_and_memory_from_default_config_items(): void
    {
        $product = new Product([
            'id' => 999001,
            'name' => '通用共享',
            'product_type' => 'cloud_server',
            'purchase_requires' => [],
            'config_options' => [
                [
                    'field' => 'cpu',
                    'name' => 'CPU',
                    'default_value' => '-',
                    'sub' => [
                        [
                            'id' => 1,
                            'option_name_first' => '4',
                            'version' => '4核',
                            'hidden' => 0,
                        ],
                    ],
                ],
                [
                    'field' => 'memory',
                    'name' => '内存',
                    'default_value' => '-',
                    'sub' => [
                        [
                            'id' => 2,
                            'option_name_first' => '4096',
                            'version' => '4G',
                            'hidden' => 0,
                        ],
                    ],
                ],
            ],
        ]);

        $display = (new ProductDisplayNameResolver)->resolveForProduct($product);

        $this->assertSame('4 vCPU 4G', $display['product_display_name'] ?? null);
        $this->assertSame('4 vCPU 4G', $display['product_spec_display'] ?? null);
        $this->assertSame('4 vCPU 4G', $display['cpu_memory_display'] ?? null);
    }

    public function test_display_name_resolver_keeps_custom_base_name_without_cpu_memory_slug(): void
    {
        $product = new Product([
            'id' => 999002,
            'custom_display_name' => 'gscs',
            'product_type' => 'cloud_server',
            'purchase_requires' => [
                'upstream_default_config' => [
                    'cpu' => '2',
                    'memory' => '2048',
                ],
            ],
            'config_options' => [],
        ]);

        $display = (new ProductDisplayNameResolver)->resolveForProduct($product);

        $this->assertSame('gscs', $display['product_display_name'] ?? null);
        $this->assertSame('gscs', $display['combined_display_name'] ?? null);
        $this->assertSame('2 vCPU 2G', $display['product_spec_display'] ?? null);
        $this->assertArrayNotHasKey('cpu_memory_slug_display', $display);
    }

    public function test_display_name_resolver_keeps_instance_spec_base_name_without_cpu_memory_slug(): void
    {
        $specCatalog = $this->createMock(InstanceSpecCatalogService::class);
        $specCatalog->method('resolveProductSpecMap')->willReturn([
            999003 => ['instance_spec_text' => 'gscs'],
        ]);
        $product = new Product([
            'product_type' => 'cloud_server',
            'purchase_requires' => [
                'upstream_default_config' => [
                    'cpu' => '32',
                    'memory' => '65536',
                ],
            ],
            'config_options' => [],
        ]);
        $product->setAttribute('id', 999003);

        $display = (new ProductDisplayNameResolver($specCatalog))->resolveForProduct($product);

        $this->assertSame('gscs', $display['product_display_name'] ?? null);
        $this->assertSame('gscs', $display['combined_display_name'] ?? null);
        $this->assertSame('gscs', $display['product_spec_display'] ?? null);
        $this->assertArrayNotHasKey('cpu_memory_slug_display', $display);
    }

    public function test_display_name_resolver_does_not_duplicate_compact_cpu_memory_in_instance_spec_name(): void
    {
        $specCatalog = $this->createMock(InstanceSpecCatalogService::class);
        $specCatalog->method('resolveProductSpecMap')->willReturn([
            999004 => ['instance_spec_text' => 'ecs.g9i.2c2g'],
        ]);
        $product = new Product([
            'product_type' => 'cloud_server',
            'purchase_requires' => [
                'upstream_default_config' => [
                    'cpu' => '2',
                    'memory' => '2048',
                ],
            ],
            'config_options' => [],
        ]);
        $product->setAttribute('id', 999004);

        $display = (new ProductDisplayNameResolver($specCatalog))->resolveForProduct($product);

        $this->assertSame('ecs.g9i.2c2g', $display['product_display_name'] ?? null);
        $this->assertSame('ecs.g9i.2c2g', $display['combined_display_name'] ?? null);
        $this->assertSame('ecs.g9i.2c2g', $display['product_spec_display'] ?? null);
    }

    private function makeProviderResolver(HostingPanelApiTransport $transport): ProviderResolver
    {
        return new ProviderResolver(new ProviderRegistry([
            new class($transport) implements UpstreamDriver
            {
                public function __construct(private readonly HostingPanelApiTransport $transport) {}

                public function key(): string
                {
                    return ProviderKey::ZJMF_FINANCE_API;
                }

                public function label(): string
                {
                    return 'ZJMF 财务接口';
                }

                public function capabilities(): array
                {
                    return [ProvidesConsoleCatalog::class];
                }

                public function supports(string $capability): bool
                {
                    return $capability === ProvidesConsoleCatalog::class
                        && $this->transport instanceof ProvidesConsoleCatalog;
                }

                public function resolve(string $capability): ?object
                {
                    return $this->supports($capability) ? $this->transport : null;
                }
            },
        ]));
    }

    private function bindSupplierToZjmf(Supplier $supplier): void
    {
        $pluginId = (int) DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where('plugin_key', ProviderKey::ZJMF_FINANCE_API)
            ->value('id');

        $this->assertGreaterThan(0, $pluginId);

        DB::table('supplier_plugin_bindings')->updateOrInsert([
            'supplier_id' => (int) $supplier->id,
            'plugin_id' => $pluginId,
            'environment' => 'production',
        ], [
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'status' => 1,
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
