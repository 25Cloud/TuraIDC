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

class AdminInstanceSpecCatalogControllerTest extends TestCase
{
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
    }

    protected function tearDown(): void
    {
        Schema::connection('sqlite')->dropIfExists('admin_users');
        Schema::connection('sqlite')->dropIfExists('roles');
        Schema::connection('sqlite')->dropIfExists('settings');

        parent::tearDown();
    }

    public function test_admin_can_fetch_and_save_instance_spec_catalog(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'instance-spec-catalog-'.$suffix,
            'label' => 'Instance Spec Catalog',
            'permissions' => [AdminPermissions::PRODUCT_MANAGE],
        ]);

        $admin = AdminUser::query()->create([
            'username' => 'instance-spec-catalog-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Instance Spec Catalog',
            'email' => 'instance-spec-catalog-'.$suffix.'@example.com',
            'status' => 1,
        ]);

        Sanctum::actingAs($admin);

        $this->getJson('/api/v2/admin/instance-spec-catalog')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $payload = [
            'list' => [
                [
                    'id' => 'spec_2c2g',
                    'value' => 'ecs_g9i_2c2g',
                    'text' => 'ecs.g9i.2c2g',
                    'alias' => '2 核 2G',
                    'note' => '入门规格',
                    'status' => '仅文本',
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
                    ],
                ],
                [
                    'id' => 'spec_4c8g',
                    'value' => 'ecs_g9i_4c8g',
                    'text' => 'ecs.g9i.4c8g',
                    'alias' => '4 核 8G',
                    'note' => '中配规格',
                ],
            ],
        ];

        $this->postJson('/api/v2/admin/instance-spec-catalog', $payload)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.text', 'ecs.g9i.2c2g')
            ->assertJsonPath('data.list.1.text', 'ecs.g9i.4c8g');

        $storedValue = DB::table('settings')
            ->where('group_key', 'product')
            ->where('item_key', 'instance_spec_catalog')
            ->value('item_value');

        $this->assertIsString($storedValue);

        $decoded = json_decode((string) $storedValue, true);
        $this->assertIsArray($decoded);
        $this->assertSame('ecs.g9i.2c2g', $decoded[0]['text'] ?? null);
        $this->assertSame('未配置规格 #101', $decoded[0]['bindings'][0]['display_name'] ?? null);
        $this->assertSame('未配置规格 #101', $decoded[0]['bindings'][0]['cpu_memory_display'] ?? null);
    }
}
