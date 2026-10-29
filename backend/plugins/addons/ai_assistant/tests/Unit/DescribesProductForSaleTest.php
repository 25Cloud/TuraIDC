<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\FirstProductGroup;
use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use Tests\TestCase;
use TuraIDC\Plugins\Addons\AiAssistant\Services\Tools\Concerns\DescribesProductForSale;

/**
 * 商品「完整叫法 + 官网链接」的回归。
 *
 * 钉死用户实测的两条投诉：
 *   1. AI 只会说「2核2G20M」——`custom_display_name` 只存规格，区域与机房全在分组名里，
 *      所以给模型的那份必须带「区域 · 机房/线路 · 规格」与分组路径；
 *   2. AI 不给链接——用户看完还得自己翻目录找回去。
 *
 * 地址口径必须与官网一致（frontend-user-v3-www/src/utils/productRoute.js）：
 * `/products/{一级slug}/{二级id}/{三级id}/{商品id}`；缺段就退到旧版 `/products/{商品id}`，
 * 绝不拼出官网打不开的地址。
 */
class DescribesProductForSaleTest extends TestCase
{
    public function test_it_builds_region_aware_title_path_and_links(): void
    {
        $product = $this->makeProduct();

        $row = $this->describer()->describe($product);

        // 「2核2G20Mbps」单独出现时用户不知道是哪台机器，标题必须带区域与机房
        $this->assertSame('华中 · 湖北襄阳 电信 · 2核2G20Mbps', $row['title']);
        $this->assertSame('华中', $row['region']);
        $this->assertSame('湖北襄阳 电信', $row['site']);
        $this->assertSame('2核2G20Mbps', $row['spec']);
        $this->assertSame('云电脑 / 华中 / 湖北襄阳 电信', $row['group_path']);
        $this->assertSame('云电脑', $row['product_type']);

        $this->assertSame(
            'http://www.example.test/products/cloud-desktop/104/2004/88',
            $row['product_url'],
        );
        $this->assertSame(
            'http://www.example.test/products/cloud-desktop/104/2004',
            $row['group_url'],
        );
        $this->assertSame(
            '[华中 · 湖北襄阳 电信 · 2核2G20Mbps](http://www.example.test/products/cloud-desktop/104/2004/88)',
            $row['link'],
        );
    }

    public function test_it_marks_sold_out_products(): void
    {
        $product = $this->makeProduct();
        $product->stock = 0;

        $row = $this->describer()->describe($product);

        // -1 是不限库存，0 才是售罄；模型据此决定要不要说「暂时缺货」
        $this->assertFalse($row['in_stock']);
        $this->assertSame(0, $row['stock']);
    }

    public function test_it_falls_back_to_relative_links_when_frontend_url_is_missing(): void
    {
        config(['app.frontend_url' => '']);

        $row = $this->describer()->describe($this->makeProduct());

        // 站点地址没配不该让「查商品」整体失败，链接退回相对路径仍然可点
        $this->assertSame('/products/cloud-desktop/104/2004/88', $row['product_url']);
        $this->assertSame('/products/cloud-desktop/104/2004', $row['group_url']);
    }

    public function test_it_falls_back_to_the_legacy_product_link_without_a_group_slug(): void
    {
        $product = $this->makeProduct();

        /** @var ThirdProductGroup $third */
        $third = $product->getRelation('productGroup');
        /** @var SecondProductGroup $second */
        $second = $third->getRelation('secondProductGroup');
        /** @var FirstProductGroup $first */
        $first = $second->getRelation('firstProductGroup');
        $first->slug = '';
        $first->code = '';

        $row = $this->describer()->describe($product);

        // 旧版独立配置页官网仍然兼容，任何分支都得能打开
        $this->assertSame('http://www.example.test/products/88', $row['product_url']);
        $this->assertSame('http://www.example.test/products', $row['group_url']);
    }

    private function describer(): object
    {
        return new class
        {
            use DescribesProductForSale;

            /** @return array<string, mixed> */
            public function describe(Product $product): array
            {
                return $this->describeProductForSale($product);
            }
        };
    }

    private function makeProduct(): Product
    {
        $first = new FirstProductGroup([
            'name' => '云电脑',
            'code' => 'cloud-desktop',
            'slug' => 'cloud-desktop',
        ]);
        $first->id = 7;

        $second = new SecondProductGroup([
            'name' => '华中',
            'slug' => 'huazhong',
            'description' => '华中大区',
        ]);
        $second->id = 104;

        $third = new ThirdProductGroup([
            'name' => '湖北襄阳 电信',
            'slug' => 'hbxy-dx',
            'description' => '',
        ]);
        $third->id = 2004;

        $product = new Product([
            'custom_display_name' => '2核2G20Mbps',
            'product_type' => 'dcimcloud',
            'stock' => -1,
        ]);
        $product->id = 88;
        $product->product_group_id = 2004;

        $product->setRelation('productGroup', $third);
        $third->setRelation('secondProductGroup', $second);
        $second->setRelation('firstProductGroup', $first);

        return $product;
    }
}
