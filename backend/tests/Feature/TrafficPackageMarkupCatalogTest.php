<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\System\SettingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 流量包目录的加价：售价（price）永远由成本价（cost_price）+ 加价规则算出，
 * 上游价即成本（流量包不走上游代理折扣）。
 */
class TrafficPackageMarkupCatalogTest extends TestCase
{
    use DatabaseTransactions;

    private string $originalItems = '[]';

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalItems = (string) Setting::getValue('traffic_package_catalog', 'items', '[]');
    }

    protected function tearDown(): void
    {
        Setting::setValue('traffic_package_catalog', 'items', $this->originalItems);

        parent::tearDown();
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function seedCatalog(array $items): void
    {
        Setting::setValue(
            'traffic_package_catalog',
            'items',
            (string) json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    #[Test]
    public function percent_markup_recomputes_price_from_cost(): void
    {
        $this->seedCatalog([[
            'category_id' => 1337,
            'product_type' => 'dcimcloud',
            'label' => '1T',
            'target_value' => 1024,
            'cost_price' => '30.00',
            'markup_type' => 'percent',
            'markup_value' => 20,
            // 手填的 price 必须被规则覆盖
            'price' => '1.00',
        ]]);

        $item = app(SettingService::class)->getTrafficPackageCatalog()[0];

        $this->assertSame('30.00', $item['cost_price']);
        $this->assertSame('percent', $item['markup_type']);
        $this->assertSame(20.0, $item['markup_value']);
        $this->assertSame('36.00', $item['price']);
    }

    #[Test]
    public function fixed_markup_and_no_markup_keep_manual_price(): void
    {
        $this->seedCatalog([
            [
                'category_id' => 1337,
                'product_type' => 'dcimcloud',
                'label' => '1T',
                'target_value' => 1024,
                'cost_price' => '30.00',
                'markup_type' => 'fixed',
                'markup_value' => 8,
                'price' => '0.00',
            ],
            [
                'category_id' => 1337,
                'product_type' => 'dcimcloud',
                'label' => '2T',
                'target_value' => 2048,
                'price' => '59.90',
            ],
        ]);

        $items = collect(app(SettingService::class)->getTrafficPackageCatalog())->keyBy('target_value');

        $this->assertSame('38.00', $items[1024]['price']);
        // 没设加价的老数据原样保留，不因新增字段被改价
        $this->assertSame('59.90', $items[2048]['price']);
        $this->assertSame('none', $items[2048]['markup_type']);
        $this->assertSame('0.00', $items[2048]['cost_price']);
    }

    #[Test]
    public function invalid_markup_type_falls_back_to_none(): void
    {
        $this->seedCatalog([[
            'category_id' => 1337,
            'product_type' => 'dcimcloud',
            'label' => '1T',
            'target_value' => 1024,
            'cost_price' => '30.00',
            'markup_type' => 'whatever',
            'markup_value' => 50,
            'price' => '45.00',
        ]]);

        $item = app(SettingService::class)->getTrafficPackageCatalog()[0];

        $this->assertSame('none', $item['markup_type']);
        $this->assertSame('45.00', $item['price']);
    }
}
