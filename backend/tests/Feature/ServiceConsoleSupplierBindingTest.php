<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ServiceStatus;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceDetailService;
use App\Services\ClientServiceConsole\ServiceResolverService;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Services\Integrations\Plugins\PluginInstaller;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\System\OperationLogService;
use App\Services\Upstream\ProviderKey;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceConsoleSupplierBindingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureZjmfFinancePluginEnabled();
    }

    #[Test]
    public function it_prefers_the_service_bound_supplier_over_the_product_supplier(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $pluginId = (int) DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where('plugin_key', ProviderKey::ZJMF_FINANCE_API)
            ->value('id');

        $this->assertGreaterThan(0, $pluginId);

        $user = User::query()->create([
            'email' => 'service-console-prefer-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $boundSupplier = Supplier::query()->create([
            'name' => 'Zjmf Bound Supplier '.$suffix,
            'code' => 'zjmf-bound-'.$suffix,
            'interface_type' => ProviderKey::ZJMF_FINANCE_API,
            'status' => 1,
            'sort_order' => 1,
        ]);

        $productSupplier = Supplier::query()->create([
            'name' => 'Zjmf Product Supplier '.$suffix,
            'code' => 'zjmf-product-'.$suffix,
            'interface_type' => ProviderKey::ZJMF_FINANCE_API,
            'status' => 1,
            'sort_order' => 1,
        ]);

        $product = Product::query()->create([
            'name' => 'Zjmf Console Product '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '99.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
            'supplier_id' => (int) $productSupplier->id,
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Console Bound Service '.$suffix,
            'domain' => 'console-bound-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '99.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => [],
            'provision_data' => [],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);

        $supplierBindingId = DB::table('supplier_plugin_bindings')->insertGetId([
            'supplier_id' => (int) $boundSupplier->id,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'environment' => 'production',
            'status' => 1,
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('service_upstream_bindings')->insert([
            'service_id' => (int) $service->id,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'upstream_service_id' => '456',
            'status_snapshot' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $service->setRelation('product', $product->setRelation('supplier', $productSupplier));

        $detailService = $this->makeDetailService($boundSupplier);

        [$resolvedSupplier, $hostId] = $detailService->resolveManagedSupplierAndHost($service);

        $this->assertSame((int) $boundSupplier->id, (int) $resolvedSupplier->id);
        $this->assertSame(456, $hostId);
    }

    #[Test]
    public function it_requires_a_supplier_binding_before_marking_service_as_manageable(): void
    {
        $transformService = new ServiceTransformService(new ServiceResolverService);

        $suffix = bin2hex(random_bytes(4));
        $pluginId = (int) DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where('plugin_key', ProviderKey::ZJMF_FINANCE_API)
            ->value('id');

        $user = User::query()->create([
            'email' => 'service-console-binding-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Zjmf Console Supplier '.$suffix,
            'code' => 'zjmf-console-'.$suffix,
            'interface_type' => ProviderKey::ZJMF_FINANCE_API,
            'api_url' => 'https://supplier-'.$suffix.'.example.com',
            'api_username' => 'demo',
            'api_key' => 'secret',
            'status' => 1,
            'sort_order' => 1,
        ]);

        $product = Product::query()->create([
            'name' => 'Zjmf Console Product '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '99.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
            'supplier_id' => (int) $supplier->id,
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Console Service '.$suffix,
            'domain' => 'console-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '99.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => [],
            'provision_data' => [],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);

        $this->assertFalse($transformService->canManageService($service));

        $supplierBindingId = DB::table('supplier_plugin_bindings')->insertGetId([
            'supplier_id' => (int) $supplier->id,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'environment' => 'production',
            'status' => 1,
            'priority' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('service_upstream_bindings')->insert([
            'service_id' => (int) $service->id,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'upstream_service_id' => '456',
            'status_snapshot' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertTrue($transformService->canManageService($service->refresh()));
    }

    #[Test]
    public function service_console_product_relations_select_new_group_hierarchy_columns(): void
    {
        $source = file_get_contents(base_path('app/Services/ClientServiceConsole/ServiceSecurityGroupService.php'));

        $this->assertStringNotContainsString('product:id,name,type,', $source);
        $this->assertStringNotContainsString('product:id,product_type,product_group_id,supplier_id,provision_module', $source);
        $this->assertStringNotContainsString('supplier_id,provision_module', $source);
        $this->assertStringNotContainsString('first_product_group_id,second_product_group_id,third_product_group_id', $source);
        $this->assertStringContainsString('product:id,product_type,service_type_code,product_group_id,config_options,purchase_requires', $source);
    }

    private function makeDetailService(?Supplier $resolvedSupplier): ServiceDetailService
    {
        return new class($resolvedSupplier, $this->createMock(OperationLogService::class), $this->createMock(ServiceResolverService::class), $this->createMock(ServiceTransformService::class)) extends ServiceDetailService
        {
            public function __construct(
                private readonly ?Supplier $resolvedSupplier,
                OperationLogService $operationLogService,
                ServiceResolverService $resolverService,
                ServiceTransformService $transformService,
            ) {
                parent::__construct(app(ProviderResolver::class), $operationLogService, $resolverService, $transformService);
            }

            protected function findSupplierById(int $supplierId): ?Supplier
            {
                return $this->resolvedSupplier;
            }
        };
    }

    private function ensureZjmfFinancePluginEnabled(): void
    {
        $this->ensurePluginTables();

        $scanner = app(PluginScanner::class);
        $installer = app(PluginInstaller::class);
        $scanner->requireManifest('upstream', 'zjmf_finance');
        $plugin = $installer->install('upstream', 'zjmf_finance');
        $installer->enable($plugin);

        $this->app->forgetInstance(ProviderRegistry::class);
        $this->app->forgetInstance(ProviderResolver::class);
    }

    private function ensurePluginTables(): void
    {
        if (! Schema::hasTable('integration_plugins')) {
            Schema::create('integration_plugins', function (Blueprint $table): void {
                $table->id();
                $table->string('domain', 32);
                $table->string('slug', 120);
                $table->string('plugin_key', 120);
                $table->string('name', 120);
                $table->string('version', 32)->default('1.0.0');
                $table->string('provider_class', 255)->nullable();
                $table->string('entry_class', 255);
                $table->json('capabilities_json')->nullable();
                $table->json('config_schema_json')->nullable();
                $table->unsignedTinyInteger('status')->default(0);
                $table->timestamp('installed_at')->nullable();
                $table->timestamps();
                $table->unique(['domain', 'slug']);
                $table->unique(['domain', 'plugin_key']);
            });
        }

        if (! Schema::hasTable('integration_plugin_configs')) {
            Schema::create('integration_plugin_configs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('plugin_id');
                $table->json('config_json')->nullable();
                $table->longText('secret_json')->nullable();
                $table->json('has_secret_json')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique('plugin_id');
            });
        }
    }
}
