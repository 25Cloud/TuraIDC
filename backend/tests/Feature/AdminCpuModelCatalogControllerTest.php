<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Support\AdminPermissions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminCpuModelCatalogControllerTest extends TestCase
{
    private bool $hadOriginalCpuModelCatalog = false;

    private ?string $originalCpuModelCatalogValue = null;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        app('db')->setDefaultConnection('sqlite');

        Schema::connection('sqlite')->create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group_key', 100);
            $table->string('item_key', 100);
            $table->text('item_value')->nullable();
            $table->unique(['group_key', 'item_key'], 'settings_group_item_unique');
        });

        Schema::connection('sqlite')->create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('label', 100);
            $table->json('permissions')->nullable();
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('admin_users', function (Blueprint $table): void {
            $table->id();
            $table->string('username', 100)->unique();
            $table->string('password', 255);
            $table->unsignedBigInteger('role_id')->nullable();
            $table->string('nickname', 100)->nullable();
            $table->string('email', 191)->nullable();
            $table->tinyInteger('status')->default(1);
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamps();
        });

        $originalValue = DB::table('settings')
            ->where('group_key', 'product')
            ->where('item_key', 'cpu_model_catalog')
            ->value('item_value');

        $this->hadOriginalCpuModelCatalog = $originalValue !== null;
        $this->originalCpuModelCatalogValue = $originalValue !== null ? (string) $originalValue : null;
    }

    protected function tearDown(): void
    {
        if ($this->hadOriginalCpuModelCatalog) {
            DB::table('settings')->updateOrInsert(
                [
                    'group_key' => 'product',
                    'item_key' => 'cpu_model_catalog',
                ],
                [
                    'item_value' => $this->originalCpuModelCatalogValue ?? '[]',
                ]
            );
        } else {
            DB::table('settings')
                ->where('group_key', 'product')
                ->where('item_key', 'cpu_model_catalog')
                ->delete();
        }

        Schema::connection('sqlite')->dropIfExists('admin_users');
        Schema::connection('sqlite')->dropIfExists('roles');
        Schema::connection('sqlite')->dropIfExists('settings');

        parent::tearDown();
    }

    public function test_admin_can_fetch_and_save_cpu_model_catalog(): void
    {
        DB::table('settings')
            ->where('group_key', 'product')
            ->where('item_key', 'cpu_model_catalog')
            ->delete();

        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'cpu-catalog-'.$suffix,
            'label' => 'CPU Catalog',
            'permissions' => [AdminPermissions::PRODUCT_MANAGE],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'cpu-catalog-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'CPU Catalog',
            'email' => 'cpu-catalog-'.$suffix.'@example.com',
            'status' => 1,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v2/admin/cpu-model-catalog')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $payload = [
            'list' => [
                [
                    'id' => 'group_intel',
                    'value' => 'intel_xeon',
                    'name' => 'Intel Xeon',
                    'models' => [
                        [
                            'id' => 'model_6133',
                            'value' => 'intel_xeon_gold_6133',
                            'name' => 'Intel Xeon Gold 6133',
                            'base_frequency' => '2.50GHz',
                            'turbo_frequency' => '3.20GHz',
                            'bindings' => [
                                [
                                    'product_id' => 101,
                                    'display_name' => 'ecs.g9i.2c2g',
                                    'cpu_memory_display' => '2 vCPU 2G',
                                    'category_full_name' => '轻量云 / 美国节点',
                                    'primary_price' => [
                                        'cycle' => 'month',
                                        'amount' => '35.00',
                                    ],
                                    'status' => 1,
                                ],
                                [
                                    'product_id' => 102,
                                    'display_name' => 'ecs.g9i.4c8g',
                                    'cpu_memory_display' => '4 vCPU 8G',
                                    'category_full_name' => '云服务器 / 香港节点',
                                    'primary_price' => [
                                        'cycle' => 'quarter',
                                        'amount' => '299.00',
                                    ],
                                    'status' => 0,
                                ],
                            ],
                        ],
                        [
                            'id' => 'model_6248',
                            'value' => 'intel_xeon_gold_6248',
                            'name' => 'Intel Xeon Gold 6248',
                        ],
                    ],
                ],
                [
                    'id' => 'group_amd',
                    'value' => 'amd_epyc',
                    'name' => 'AMD EPYC',
                    'models' => [
                        [
                            'id' => 'model_7k62',
                            'value' => 'amd_epyc_7k62',
                            'name' => 'AMD EPYC 7K62',
                        ],
                    ],
                ],
            ],
        ];

        $this->postJson('/api/v2/admin/cpu-model-catalog', $payload)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.name', 'Intel Xeon')
            ->assertJsonPath('data.list.0.models.0.name', 'Intel Xeon Gold 6133')
            ->assertJsonPath('data.list.1.name', 'AMD EPYC')
            ->assertJsonPath('data.list.1.models.0.name', 'AMD EPYC 7K62');

        $storedValue = DB::table('settings')
            ->where('group_key', 'product')
            ->where('item_key', 'cpu_model_catalog')
            ->value('item_value');

        $this->assertIsString($storedValue);

        $decoded = json_decode((string) $storedValue, true);
        $this->assertIsArray($decoded);
        $this->assertSame('Intel Xeon', $decoded[0]['name'] ?? null);
        $this->assertSame('AMD EPYC 7K62', $decoded[1]['models'][0]['name'] ?? null);
        $this->assertSame('2.50GHz', $decoded[0]['models'][0]['base_frequency'] ?? null);
        $this->assertSame('3.20GHz', $decoded[0]['models'][0]['turbo_frequency'] ?? null);
        $this->assertSame(101, $decoded[0]['models'][0]['bindings'][0]['product_id'] ?? null);
        $this->assertSame('未配置规格 #101', $decoded[0]['models'][0]['bindings'][0]['display_name'] ?? null);
        $this->assertSame('未配置规格 #101', $decoded[0]['models'][0]['bindings'][0]['cpu_memory_display'] ?? null);
        $this->assertSame('35.00', $decoded[0]['models'][0]['bindings'][0]['primary_price']['amount'] ?? null);
        $this->assertSame(1, $decoded[0]['models'][0]['bindings'][0]['status'] ?? null);

        $this->getJson('/api/v2/admin/cpu-model-catalog')
            ->assertOk()
            ->assertJsonPath('data.list.0.name', 'Intel Xeon')
            ->assertJsonPath('data.list.0.models.1.name', 'Intel Xeon Gold 6248')
            ->assertJsonPath('data.list.0.models.0.base_frequency', '2.50GHz')
            ->assertJsonPath('data.list.0.models.0.turbo_frequency', '3.20GHz')
            ->assertJsonPath('data.list.0.models.0.bindings.0.display_name', '未配置规格 #101')
            ->assertJsonPath('data.list.0.models.0.bindings.0.cpu_memory_display', '未配置规格 #101')
            ->assertJsonPath('data.list.0.models.0.bindings.1.status', 0)
            ->assertJsonPath('data.list.1.models.0.name', 'AMD EPYC 7K62');
    }
}
