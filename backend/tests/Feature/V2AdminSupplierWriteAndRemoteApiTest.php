<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\Supplier;
use App\Services\Integrations\Plugins\UpstreamBindingWriter;
use App\Services\Upstream\ProviderRegistry;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2AdminSupplierWriteAndRemoteApiTest extends TestCase
{
    use DatabaseTransactions;

    public function test_supplier_write_endpoints_use_v2_contracts(): void
    {
        $this->activateDemoUpstreamPlugin();
        $suffix = bin2hex(random_bytes(4));

        $this->postJson('/api/v2/admin/suppliers', $this->supplierPayload($suffix))
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SUPPLIER_LIST]));

        $this->postJson('/api/v2/admin/suppliers', $this->supplierPayload($suffix))
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SUPPLIER_MANAGE]));

        $this->postJson('/api/v2/admin/suppliers?per_page=20', $this->supplierPayload($suffix))
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $createResponse = $this->postJson('/api/v2/admin/suppliers', $this->supplierPayload($suffix))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '创建成功')
            ->assertJsonPath('data.supplier.provider_key', 'demo_servers')
            ->assertJsonPath('data.supplier.provider_config.demo_region', 'ap-v2-'.$suffix)
            ->assertJsonMissingPath('data.supplier.api_key');

        $supplierId = (int) $createResponse->json('data.supplier.id');
        $this->assertSame(['supplier'], array_keys($createResponse->json('data')));
        $this->assertSame($this->supplierWhitelist(), array_keys($createResponse->json('data.supplier')));
        $this->assertNoSensitiveKeys($createResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $createResponse->getContent()));

        $updateResponse = $this->putJson('/api/v2/admin/suppliers/'.$supplierId, array_merge(
            $this->supplierPayload($suffix),
            ['name' => 'V2 Demo Supplier Updated '.$suffix]
        ))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '更新成功')
            ->assertJsonPath('data.supplier.name', 'V2 Demo Supplier Updated '.$suffix);

        $this->assertSame(['supplier'], array_keys($updateResponse->json('data')));
        $this->assertSame($this->supplierWhitelist(), array_keys($updateResponse->json('data.supplier')));
        $this->assertNoSensitiveKeys($updateResponse->json());

        $boundSupplierBindingId = (int) DB::table('supplier_plugin_bindings')
            ->where('supplier_id', $supplierId)
            ->value('id');

        $this->deleteJson('/api/v2/admin/suppliers/'.$supplierId)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $supplierId)
            ->assertJsonPath('data.status', 'deleted');

        $this->assertDatabaseMissing('suppliers', ['id' => $supplierId]);
        $this->assertDatabaseMissing('supplier_plugin_bindings', ['id' => $boundSupplierBindingId]);

        $standalone = Supplier::query()->create([
            'name' => 'V2 Delete Supplier '.$suffix,
            'code' => 'v2_delete_supplier_'.$suffix,
            'status' => 1,
            'sort_order' => 1,
        ]);

        $deleteResponse = $this->deleteJson('/api/v2/admin/suppliers/'.$standalone->id)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $standalone->id)
            ->assertJsonPath('data.status', 'deleted');

        $this->assertSame(['id', 'status', 'message'], array_keys($deleteResponse->json('data')));
        $this->assertDatabaseMissing('suppliers', ['id' => (int) $standalone->id]);
    }

    public function test_supplier_remote_resources_use_v2_projection(): void
    {
        $this->activateDemoUpstreamPlugin();
        $supplier = $this->createDemoSupplier();

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SUPPLIER_DETAIL]));

        $this->getJson('/api/v2/admin/suppliers/'.$supplier->id.'/balance')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SUPPLIER_SYNC]));

        $this->getJson('/api/v2/admin/suppliers/'.$supplier->id.'/balance?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $balanceResponse = $this->getJson('/api/v2/admin/suppliers/'.$supplier->id.'/balance')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.supplier_id', $supplier->id)
            ->assertJsonPath('data.balance', '9999.00')
            ->assertJsonMissingPath('data.client.api_key')
            ->assertJsonMissingPath('data.raw_response');

        $this->assertSame([
            'supplier_id',
            'supplier_name',
            'balance',
            'client',
            'connection_status',
            'connection_message',
        ], array_keys($balanceResponse->json('data')));
        $this->assertNoSensitiveKeys($balanceResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $balanceResponse->getContent()));

        $productsResponse = $this->getJson('/api/v2/admin/suppliers/'.$supplier->id.'/products')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.supplier_id', $supplier->id)
            ->assertJsonPath('data.products.0.name', 'Demo 云服务器 1C2G')
            ->assertJsonPath('data.truncated', false)
            ->assertJsonMissingPath('data.products.0.api_key')
            ->assertJsonMissingPath('data.products.0.raw_response');

        $this->assertSame(['supplier_id', 'supplier_name', 'groups', 'products', 'truncated'], array_keys($productsResponse->json('data')));
        $this->assertNoSensitiveKeys($productsResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $productsResponse->getContent()));

        $templateResponse = $this->getJson('/api/v2/admin/suppliers/'.$supplier->id.'/products/1001/config-template')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.supplier_id', $supplier->id)
            ->assertJsonPath('data.upstream_product_id', 1001)
            ->assertJsonPath('data.config_options.0.field', 'cpu')
            ->assertJsonMissingPath('data.config_options.0.api_key')
            ->assertJsonMissingPath('data.raw_response');

        $this->assertSame([
            'supplier_id',
            'supplier_name',
            'upstream_product_id',
            'product',
            'config_options',
            'auto_filled_fields',
        ], array_keys($templateResponse->json('data')));
        $this->assertNoSensitiveKeys($templateResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $templateResponse->getContent()));
    }

    private function activateDemoUpstreamPlugin(): void
    {
        $this->activateIntegrationPluginForTest('upstream', 'demo_servers');
        $this->app->forgetInstance(ProviderRegistry::class);
    }

    private function createDemoSupplier(): Supplier
    {
        $suffix = bin2hex(random_bytes(4));
        $supplier = Supplier::query()->create([
            'name' => 'V2 Remote Supplier '.$suffix,
            'code' => 'v2_remote_supplier_'.$suffix,
            'status' => 1,
            'sort_order' => 1,
        ]);

        app(UpstreamBindingWriter::class)->syncSupplierBinding($supplier, [
            'provider_key' => 'demo_servers',
            'provider_config' => ['demo_region' => 'ap-v2-remote'],
            'status' => 1,
            'priority' => 1,
        ]);

        return $supplier->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function supplierPayload(string $suffix): array
    {
        return [
            'name' => 'V2 Demo Supplier '.$suffix,
            'upstream_binding' => [
                'provider_key' => 'demo_servers',
            ],
            'provider_config' => [
                'demo_region' => 'ap-v2-'.$suffix,
            ],
            'status' => 1,
            'sort_order' => 1,
        ];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-supplier-write-'.$suffix,
            'label' => 'V2 Supplier Write',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-supplier-write-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Supplier Write',
            'email' => 'v2-supplier-write-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    /**
     * @return list<string>
     */
    private function supplierWhitelist(): array
    {
        return [
            'id',
            'name',
            'code',
            'provider_key',
            'provider_label',
            'connection',
            'credentials',
            'provider_config',
            'upstream_binding',
            'contact_name',
            'contact_phone',
            'contact_email',
            'website',
            'status',
            'sort_order',
            'notes',
            // 上游余额告警设置（阈值 + 开关），随 AdminSupplierResource 一并返回
            'balance_setting',
            'created_at',
            'updated_at',
            'card',
        ];
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response', 'token'] as $needle) {
                    $this->assertStringNotContainsString($needle, strtolower($key));
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
