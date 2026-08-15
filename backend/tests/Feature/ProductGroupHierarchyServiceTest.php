<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ProductCatalog\ProductGroupHierarchyService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductGroupHierarchyServiceTest extends TestCase
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

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        foreach ([
            'settings',
            'products',
            'third_product_groups',
            'second_product_groups',
            'first_product_groups',
        ] as $table) {
            Schema::connection('sqlite')->dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_it_reports_physical_product_group_hierarchy_as_clean(): void
    {
        $rootId = (int) DB::table('first_product_groups')->insertGetId([
            'code' => 'vps',
            'product_type' => 'cloud_server',
            'name' => '云服务器',
            'sort_order' => 1,
            'is_visible' => 1,
            'is_system' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $secondId = (int) DB::table('second_product_groups')->insertGetId([
            'first_product_group_id' => $rootId,
            'name' => '香港',
            'sort_order' => 1,
            'is_visible' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $thirdId = (int) DB::table('third_product_groups')->insertGetId([
            'second_product_group_id' => $secondId,
            'name' => '三网精品',
            'sort_order' => 1,
            'is_visible' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = Product::query()->create([
            'product_group_id' => $thirdId,
            'name' => '8vcpu-16gib',
            'product_type' => 'cloud_server',
            'service_type_code' => 'cloud_server',
            'pricing' => ['monthly' => '88.00'],
            'purchase_requires' => [],
            'config_options' => [],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 1,
            'provision_module' => null,
            'auto_setup' => 0,
        ]);

        $result = app(ProductGroupHierarchyService::class)->checkHierarchy();

        $this->assertDatabaseHas('products', [
            'id' => (int) $product->id,
            'product_group_id' => $thirdId,
            'service_type_code' => 'cloud_server',
        ]);
        $this->assertSame([], $result['blocking_errors']);
        $this->assertSame(1, $result['counts']['root_product_groups']);
        $this->assertSame(1, $result['counts']['second_product_groups']);
        $this->assertSame(1, $result['counts']['third_product_groups']);
    }

    public function test_it_reports_products_with_missing_product_group(): void
    {
        DB::table('first_product_groups')->insert([
            'code' => 'vps',
            'product_type' => 'cloud_server',
            'name' => '云服务器',
            'sort_order' => 1,
            'is_visible' => 1,
            'is_system' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = Product::query()->create([
            'product_group_id' => 999,
            'name' => 'missing-legacy-group-product',
            'custom_display_name' => '历史缺失分类商品',
            'product_type' => 'cloud_server',
            'service_type_code' => 'cloud_server',
            'pricing' => ['monthly' => '88.00'],
            'purchase_requires' => [],
            'config_options' => [],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 1,
            'provision_module' => null,
            'auto_setup' => 0,
        ]);

        $result = app(ProductGroupHierarchyService::class)->checkHierarchy();

        $this->assertSame(1, $result['counts']['orphan_product_group_products']);
        $this->assertNotSame([], $result['blocking_errors']);
        $this->assertDatabaseHas('products', [
            'id' => (int) $product->id,
            'product_group_id' => 999,
        ]);
    }

    public function test_product_scopes_resolve_only_the_requested_physical_group_level(): void
    {
        DB::table('first_product_groups')->insert([
            ['id' => 1, 'code' => 'vps', 'product_type' => 'cloud_server', 'name' => '云服务器', 'sort_order' => 1, 'is_visible' => 1, 'is_system' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'code' => 'game', 'product_type' => 'game', 'name' => '游戏云', 'sort_order' => 2, 'is_visible' => 1, 'is_system' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('second_product_groups')->insert([
            ['id' => 1, 'first_product_group_id' => 1, 'name' => '香港', 'sort_order' => 1, 'is_visible' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'first_product_group_id' => 2, 'name' => '日本', 'sort_order' => 2, 'is_visible' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('third_product_groups')->insert([
            ['id' => 1, 'second_product_group_id' => 1, 'name' => '标准型', 'sort_order' => 1, 'is_visible' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'second_product_group_id' => 2, 'name' => '高性能', 'sort_order' => 2, 'is_visible' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('products')->insert([
            ['id' => 101, 'product_group_id' => 1, 'service_type_code' => 'cloud_server', 'name' => '香港标准', 'product_type' => 'cloud_server', 'pricing' => '{}', 'purchase_requires' => '{}', 'config_options' => '{}', 'stock' => -1, 'status' => 1, 'sort_order' => 1, 'auto_setup' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 102, 'product_group_id' => 2, 'service_type_code' => 'game', 'name' => '日本高性能', 'product_type' => 'game', 'pricing' => '{}', 'purchase_requires' => '{}', 'config_options' => '{}', 'stock' => -1, 'status' => 1, 'sort_order' => 2, 'auto_setup' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->assertSame([101], Product::query()->inFirstProductGroup(1)->pluck('id')->all());
        $this->assertSame([101], Product::query()->inSecondProductGroup(1)->pluck('id')->all());
        $this->assertSame([101], Product::query()->inCurrentProductGroup(1)->pluck('id')->all());
        $this->assertSame([102], Product::query()->inFirstProductGroup(2)->pluck('id')->all());
    }

    private function createSchema(): void
    {
        Schema::connection('sqlite')->create('first_product_groups', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('product_type', 50)->nullable();
            $table->string('name', 100);
            $table->string('slug', 100)->nullable()->unique();
            $table->string('description', 255)->nullable();
            $table->string('icon', 100)->nullable();
            $table->string('banner_image', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->integer('is_visible')->default(1);
            $table->integer('is_system')->default(0);
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('second_product_groups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('first_product_group_id');
            $table->string('name', 100);
            $table->string('slug', 100)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('banner_image', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->integer('is_visible')->default(1);
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('third_product_groups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('second_product_group_id');
            $table->string('name', 100);
            $table->string('slug', 100)->nullable();
            $table->string('description', 255)->nullable();
            $table->string('banner_image', 255)->nullable();
            $table->integer('sort_order')->default(0);
            $table->integer('is_visible')->default(1);
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_group_id')->nullable();
            $table->string('service_type_code', 50)->nullable();
            $table->string('name')->nullable();
            $table->string('custom_display_name')->nullable();
            $table->string('product_type')->nullable();
            $table->json('pricing')->nullable();
            $table->json('purchase_requires')->nullable();
            $table->json('config_options')->nullable();
            $table->integer('stock')->default(-1);
            $table->integer('status')->default(1);
            $table->integer('sort_order')->default(0);
            $table->string('provision_module')->nullable();
            $table->integer('auto_setup')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::connection('sqlite')->create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group_key')->nullable();
            $table->string('item_key')->nullable();
            $table->text('item_value')->nullable();
            $table->timestamps();
        });
    }
}
