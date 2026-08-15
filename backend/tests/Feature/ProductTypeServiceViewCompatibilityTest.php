<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ProductType;
use App\Models\Setting;
use App\Services\ProductCatalog\ProductTypeService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProductTypeServiceViewCompatibilityTest extends TestCase
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
        Setting::forgetCachedGroup(ProductType::SETTING_GROUP);
        ProductType::resetCache();
    }

    protected function tearDown(): void
    {
        foreach (['first_product_groups', 'second_product_groups', 'third_product_groups'] as $view) {
            DB::statement("DROP VIEW IF EXISTS {$view}");
        }

        foreach (['settings', 'products', 'third_product_group_rows', 'second_product_group_rows', 'first_product_group_rows'] as $table) {
            Schema::connection('sqlite')->dropIfExists($table);
        }

        Setting::forgetCachedGroup(ProductType::SETTING_GROUP);
        ProductType::resetCache();

        parent::tearDown();
    }

    public function test_it_counts_products_when_hierarchy_sources_are_compatibility_views(): void
    {
        DB::table('first_product_group_rows')->insert([
            'id' => 1,
            'code' => 'vps',
            'product_type' => 'cloud_server',
            'name' => '云服务器',
        ]);
        DB::table('second_product_group_rows')->insert([
            'id' => 10,
            'first_product_group_id' => 1,
            'name' => '翼阳',
        ]);
        DB::table('third_product_group_rows')->insert([
            'id' => 100,
            'second_product_group_id' => 10,
            'name' => '高宽',
        ]);
        DB::table('products')->insert([
            'id' => 1000,
            'product_group_id' => 100,
            'deleted_at' => null,
        ]);

        $this->assertFalse(Schema::hasTable('first_product_groups'));
        $this->assertTrue(Schema::hasView('first_product_groups'));

        $type = collect(app(ProductTypeService::class)->list())->firstWhere('value', 'vps');

        $this->assertSame(1, (int) ($type['first_product_group_id'] ?? 0));
        $this->assertSame(1, (int) ($type['usage_count'] ?? 0));
        $this->assertSame(2, (int) ($type['group_count'] ?? 0));
    }

    private function createSchema(): void
    {
        Schema::connection('sqlite')->create('first_product_group_rows', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50);
            $table->string('product_type', 50)->nullable();
            $table->string('name', 100);
        });
        Schema::connection('sqlite')->create('second_product_group_rows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('first_product_group_id');
            $table->string('name', 100);
        });
        Schema::connection('sqlite')->create('third_product_group_rows', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('second_product_group_id');
            $table->string('name', 100);
        });
        Schema::connection('sqlite')->create('products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_group_id')->nullable();
            $table->softDeletes();
        });
        Schema::connection('sqlite')->create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group_key')->nullable();
            $table->string('item_key')->nullable();
            $table->text('item_value')->nullable();
        });

        DB::statement('CREATE VIEW first_product_groups AS SELECT id, code, product_type, name FROM first_product_group_rows');
        DB::statement('CREATE VIEW second_product_groups AS SELECT id, first_product_group_id, name FROM second_product_group_rows');
        DB::statement('CREATE VIEW third_product_groups AS SELECT id, second_product_group_id, name FROM third_product_group_rows');
    }
}
