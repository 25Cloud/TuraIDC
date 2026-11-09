<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\FirstProductGroup;
use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\Setting;
use App\Models\ThirdProductGroup;
use App\Services\ClientServiceConsole\ServiceTrafficPackageService;
use App\Services\System\SettingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * traffic:backfill-catalog：把空目录铺成可售档位，并修掉会让功能永远匹配不到档位的配置。
 *
 * 命令本身不重复实现拉取逻辑，它只是把「后台一键对接」按分类跑一遍，
 * 所以测试里 mock 掉 pullCatalogTemplateForAdmin，重点验证落库口径。
 */
class BackfillTrafficPackageCatalogTest extends TestCase
{
    use DatabaseTransactions;

    private string $originalCatalog = '[]';

    /** @var list<int> */
    private array $productIds = [];

    /** @var list<int> */
    private array $thirdGroupIds = [];

    /** @var list<int> */
    private array $secondGroupIds = [];

    /** @var list<int> */
    private array $firstGroupIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalCatalog = (string) Setting::getValue('traffic_package_catalog', 'items', '[]');
    }

    protected function tearDown(): void
    {
        Setting::setValue('traffic_package_catalog', 'items', $this->originalCatalog);

        DB::connection()->table('products')->whereIn('id', $this->productIds)->delete();
        DB::connection()->table('third_product_groups')->whereIn('id', $this->thirdGroupIds)->delete();
        DB::connection()->table('second_product_groups')->whereIn('id', $this->secondGroupIds)->delete();
        DB::connection()->table('first_product_groups')->whereIn('id', $this->firstGroupIds)->delete();

        parent::tearDown();
    }

    #[Test]
    public function it_writes_cost_price_and_markup_into_the_catalog(): void
    {
        $product = $this->createUpstreamProduct('write');
        $catalogType = (string) $product->product_type;
        $categoryId = (int) $product->product_group_id;

        $this->mockPull([
            ['label' => '100G', 'target_value' => 100, 'price' => '10.00'],
            ['label' => '500G', 'target_value' => 500, 'price' => '48.00'],
        ]);

        $this->artisan('traffic:backfill-catalog', [
            '--markup-type' => 'percent',
            '--markup-value' => '20',
            '--ignore-bindings' => true,
        ])->assertExitCode(0);

        $items = collect(app(SettingService::class)->getTrafficPackageCatalog())
            ->where('category_id', $categoryId)
            ->where('product_type', $catalogType)
            ->sortBy('target_value')
            ->values();

        $this->assertCount(2, $items);
        $this->assertSame('10.00', $items[0]['cost_price']);
        $this->assertSame('12.00', $items[0]['price']);
        $this->assertSame('percent', $items[0]['markup_type']);
        $this->assertSame(20.0, $items[0]['markup_value']);
        $this->assertSame(1, (int) $items[0]['enabled']);
        $this->assertSame('57.60', $items[1]['price']);
    }

    #[Test]
    public function zero_cost_tiers_are_written_as_disabled(): void
    {
        $product = $this->createUpstreamProduct('zero');
        $catalogType = (string) $product->product_type;

        $this->mockPull([
            ['label' => '100G', 'target_value' => 100, 'price' => '0.00'],
            ['label' => '500G', 'target_value' => 500, 'price' => '48.00'],
        ]);

        $this->artisan('traffic:backfill-catalog', ['--ignore-bindings' => true])->assertExitCode(0);

        $items = collect(app(SettingService::class)->getTrafficPackageCatalog())
            ->where('category_id', (int) $product->product_group_id)
            ->where('product_type', $catalogType)
            ->sortBy('target_value')
            ->values();

        // 上游没报价的档位不能按 0 元卖出去
        $this->assertSame(0, (int) $items[0]['enabled']);
        $this->assertSame(1, (int) $items[1]['enabled']);
        $this->assertSame('48.00', $items[1]['price']);
    }

    #[Test]
    public function it_keeps_manual_tiers_unless_forced(): void
    {
        $product = $this->createUpstreamProduct('keep');
        $catalogType = (string) $product->product_type;
        $categoryId = (int) $product->product_group_id;

        Setting::setValue('traffic_package_catalog', 'items', (string) json_encode([[
            'category_id' => $categoryId,
            'product_type' => $catalogType,
            'label' => '手工档位',
            'target_value' => 200,
            'price' => '99.00',
            'enabled' => 1,
        ]], JSON_UNESCAPED_UNICODE));

        $this->mockPull([
            ['label' => '100G', 'target_value' => 100, 'price' => '10.00'],
        ]);

        $this->artisan('traffic:backfill-catalog', [
            '--category' => [(string) $categoryId],
            '--ignore-bindings' => true,
        ])->assertExitCode(0);

        $items = app(SettingService::class)->getTrafficPackageCatalog();
        $this->assertCount(1, $items);
        $this->assertSame(200, (int) $items[0]['target_value']);

        $this->artisan('traffic:backfill-catalog', [
            '--category' => [(string) $categoryId],
            '--force' => true,
            '--ignore-bindings' => true,
        ])->assertExitCode(0);

        $items = app(SettingService::class)->getTrafficPackageCatalog();
        $this->assertCount(1, $items);
        $this->assertSame(100, (int) $items[0]['target_value']);
        $this->assertSame('10.00', $items[0]['cost_price']);
    }

    #[Test]
    public function dry_run_does_not_touch_the_catalog(): void
    {
        $this->createUpstreamProduct('dry');
        $this->mockPull([
            ['label' => '100G', 'target_value' => 100, 'price' => '10.00'],
        ]);

        $this->artisan('traffic:backfill-catalog', [
            '--dry-run' => true,
            '--ignore-bindings' => true,
        ])->assertExitCode(0);

        $this->assertSame([], app(SettingService::class)->getTrafficPackageCatalog());
    }

    #[Test]
    public function repair_config_fills_blank_matching_fields_and_modes(): void
    {
        Setting::setValue('traffic_package', 'traffic_package_option_field', '');
        Setting::setValue('traffic_package', 'traffic_package_option_keyword', '');
        Setting::setValue('traffic_package', 'traffic_package_allow_choice_mode', '0');
        Setting::setValue('traffic_package', 'traffic_package_allow_quantity_mode', '0');
        // 总开关不在修复范围内，必须原样保留
        Setting::setValue('traffic_package', 'traffic_package_enabled', '0');

        $this->createUpstreamProduct('repair');
        $this->mockPull([
            ['label' => '100G', 'target_value' => 100, 'price' => '10.00'],
        ]);

        $this->artisan('traffic:backfill-catalog', [
            '--repair-config' => true,
            '--ignore-bindings' => true,
        ])->assertExitCode(0);

        $config = app(SettingService::class)->getTrafficPackageConfig();
        $this->assertSame('flow_limit', $config['option_field']);
        $this->assertSame('流量', $config['option_keyword']);
        $this->assertTrue($config['allow_choice_mode']);
        $this->assertTrue($config['allow_quantity_mode']);
        $this->assertFalse($config['enabled'], '总开关必须保持原值，不能被修复命令打开');
    }

    /**
     * @param  list<array<string, mixed>>  $packages
     */
    private function mockPull(array $packages): void
    {
        $traffic = $this->createMock(ServiceTrafficPackageService::class);
        $traffic->method('pullCatalogTemplateForAdmin')->willReturn([
            'source' => ['mode' => 'product'],
            'traffic' => [],
            'packages' => $packages,
        ]);
        $this->swap(ServiceTrafficPackageService::class, $traffic);
    }

    private function createUpstreamProduct(string $suffix): Product
    {
        $firstGroup = FirstProductGroup::query()->create([
            'code' => 'bf-'.$suffix,
            'name' => 'Backfill Root '.$suffix,
            'slug' => 'backfill-root-'.$suffix,
            'sort_order' => 0,
            'is_visible' => 1,
            'is_system' => 0,
            'product_type' => 'cloud_server',
        ]);
        $this->firstGroupIds[] = (int) $firstGroup->id;

        $secondGroup = SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => 'Backfill Category '.$suffix,
            'slug' => 'backfill-category-'.$suffix,
            'sort_order' => 0,
            'is_visible' => 1,
        ]);
        $this->secondGroupIds[] = (int) $secondGroup->id;

        $thirdGroup = ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $secondGroup->id,
            'name' => 'Backfill Leaf '.$suffix,
            'slug' => 'backfill-leaf-'.$suffix,
            'sort_order' => 0,
            'is_visible' => 1,
        ]);
        $this->thirdGroupIds[] = (int) $thirdGroup->id;

        $product = Product::query()->create([
            'name' => 'Backfill Product '.$suffix,
            'product_type' => 'other',
            'product_group_id' => (int) $thirdGroup->id,
            'pricing' => ['monthly' => '30.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'auto_setup' => 0,
        ]);
        $this->productIds[] = (int) $product->id;

        return $product;
    }
}
