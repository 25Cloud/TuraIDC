<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\ProductType;
use App\Constants\ServiceStatus;
use App\Models\AdminUser;
use App\Models\FirstProductGroup;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Role;
use App\Models\SecondProductGroup;
use App\Models\Service;
use App\Models\ThirdProductGroup;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2AdminServiceListApiTest extends TestCase
{
    public function test_admin_service_list_uses_v2_pagination_permissions_and_safe_projection(): void
    {
        ['service' => $service] = $this->createServiceFixture();

        $this->getJson('/api/v2/admin/services')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([]));

        $this->getJson('/api/v2/admin/services')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_LIST]));

        $this->getJson('/api/v2/admin/services?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->getJson('/api/v2/admin/services?'.http_build_query([
            'keyword' => (string) $service->name,
            'page' => 1,
            'page_size' => 20,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', (int) $service->id)
            ->assertJsonPath('data.list.0.connection.username', 'root')
            ->assertJsonMissingPath('data.list.0.provision_data')
            ->assertJsonMissingPath('data.list.0.connection.password')
            ->assertJsonMissingPath('data.list.0.connection_secret')
            ->assertJsonMissingPath('data.list.0.raw_response');

        $this->assertSame($this->pageWhitelist(), array_keys($response->json('data')));
        $this->assertSame($this->serviceItemWhitelist(), array_keys($response->json('data.list.0')));
        $this->assertSame($this->connectionWhitelist(), array_keys($response->json('data.list.0.connection')));
        $this->assertSame($this->userWhitelist(), array_keys($response->json('data.list.0.user')));
        $this->assertSame($this->productWhitelist(), array_keys($response->json('data.list.0.product')));
        $this->assertSame($this->orderWhitelist(), array_keys($response->json('data.list.0.order')));
        $this->assertSame($this->invoiceWhitelist(), array_keys($response->json('data.list.0.invoice')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_admin_service_hostname_batch_uses_v2_validation_and_compact_result(): void
    {
        ['service' => $service] = $this->createServiceFixture();

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_LIST]));

        $this->postJson('/api/v2/admin/services/custom-hostnames/batch', [
            'items' => [
                ['service_id' => (int) $service->id, 'hostname' => 'blocked.example.test'],
            ],
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::USER_MANAGE]));

        $this->postJson('/api/v2/admin/services/custom-hostnames/batch?per_page=20', [
            'items' => [
                ['service_id' => (int) $service->id, 'hostname' => 'blocked.example.test'],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $hostname = 'custom-'.$service->id;
        $response = $this->postJson('/api/v2/admin/services/custom-hostnames/batch', [
            'items' => [
                ['service_id' => (int) $service->id, 'hostname' => $hostname],
            ],
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.updated_count', 1)
            ->assertJsonPath('data.unchanged_count', 0)
            ->assertJsonPath('data.items.0.service_id', (int) $service->id)
            ->assertJsonPath('data.items.0.custom_hostname', $hostname)
            ->assertJsonPath('data.items.0.updated', true)
            ->assertJsonMissingPath('data.operator_id')
            ->assertJsonMissingPath('data.trace_id');

        $this->assertSame($this->hostnameResultWhitelist(), array_keys($response->json('data')));
        $this->assertSame($this->hostnameItemWhitelist(), array_keys($response->json('data.items.0')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
        $this->assertSame($hostname, $service->refresh()->provision_data['custom_hostname'] ?? null);
    }

    /**
     * @return array{user: User, product: Product, invoice: Invoice, service: Service}
     */
    private function createServiceFixture(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'v2-admin-service-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'V2 Admin Service '.$suffix,
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
        ]);
        $product = $this->createProduct($suffix);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'V2ADMINSVC'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'type' => 'normal',
            'amount' => '66.00',
            'paid_amount' => '66.00',
            'status' => InvoiceStatus::PAID,
            'billing_cycle' => 'monthly',
            'product_spec_snapshot' => 'V2 管理服务 2C4G',
            'config_snapshot' => [],
            'due_date' => now()->addDay(),
            'paid_at' => now(),
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'invoice_id' => (int) $invoice->id,
            'name' => 'V2 Admin Service '.$suffix,
            'domain' => 'v2-admin-service-'.$suffix.'.example.test',
            'billing_cycle' => 'monthly',
            'amount' => '66.00',
            'locked_pricing' => [],
            'status' => ServiceStatus::ACTIVE,
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);

        $service->forceFill([
            'provision_data' => [
                'requested_host' => 'requested-'.$suffix,
                'hostname' => 'vm-'.$suffix.'.example.test',
                'upstream_host_id' => 'upstream-'.$suffix,
                'upstream_host_ids' => ['upstream-'.$suffix],
                'dedicated_ip' => '203.0.113.8',
                'internal_ip' => '10.0.0.8',
                'assigned_ips' => ['203.0.113.8'],
                'username' => 'root',
                'os' => 'CentOS 7',
                'connection_secret' => Crypt::encryptString((string) json_encode([
                    'hostname' => 'vm-'.$suffix.'.example.test',
                    'username' => 'root',
                    'password' => 'must-not-leak',
                    'port' => 22,
                    'internal_ip' => '10.0.0.8',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'raw_response' => ['password' => 'must-not-leak'],
            ],
        ])->save();

        return [
            'user' => $user,
            'product' => $product,
            'invoice' => $invoice,
            'service' => $service->refresh(),
        ];
    }

    private function createProduct(string $suffix): Product
    {
        $firstGroup = FirstProductGroup::query()->create([
            'code' => 'v2_admin_service_'.$suffix,
            'name' => '管理服务分组 '.$suffix,
            'slug' => 'v2-admin-service-'.$suffix,
            'description' => '管理服务分组说明',
            'sort_order' => 1,
            'is_visible' => 1,
            'is_system' => 0,
            'legacy_product_type' => ProductType::VPS,
        ]);

        $secondGroup = SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => '管理服务二级 '.$suffix,
            'slug' => 'v2-admin-service-child-'.$suffix,
            'description' => '管理服务二级说明',
            'sort_order' => 1,
            'is_visible' => 1,
        ]);
        $thirdGroup = ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $secondGroup->id,
            'name' => '管理服务三级 '.$suffix,
            'slug' => 'v2-admin-service-leaf-'.$suffix,
            'description' => '管理服务三级说明',
            'sort_order' => 1,
            'is_visible' => 1,
        ]);

        return Product::query()->create([
            'product_group_id' => (int) $thirdGroup->id,
            'service_type_code' => ProductType::VPS,
            'name' => 'V2 Admin Service Product '.$suffix,
            'custom_display_name' => 'V2 Admin Service Product '.$suffix,
            'product_type' => ProductType::VPS,
            'description' => '',
            'pricing' => ['monthly' => '66.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => ['password' => 'must-not-leak'],
            'stock' => 10,
            'status' => 1,
            'sort_order' => 1,
            'auto_setup' => 0,
        ]);
    }

    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-admin-service-'.$suffix,
            'label' => 'V2 Admin Service',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-admin-service-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Admin Service',
            'email' => 'v2-admin-service-admin-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    /**
     * @return list<string>
     */
    private function pageWhitelist(): array
    {
        return ['list', 'total', 'page', 'page_size'];
    }

    /**
     * @return list<string>
     */
    private function serviceItemWhitelist(): array
    {
        return [
            'id',
            'service_id',
            'instance_id',
            'name',
            'product_id',
            'product_display_name',
            'product_full_path',
            'domain',
            'requested_hostname',
            'custom_hostname',
            'has_custom_hostname',
            'status',
            'status_label',
            'billing_cycle',
            'amount',
            'expires_at',
            'created_at',
            'auto_renew',
            'upstream_host_id',
            'upstream_host_id_text',
            'upstream_host_ids',
            'dedicated_ip',
            'host_ips',
            'internal_ip',
            'host_username',
            'connection',
            'os',
            'user',
            'product',
            'order',
            'invoice',
        ];
    }

    /**
     * @return list<string>
     */
    private function connectionWhitelist(): array
    {
        return ['hostname', 'username', 'internal_ip', 'port'];
    }

    /**
     * @return list<string>
     */
    private function userWhitelist(): array
    {
        return ['id', 'username', 'email', 'phone', 'status'];
    }

    /**
     * @return list<string>
     */
    private function productWhitelist(): array
    {
        return ['id', 'name', 'display_name', 'type'];
    }

    /**
     * @return list<string>
     */
    private function orderWhitelist(): array
    {
        return ['id', 'order_no'];
    }

    /**
     * @return list<string>
     */
    private function invoiceWhitelist(): array
    {
        return ['id', 'invoice_no', 'status', 'paid_at'];
    }

    /**
     * @return list<string>
     */
    private function hostnameResultWhitelist(): array
    {
        return ['updated_count', 'unchanged_count', 'items'];
    }

    /**
     * @return list<string>
     */
    private function hostnameItemWhitelist(): array
    {
        return ['service_id', 'custom_hostname', 'updated'];
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response'] as $needle) {
                    $this->assertStringNotContainsString($needle, strtolower($key));
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
