<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ServiceStatus;
use App\Models\Product;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\User;
use App\Services\Upstream\ProviderKey;
use App\Services\User\UserService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminUserServiceUpstreamBindingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->activateIntegrationPluginForTest('upstream', 'zjmf_finance');
    }

    public function test_manual_service_can_bind_zjmf_finance_supplier_and_override_upstream_product_id(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'service-upstream-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Zjmf Service Supplier '.$suffix,
            'code' => 'zjmf-service-'.$suffix,
            'interface_type' => ProviderKey::ZJMF_FINANCE_API,
            'api_url' => 'https://supplier-'.$suffix.'.example.com',
            'api_username' => 'demo',
            'api_key' => 'secret',
            'status' => 1,
            'sort_order' => 1,
        ]);

        $product = Product::query()->create([
            'name' => 'Zjmf Service Product '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '99.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
            'supplier_id' => (int) $supplier->id,
            'supplier_product_id' => 10001,
            'provision_module' => ProviderKey::ZJMF_FINANCE_API,
        ]);
        $this->createProductUpstreamBinding($supplier, $product, 10001);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Manual Local Service '.$suffix,
            'domain' => 'manual-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '99.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => [],
            'provision_data' => [
                'source_type' => 'manual',
                'created_from_admin' => true,
            ],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);
        DB::table('service_runtime_snapshots')->insert([
            'service_id' => (int) $service->id,
            'provider_key' => 'mofang_finance_api',
            'status_key' => 'off',
            'resource_json' => json_encode(['provider_key' => 'mofang_finance_api']),
            'synced_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(UserService::class)->updateServiceMeta($user, (int) $service->id, [
            'supplier_id' => (int) $supplier->id,
            'upstream_product_id' => 20002,
            'upstream_host_id' => 30003,
        ]);

        $provisionData = (array) $service->refresh()->provision_data;

        $this->assertSame('upstream', $provisionData['source_type'] ?? null);
        $this->assertSame(ProviderKey::ZJMF_FINANCE_API, $provisionData['provider_key'] ?? null);
        $this->assertSame((int) $supplier->id, $provisionData['supplier_id'] ?? null);
        $this->assertSame(20002, $provisionData['upstream_product_id'] ?? null);
        $this->assertSame(30003, $provisionData['upstream_host_id'] ?? null);

        $binding = DB::table('service_upstream_bindings')
            ->where('service_id', (int) $service->id)
            ->where('provider_key', ProviderKey::ZJMF_FINANCE_API)
            ->where('upstream_service_id', '30003')
            ->first();

        $this->assertNotNull($binding);
        $this->assertDatabaseHas('service_runtime_snapshots', [
            'service_id' => (int) $service->id,
            'service_upstream_binding_id' => (int) $binding->id,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
        ]);
    }

    public function test_service_specific_supplier_binding_uses_saved_supplier_id_without_product_binding(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'service-specific-binding-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);
        $supplier = Supplier::query()->create([
            'name' => 'Service Specific Supplier '.$suffix,
            'code' => 'service-specific-'.$suffix,
            'interface_type' => ProviderKey::ZJMF_FINANCE_API,
            'api_url' => 'https://service-specific-'.$suffix.'.example.com',
            'api_username' => 'demo',
            'api_key' => 'secret',
            'status' => 1,
            'sort_order' => 1,
        ]);
        $pluginId = (int) DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where('plugin_key', ProviderKey::ZJMF_FINANCE_API)
            ->value('id');
        DB::table('supplier_plugin_bindings')->insert([
            'supplier_id' => (int) $supplier->id,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'environment' => 'production',
            'status' => 1,
            'priority' => 1,
            'base_url' => 'https://service-specific-'.$suffix.'.example.com',
            'account_name' => 'demo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $product = Product::query()->create([
            'name' => 'Service Specific Product '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '66.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);
        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Service Specific Instance '.$suffix,
            'domain' => 'service-specific-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '66.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => [],
            'provision_data' => [],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);

        app(UserService::class)->updateServiceMeta($user, (int) $service->id, [
            'supplier_id' => (int) $supplier->id,
            'upstream_product_id' => 55501,
            'upstream_host_id' => 66601,
        ]);

        $binding = DB::table('service_upstream_bindings')
            ->where('service_id', (int) $service->id)
            ->where('provider_key', ProviderKey::ZJMF_FINANCE_API)
            ->where('upstream_service_id', '66601')
            ->first(['product_upstream_binding_id', 'supplier_plugin_binding_id']);

        $this->assertNotNull($binding);
        $this->assertNull($binding->product_upstream_binding_id);
        $this->assertSame((int) $supplier->id, (int) DB::table('supplier_plugin_bindings')
            ->where('id', (int) $binding->supplier_plugin_binding_id)
            ->value('supplier_id'));
        $this->assertDatabaseHas('service_runtime_snapshots', [
            'service_id' => (int) $service->id,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
        ]);
    }

    public function test_deleting_service_record_removes_its_local_upstream_binding_without_calling_upstream(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'service-delete-binding-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);
        $supplier = Supplier::query()->create([
            'name' => 'Delete Binding Supplier '.$suffix,
            'code' => 'delete-binding-'.$suffix,
            'interface_type' => ProviderKey::ZJMF_FINANCE_API,
            'api_url' => 'https://supplier-delete-'.$suffix.'.example.com',
            'api_username' => 'demo',
            'api_key' => 'secret',
            'status' => 1,
            'sort_order' => 1,
        ]);
        $product = Product::query()->create([
            'name' => 'Delete Binding Product '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '99.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
            'supplier_id' => (int) $supplier->id,
            'supplier_product_id' => 30001,
            'provision_module' => ProviderKey::ZJMF_FINANCE_API,
        ]);
        $this->createProductUpstreamBinding($supplier, $product, 30001);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Delete Binding Service '.$suffix,
            'domain' => 'delete-binding-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '99.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => [],
            'provision_data' => [],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);

        $userService = app(UserService::class);
        $userService->updateServiceMeta($user, (int) $service->id, [
            'supplier_id' => (int) $supplier->id,
            'upstream_product_id' => 30001,
            'upstream_host_id' => 40001,
        ]);

        $this->assertDatabaseHas('service_upstream_bindings', [
            'service_id' => (int) $service->id,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'upstream_service_id' => '40001',
        ]);

        $userService->deleteService($user, (int) $service->id);

        $this->assertDatabaseMissing('service_upstream_bindings', [
            'service_id' => (int) $service->id,
        ]);
        // services 已启用软删除，记录保留但标记 deleted_at
        $this->assertSoftDeleted('services', [
            'id' => (int) $service->id,
        ]);
    }

    public function test_update_upstream_host_id_only_corrects_provider_from_supplier_binding(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'service-host-only-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Zjmf Host Only Supplier '.$suffix,
            'code' => 'zjmf-host-only-'.$suffix,
            'interface_type' => ProviderKey::ZJMF_FINANCE_API,
            'api_url' => 'https://supplier-host-only-'.$suffix.'.example.com',
            'api_username' => 'demo',
            'api_key' => 'secret',
            'status' => 1,
            'sort_order' => 1,
        ]);

        $product = Product::query()->create([
            'name' => 'Zjmf Host Only Product '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '88.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 1,
            'supplier_id' => (int) $supplier->id,
            'supplier_product_id' => 80001,
            'provision_module' => ProviderKey::ZJMF_FINANCE_API,
        ]);
        $this->createProductUpstreamBinding($supplier, $product, 80001);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Mismatched Provider Service '.$suffix,
            'domain' => 'mismatch-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '88.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => [],
            'provision_data' => [
                'source_type' => 'upstream',
                'provider_key' => ProviderKey::HOSTING_PANEL_API,
                'supplier_id' => (int) $supplier->id,
                'upstream_product_id' => 80001,
                'upstream_host_id' => 90001,
            ],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);

        app(UserService::class)->updateServiceMeta($user, (int) $service->id, [
            'upstream_host_id' => 90002,
        ]);

        $provisionData = (array) $service->refresh()->provision_data;

        $this->assertSame(ProviderKey::ZJMF_FINANCE_API, $provisionData['provider_key'] ?? null,
            'When only updating upstream_host_id, the provider key is corrected from the normalized product/supplier binding');
        $this->assertSame(80001, $provisionData['upstream_product_id'] ?? null);
        $this->assertSame(90002, $provisionData['upstream_host_id'] ?? null);
    }

    public function test_rebind_supplier_corrects_mismatched_provider(): void
    {
        $suffix = bin2hex(random_bytes(4));

        $user = User::query()->create([
            'email' => 'service-rebind-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);

        $supplier = Supplier::query()->create([
            'name' => 'Zjmf Rebind Supplier '.$suffix,
            'code' => 'zjmf-rebind-'.$suffix,
            'interface_type' => ProviderKey::ZJMF_FINANCE_API,
            'api_url' => 'https://supplier-rebind-'.$suffix.'.example.com',
            'api_username' => 'demo',
            'api_key' => 'secret',
            'status' => 1,
            'sort_order' => 1,
        ]);

        $product = Product::query()->create([
            'name' => 'Zjmf Rebind Product '.$suffix,
            'product_type' => 'server',
            'pricing' => ['monthly' => '77.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 1,
            'supplier_id' => (int) $supplier->id,
            'supplier_product_id' => 80002,
            'provision_module' => ProviderKey::ZJMF_FINANCE_API,
        ]);
        $this->createProductUpstreamBinding($supplier, $product, 80002);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Rebind Correction Service '.$suffix,
            'domain' => 'rebind-'.$suffix.'.example.com',
            'billing_cycle' => 'monthly',
            'amount' => '77.00',
            'status' => ServiceStatus::ACTIVE,
            'locked_pricing' => [],
            'provision_data' => [
                'source_type' => 'upstream',
                'provider_key' => ProviderKey::HOSTING_PANEL_API,
                'supplier_id' => (int) $supplier->id,
                'upstream_product_id' => 80002,
                'upstream_host_id' => 91001,
            ],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);

        app(UserService::class)->updateServiceMeta($user, (int) $service->id, [
            'supplier_id' => (int) $supplier->id,
            'upstream_host_id' => 91002,
        ]);

        $provisionData = (array) $service->refresh()->provision_data;

        $this->assertSame(ProviderKey::ZJMF_FINANCE_API, $provisionData['provider_key'] ?? null,
            'Rebinding same supplier must correct a mismatched provider key to the normalized supplier binding');
        $this->assertSame(80002, $provisionData['upstream_product_id'] ?? null);
        $this->assertSame(91002, $provisionData['upstream_host_id'] ?? null);
        $this->assertDatabaseHas('service_upstream_bindings', [
            'service_id' => (int) $service->id,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'upstream_service_id' => '91002',
        ]);
    }

    private function createProductUpstreamBinding(Supplier $supplier, Product $product, int $upstreamProductId): void
    {
        $pluginId = (int) DB::table('integration_plugins')
            ->where('domain', 'upstream')
            ->where('plugin_key', ProviderKey::ZJMF_FINANCE_API)
            ->value('id');

        $now = now();
        $supplierBindingId = DB::table('supplier_plugin_bindings')->insertGetId([
            'supplier_id' => (int) $supplier->id,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'environment' => 'production',
            'status' => 1,
            'priority' => 1,
            'base_url' => 'https://binding-'.$supplier->id.'.example.com',
            'account_name' => 'demo',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('product_upstream_bindings')->insert([
            'product_id' => (int) $product->id,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'plugin_id' => $pluginId,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'upstream_product_id' => (string) $upstreamProductId,
            'auto_setup' => (int) ($product->auto_setup ?? 0) === 1 ? 1 : 0,
            'status' => 1,
            'last_synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
