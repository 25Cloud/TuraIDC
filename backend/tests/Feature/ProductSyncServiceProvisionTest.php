<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\OrderStatus;
use App\Constants\ServiceStatus;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\Integrations\Plugins\UpstreamBindingWriter;
use App\Services\ProductCatalog\ProductSyncService;
use App\Services\Upstream\Contracts\ProvidesConsoleCatalog;
use App\Services\Upstream\Contracts\UpstreamDriver;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use App\Services\Upstream\ProviderKey;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductSyncServiceProvisionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(PluginFileLoader::class)->ensureLoaded(
            app(PluginScanner::class)->requireManifest('upstream', 'zjmf_finance')
        );
        $this->activateIntegrationPluginForTest('upstream', 'zjmf_finance');
    }

    public function test_assert_product_can_be_provisioned_blocks_when_local_stock_is_insufficient(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $product = Product::query()->create([
            'name' => 'Provision Local Stock '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '99.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => 1,
            'status' => 1,
            'auto_setup' => 0,
        ]);

        $service = new ProductSyncService(
            $this->makeProviderResolver($this->createMock(HostingPanelApiTransport::class))
        );

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('该商品库存不足，无法继续下单');

        $service->assertProductCanBeProvisioned($product, 2);
    }

    public function test_assert_product_can_be_provisioned_counts_reserved_order_quantity_for_upstream_products(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'provision-stock-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Provision Supplier '.$suffix,
            'code' => 'provision-'.$suffix,
            'interface_type' => ProviderKey::ZJMF_FINANCE_API,
            'api_url' => 'https://supplier-'.$suffix.'.example.com',
            'api_username' => 'demo',
            'api_key' => 'secret',
            'status' => 1,
            'sort_order' => 1,
        ]);

        $supplierProductId = random_int(10000, 99999);
        $product = Product::query()->create([
            'name' => 'Provision Remote Stock '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '99.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
            'supplier_id' => (int) $supplier->id,
            'provision_module' => ProviderKey::ZJMF_FINANCE_API,
            'supplier_product_id' => $supplierProductId,
        ]);
        $this->bindProductToZjmf($supplier, $product, $supplierProductId);

        Order::query()->create([
            'order_no' => 'ORDPROVISION'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => '未配置规格 #'.(int) $product->id,
            'product_type_snapshot' => (string) $product->product_type,
            'type' => 'new',
            'amount' => '99.00',
            'discount' => '0.00',
            'billing_cycle' => 'monthly',
            'quantity' => 2,
            'config_snapshot' => [],
            'config_pricing_snapshot' => [],
            'status' => OrderStatus::PENDING,
            'service_id' => null,
        ]);

        $transport = $this->createMock(HostingPanelApiTransport::class);
        $transport->expects($this->once())
            ->method('fetchBatchProductStocks')
            ->with(
                $this->callback(fn (Supplier $candidate): bool => (int) $candidate->id === (int) $supplier->id),
                [$supplierProductId]
            )
            ->willReturn([
                $supplierProductId => ['stock' => 3],
            ]);

        $service = new ProductSyncService($this->makeProviderResolver($transport));

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('该商品库存不足，无法继续下单');

        $service->assertProductCanBeProvisioned($product, 2);
    }

    public function test_assert_product_can_be_provisioned_uses_plugin_binding_runtime_credentials(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $supplier = Supplier::query()->create([
            'name' => 'Provision Runtime Supplier '.$suffix,
            'code' => 'provision-runtime-'.$suffix,
            'status' => 1,
            'sort_order' => 1,
        ]);

        $baseUrl = 'https://runtime-'.$suffix.'.example.com';
        $accountName = 'runtime-account-'.$suffix;
        $apiKey = 'runtime-secret-'.$suffix;
        $supplierBindingId = app(UpstreamBindingWriter::class)->syncSupplierBinding($supplier, [
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'base_url' => $baseUrl,
            'account_name' => $accountName,
            'api_key' => $apiKey,
            'status' => 1,
            'priority' => 0,
        ]);
        $this->assertNotNull($supplierBindingId);

        $supplierProductId = random_int(10000, 99999);
        $product = Product::query()->create([
            'name' => 'Provision Runtime Stock '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '99.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 1,
        ]);

        DB::table('product_upstream_bindings')->insert([
            'product_id' => (int) $product->id,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'plugin_id' => (int) DB::table('integration_plugins')
                ->where('domain', 'upstream')
                ->where('plugin_key', ProviderKey::ZJMF_FINANCE_API)
                ->value('id'),
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'upstream_product_id' => (string) $supplierProductId,
            'auto_setup' => 1,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $transport = $this->createMock(HostingPanelApiTransport::class);
        $transport->expects($this->once())
            ->method('fetchBatchProductStocks')
            ->with(
                $this->callback(fn (Supplier $candidate): bool => (int) $candidate->id === (int) $supplier->id
                    && (string) $candidate->api_url === $baseUrl
                    && (string) $candidate->api_username === $accountName
                    && (string) $candidate->api_key === $apiKey),
                [$supplierProductId]
            )
            ->willReturn([
                $supplierProductId => ['stock' => 4],
            ]);

        $service = new ProductSyncService($this->makeProviderResolver($transport));

        $service->assertProductCanBeProvisioned($product, 2);

        $this->assertTrue(true);
    }

    public function test_assert_product_can_be_provisioned_keeps_inventory_reserved_when_order_service_is_still_pending(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $supplier = Supplier::query()->create([
            'name' => 'Provision Supplier Pending Service '.$suffix,
            'code' => 'provision-pending-'.$suffix,
            'interface_type' => ProviderKey::ZJMF_FINANCE_API,
            'api_url' => 'https://supplier-'.$suffix.'.example.com',
            'api_username' => 'demo',
            'api_key' => 'secret',
            'status' => 1,
            'sort_order' => 1,
        ]);

        $supplierProductId = random_int(10000, 99999);
        $product = Product::query()->create([
            'name' => 'Provision Pending Service Stock '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '99.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 1,
            'supplier_id' => (int) $supplier->id,
            'provision_module' => ProviderKey::ZJMF_FINANCE_API,
            'supplier_product_id' => $supplierProductId,
        ]);
        $this->bindProductToZjmf($supplier, $product, $supplierProductId);

        $user = User::query()->create([
            'email' => 'provision-pending-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $order = Order::query()->create([
            'order_no' => 'ORDPROVPEND'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => '未配置规格 #'.(int) $product->id,
            'product_type_snapshot' => (string) $product->product_type,
            'type' => 'new',
            'amount' => '99.00',
            'discount' => '0.00',
            'billing_cycle' => 'monthly',
            'quantity' => 2,
            'config_snapshot' => [],
            'config_pricing_snapshot' => [],
            'status' => OrderStatus::PROCESSING,
            'service_id' => null,
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'order_id' => (int) $order->id,
            'name' => 'Pending Service '.$suffix,
            'domain' => 'pending-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '99.00',
            'status' => ServiceStatus::PENDING,
            'provision_data' => [
                'provision_error' => '上游开通处理中',
            ],
            'auto_renew' => 1,
        ]);

        $order->forceFill([
            'service_id' => (int) $service->id,
        ])->save();

        $transport = $this->createMock(HostingPanelApiTransport::class);
        $transport->expects($this->once())
            ->method('fetchBatchProductStocks')
            ->with(
                $this->callback(fn (Supplier $candidate): bool => (int) $candidate->id === (int) $supplier->id),
                [$supplierProductId]
            )
            ->willReturn([
                $supplierProductId => ['stock' => 3],
            ]);

        $service = new ProductSyncService($this->makeProviderResolver($transport));

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('该商品库存不足，无法继续下单');

        $service->assertProductCanBeProvisioned($product, 2);
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

    private function bindProductToZjmf(Supplier $supplier, Product $product, int|string $upstreamProductId): void
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

        $supplierBindingId = (int) DB::table('supplier_plugin_bindings')
            ->where('supplier_id', (int) $supplier->id)
            ->where('plugin_id', $pluginId)
            ->where('environment', 'production')
            ->value('id');

        DB::table('product_upstream_bindings')->updateOrInsert([
            'product_id' => (int) $product->id,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'upstream_product_id' => (string) $upstreamProductId,
        ], [
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'auto_setup' => (int) ($product->auto_setup ?? 0) === 1 ? 1 : 0,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
