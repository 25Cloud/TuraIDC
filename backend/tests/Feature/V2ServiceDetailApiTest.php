<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\ProductType;
use App\Constants\ServiceStatus;
use App\Models\AdminUser;
use App\Models\FirstProductGroup;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\SecondProductGroup;
use App\Models\Service;
use App\Models\ThirdProductGroup;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2ServiceDetailApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_client_service_detail_requires_login_rejects_per_page_and_enforces_ownership(): void
    {
        ['user' => $user, 'other_user' => $otherUser, 'service' => $service] = $this->createServiceFixture();

        $this->getJson('/api/v2/client/services/'.$service->id)
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($otherUser);

        $this->getJson('/api/v2/client/services/'.$service->id)
            ->assertNotFound()
            ->assertJsonPath('code', 40400);

        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/services/'.$service->id.'?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/client/services/'.$service->id.'?page=1')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['page']]]);

        $this->getJson('/api/v2/client/services/'.$service->id.'?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);
    }

    public function test_client_service_detail_is_whitelisted_and_has_no_connection_or_sensitive_keys(): void
    {
        ['user' => $user, 'service' => $service] = $this->createServiceFixture();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v2/client/services/'.$service->id)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.service.id', $service->id)
            ->assertJsonPath('data.service.console_template', Product::CONSOLE_TEMPLATE_PORT_MAPPING)
            ->assertJsonPath('data.service.product.console_template', Product::CONSOLE_TEMPLATE_PORT_MAPPING)
            ->assertJsonMissingPath('data.service.connection')
            ->assertJsonMissingPath('data.service.upstream.provider_key')
            ->assertJsonMissingPath('data.service.upstream.supplier_id')
            ->assertJsonMissingPath('data.service.upstream.upstream_product_id')
            ->assertJsonMissingPath('data.service.upstream.invoice_id')
            ->assertJsonPath('data.service.actions.power', false);

        $this->assertSame($this->serviceDetailWhitelist(), array_keys($response->json('data.service')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_client_service_detail_exposes_the_port_mapping_console_template(): void
    {
        ['user' => $user, 'service' => $service] = $this->createServiceFixture();

        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/services/'.$service->id)
            ->assertOk()
            ->assertJsonPath('data.service.console_template', Product::CONSOLE_TEMPLATE_PORT_MAPPING)
            ->assertJsonPath('data.service.product.console_template', Product::CONSOLE_TEMPLATE_PORT_MAPPING)
            ->assertJsonPath('data.service.console_mode', 'nat')
            ->assertJsonPath('data.service.is_nat_console', true);
    }

    public function test_client_service_connection_returns_owned_service_password(): void
    {
        ['user' => $user, 'service' => $service] = $this->createServiceFixture();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v2/client/services/'.$service->id.'/connection')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.connection.hostname', (string) $service->domain)
            ->assertJsonPath('data.connection.username', 'root')
            ->assertJsonPath('data.connection.has_password', true)
            ->assertJsonPath('data.connection.dedicated_ip', '203.0.113.'.$service->id)
            ->assertJsonPath('data.connection.assigned_ips', ['203.0.113.'.$service->id, '198.51.100.'.$service->id])
            ->assertJsonPath('data.connection.password', 'LoginSecret#'.$service->id);

        $this->getJson('/api/v2/client/services/'.$service->id.'/connection?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $this->assertSame($this->connectionWhitelist(true), array_keys($response->json('data.connection')));
        $this->assertNoSensitiveKeys($response->json(), ['password']);
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_client_service_runtime_is_whitelisted_without_connection_or_sensitive_keys(): void
    {
        ['user' => $user, 'service' => $service] = $this->createServiceFixture();

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v2/client/services/'.$service->id.'/runtime')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.runtime.id', $service->id)
            ->assertJsonMissingPath('data.runtime.connection')
            ->assertJsonMissingPath('data.runtime.upstream.provider_key')
            ->assertJsonMissingPath('data.runtime.upstream.supplier_id')
            ->assertJsonMissingPath('data.runtime.upstream.upstream_product_id')
            ->assertJsonMissingPath('data.runtime.upstream.invoice_id');

        $this->getJson('/api/v2/client/services/'.$service->id.'/runtime?page_size=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['page_size']]]);

        $this->assertSame($this->runtimeWhitelist(), array_keys($response->json('data.runtime')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_admin_user_service_detail_requires_permission_and_owner_scope(): void
    {
        ['user' => $user, 'other_user' => $otherUser, 'service' => $service] = $this->createServiceFixture();

        $this->getJson('/api/v2/admin/users/'.$user->id.'/services/'.$service->id)
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([]));

        $this->getJson('/api/v2/admin/users/'.$user->id.'/services/'.$service->id)
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::USER_DETAIL]));

        $this->getJson('/api/v2/admin/users/'.$otherUser->id.'/services/'.$service->id)
            ->assertNotFound()
            ->assertJsonPath('code', 40400);

        $this->getJson('/api/v2/admin/users/'.$user->id.'/services/'.$service->id.'?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/admin/users/'.$user->id.'/services/'.$service->id.'?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);
    }

    public function test_admin_user_service_detail_and_connection_are_projected_safely(): void
    {
        ['user' => $user, 'service' => $service] = $this->createServiceFixture();

        Sanctum::actingAs($this->createAdmin([AdminPermissions::USER_DETAIL]));

        $detailResponse = $this->getJson('/api/v2/admin/users/'.$user->id.'/services/'.$service->id)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.service.id', $service->id)
            ->assertJsonMissingPath('data.service.connection')
            ->assertJsonPath('data.service.upstream.provider_key', '')
            ->assertJsonPath('data.service.upstream.supplier_id', 0)
            ->assertJsonPath('data.service.upstream.upstream_product_id', '');

        $this->assertSame($this->serviceDetailWhitelist(), array_keys($detailResponse->json('data.service')));
        $this->assertNoSensitiveKeys($detailResponse->json());

        $connectionResponse = $this->getJson('/api/v2/admin/users/'.$user->id.'/services/'.$service->id.'/connection')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.connection.has_password', true)
            ->assertJsonMissingPath('data.connection.password');

        $this->getJson('/api/v2/admin/users/'.$user->id.'/services/'.$service->id.'/connection?page=1')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['page']]]);

        $this->getJson('/api/v2/admin/users/'.$user->id.'/services/'.$service->id.'/connection?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $this->assertSame($this->connectionWhitelist(), array_keys($connectionResponse->json('data.connection')));
        $this->assertNoSensitiveKeys($connectionResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $detailResponse->getContent()));
        $this->assertLessThan(100 * 1024, strlen((string) $connectionResponse->getContent()));
    }

    /**
     * @return array{
     *     user: User,
     *     other_user: User,
     *     product: Product,
     *     order: Order,
     *     invoice: Invoice,
     *     service: Service
     * }
     */
    private function createServiceFixture(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createClientUser('owner-'.$suffix);
        $otherUser = $this->createClientUser('other-'.$suffix);
        $product = $this->createProduct($suffix);

        $order = Order::query()->create([
            'order_no' => 'V2-SVC-ORD-'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'type' => 'new',
            'amount' => '88.00',
            'discount' => '0.00',
            'paid_amount' => '88.00',
            'billing_cycle' => 'monthly',
            'config_snapshot' => [],
            'config_pricing_snapshot' => [],
            'coupon_snapshot' => [],
            'status' => OrderStatus::COMPLETED,
            'paid_at' => now(),
        ]);

        $invoice = Invoice::query()->create([
            'invoice_no' => 'V2-SVC-INV-'.strtoupper($suffix),
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'type' => 'normal',
            'amount' => '88.00',
            'paid_amount' => '88.00',
            'status' => InvoiceStatus::PAID,
            'due_date' => now()->addDay(),
            'paid_at' => now(),
        ]);

        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'name' => 'V2 Service '.$suffix,
            'domain' => 'svc-'.$suffix.'.example.test',
            'billing_cycle' => 'monthly',
            'amount' => '88.00',
            'locked_pricing' => ['monthly' => '88.00'],
            'status' => ServiceStatus::ACTIVE,
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);

        $service->forceFill([
            'provision_data' => $this->serviceProvisionData($service),
        ])->save();

        $order->forceFill(['service_id' => (int) $service->id])->save();

        return [
            'user' => $user,
            'other_user' => $otherUser,
            'product' => $product,
            'order' => $order,
            'invoice' => $invoice,
            'service' => $service->refresh(),
        ];
    }

    private function createClientUser(string $suffix): User
    {
        return User::query()->create([
            'email' => 'v2-service-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'V2 Service '.$suffix,
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
    }

    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-service-'.$suffix,
            'label' => 'V2 Service',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-service-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Service',
            'email' => 'v2-service-admin-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    private function createProduct(string $suffix): Product
    {
        $firstGroup = FirstProductGroup::query()->create([
            'code' => 'v2_service_'.$suffix,
            'name' => '服务分组 '.$suffix,
            'slug' => 'v2-service-'.$suffix,
            'description' => '服务分组说明',
            'sort_order' => 1,
            'is_visible' => 1,
            'is_system' => 0,
            'legacy_product_type' => ProductType::VPS,
        ]);

        $secondGroup = SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => '服务二级 '.$suffix,
            'slug' => 'v2-service-child-'.$suffix,
            'description' => '服务二级说明',
            'sort_order' => 1,
            'is_visible' => 1,
        ]);
        $thirdGroup = ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $secondGroup->id,
            'name' => '服务三级 '.$suffix,
            'slug' => 'v2-service-leaf-'.$suffix,
            'description' => '服务三级说明',
            'sort_order' => 1,
            'is_visible' => 1,
        ]);

        return Product::query()->create([
            'product_group_id' => (int) $thirdGroup->id,
            'service_type_code' => ProductType::VPS,
            'name' => 'V2 Service Product '.$suffix,
            'custom_display_name' => 'V2 Service Product '.$suffix,
            'product_type' => ProductType::VPS,
            'console_template' => Product::CONSOLE_TEMPLATE_PORT_MAPPING,
            'description' => '',
            'pricing' => ['monthly' => '88.00'],
            'setup_fee' => '0.00',
            'config_options' => [
                ['field' => 'cpu', 'name' => 'CPU'],
                ['field' => 'memory', 'name' => '内存'],
            ],
            'purchase_requires' => ['password' => 'must-not-leak'],
            'stock' => 10,
            'status' => 1,
            'sort_order' => 1,
            'auto_setup' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceProvisionData(Service $service): array
    {
        return [
            'hostname' => 'vm-'.$service->id.'.example.test',
            'connection_secret' => Crypt::encryptString((string) json_encode([
                'hostname' => 'vm-'.$service->id.'.example.test',
                'username' => 'root',
                'password' => 'Secret#'.$service->id,
                'port' => 22,
                'internal_ip' => '10.0.0.'.$service->id,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'connection_cached_at' => now()->format('Y-m-d H:i:s'),
            'upstream_status' => 'Active',
            'runtime_status' => 'running',
            'runtime_description' => '运行中',
            'dedicated_ip' => '203.0.113.'.$service->id,
            'assigned_ips' => ['203.0.113.'.$service->id, '198.51.100.'.$service->id],
            'nat_remote_address' => '198.51.100.'.$service->id,
            'nat_remote_host' => 'nat.example.test',
            'nat_remote_port' => 2200 + (int) $service->id,
            'nat_remote_checked_at' => now()->format('Y-m-d H:i:s'),
            'client_remark' => '测试备注',
            'custom_service_name' => '测试实例',
            'requested_config' => [
                'cpu' => 2,
                'memory' => 2048,
            ],
            'password' => 'LoginSecret#'.$service->id,
            'raw_response' => ['must' => 'not leak'],
        ];
    }

    /**
     * @return list<string>
     */
    private function serviceDetailWhitelist(): array
    {
        return [
            'id',
            'name',
            'product_display_name',
            'combined_display_name',
            'domain',
            'status',
            'status_label',
            'status_tone',
            'billing_cycle',
            'billing_cycle_label',
            'amount',
            'expires_at',
            'created_at',
            'auto_renew',
            'suspended_reason',
            'remark',
            'custom_service_name',
            'has_custom_service_name',
            'custom_hostname',
            'has_custom_hostname',
            'console_template',
            'console_mode',
            'is_nat_console',
            'product',
            'invoice',
            'upstream',
            'runtime',
            'specs',
            'traffic',
            'renewal',
            'actions',
        ];
    }

    /**
     * @return list<string>
     */
    private function connectionWhitelist(bool $includePassword = false): array
    {
        $keys = [
            'hostname',
            'username',
            'has_password',
            'port',
            'dedicated_ip',
            'internal_ip',
            'assigned_ips',
            'nat_remote_address',
            'nat_remote_host',
            'nat_remote_port',
            'nat_remote_checked_at',
        ];

        if ($includePassword) {
            $keys[] = 'password';
        }

        return $keys;
    }

    /**
     * @return list<string>
     */
    private function runtimeWhitelist(): array
    {
        return [
            'id',
            'status',
            'status_label',
            'status_tone',
            'expires_at',
            'upstream',
            'runtime',
            'traffic',
            'actions',
            'specs',
        ];
    }

    /**
     * @param  list<string>  $allowedSensitiveKeys
     */
    private function assertNoSensitiveKeys(mixed $payload, array $allowedSensitiveKeys = []): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $normalized = strtolower($key);

                if (! in_array($normalized, ['has_password', 'password_reset'], true)
                    && ! in_array($normalized, $allowedSensitiveKeys, true)) {
                    $this->assertStringNotContainsString('password', $normalized);
                }

                foreach (['secret', 'api_key', 'raw_response', 'third_party_response'] as $needle) {
                    $this->assertStringNotContainsString($needle, $normalized);
                }
            }

            $this->assertNoSensitiveKeys($value, $allowedSensitiveKeys);
        }
    }
}
