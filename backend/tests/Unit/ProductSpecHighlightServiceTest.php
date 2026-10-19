<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\ProductCatalog\ProductSpecHighlightService;
use Tests\TestCase;

/**
 * 商品规格亮点提取回归。
 *
 * 这些用例锁定的是「前台看到的规格必须与上游商品描述一致」的解析口径：
 *   1. 上游描述（历史导入存在 remark）是转义过的 HTML，必须先解码实体再按行解析；
 *   2. 商品名常见写法「4C4G20M」里的 C 就是核；
 *   3. 导入数据给所有配置项补了 qty_minimum=0，下拉项不能因此被当成「最小值为 0」
 *      而丢掉取值。
 */
class ProductSpecHighlightServiceTest extends TestCase
{
    public function test_it_decodes_escaped_upstream_remark_html(): void
    {
        $service = new ProductSpecHighlightService();

        $highlights = $service->resolveHighlightsForProduct(new Product([
            'remark' => '&lt;li&gt;配置：4核 4G&lt;/li&gt;&lt;li&gt;硬盘：30G SSD&lt;/li&gt;'
                .'&lt;li&gt;带宽：20M峰值&lt;/li&gt;&lt;li&gt;防御：200Gbps&lt;/li&gt;'
                .'&lt;li&gt;流量：不限流量&lt;/li&gt;&lt;li&gt;线路：中国电信&lt;/li&gt;',
        ]));

        $values = collect($highlights)->pluck('value', 'key')->all();

        $this->assertSame('4核', $values['cpu'] ?? null);
        $this->assertSame('4G', $values['memory'] ?? null);
        $this->assertSame('30G SSD', $values['disk'] ?? null);
        $this->assertSame('200Gbps', $values['defense'] ?? null);
        $this->assertSame('不限流量', $values['traffic'] ?? null);
        $this->assertSame('中国电信', $values['route'] ?? null);
    }

    public function test_it_reads_cpu_from_4c_style_product_name(): void
    {
        $service = new ProductSpecHighlightService();

        $highlights = $service->resolveHighlightsForProduct(new Product([
            'custom_display_name' => '香港BGP轻量 4C4G20M',
            'product_type' => 'cloud',
        ]));

        $values = collect($highlights)->pluck('value', 'key')->all();

        $this->assertSame('4核', $values['cpu'] ?? null);
        $this->assertSame('4G', $values['memory'] ?? null);
        $this->assertSame('20M', $values['bandwidth'] ?? null);
    }

    public function test_it_cleans_truncated_remark_fragments(): void
    {
        $service = new ProductSpecHighlightService();

        // varchar(255) 截断后的形态：末尾留着半个标签
        $highlights = $service->resolveHighlightsForProduct(new Product([
            'remark' => '&lt;li&gt;硬盘：30G SSD&lt;/li&gt;&lt;li&gt;带宽：20M峰值&lt;span class',
        ]));

        $values = collect($highlights)->pluck('value', 'key')->all();

        $this->assertSame('20M峰值', $values['bandwidth'] ?? null);
        foreach ($values as $value) {
            $this->assertTrue(mb_check_encoding((string) $value, 'UTF-8'));
            $this->assertStringNotContainsString('<', (string) $value);
        }
    }

    public function test_it_does_not_corrupt_values_ending_with_chinese_punctuation(): void
    {
        $service = new ProductSpecHighlightService();

        // 「下行」的末字节 0x8C 与中文标点『，』的字节相同；用 trim() 按字节裁标点
        // 会把「行」裁成半个字符，线上「云服务器/华中」因此 500。
        $highlights = $service->resolveHighlightsForProduct(new Product([
            'remark' => '&lt;li&gt;带宽：40M上行 100M下行&lt;/li&gt;',
        ]));

        $values = collect($highlights)->pluck('value', 'key')->all();

        $this->assertSame('40M上行 100M下行', $values['bandwidth'] ?? null);
        $this->assertTrue(mb_check_encoding($values['bandwidth'], 'UTF-8'));
    }

    public function test_it_reads_select_sub_values_even_when_qty_minimum_is_zero(): void
    {
        $service = new ProductSpecHighlightService();

        $highlights = $service->resolveHighlightsForProduct(new Product([
            'config_options' => [
                [
                    'id' => 1,
                    'field' => 'cpu',
                    'name' => 'CPU',
                    'qty_minimum' => 0,
                    'sub' => [['version' => '4核', 'option_name_first' => '4']],
                ],
                [
                    'id' => 2,
                    'field' => 'memory',
                    'name' => '内存',
                    'qty_minimum' => 0,
                    'sub' => [['version' => '4GB', 'option_name_first' => '4096']],
                ],
                [
                    // 范围型配置项没有子项，取 qty_minimum + unit
                    'id' => 3,
                    'field' => 'bw',
                    'name' => '上行带宽',
                    'qty_minimum' => 20,
                    'unit' => '',
                    'sub' => [],
                ],
            ],
        ]));

        $values = collect($highlights)->pluck('value', 'key')->all();

        $this->assertSame('4核', $values['cpu'] ?? null);
        $this->assertSame('4GB', $values['memory'] ?? null);
        $this->assertSame('20', $values['bandwidth_up'] ?? null);
    }
}
