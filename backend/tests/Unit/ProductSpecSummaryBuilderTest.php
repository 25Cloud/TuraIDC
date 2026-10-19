<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Product;
use App\Services\ProductCatalog\ProductSpecSummaryBuilder;
use Tests\TestCase;

/**
 * 商品规范规格摘要回归。
 *
 * 锁定的是「商品名口径统一」的解析规则：
 *   1. 上游配置只有一个取值时，配置就是权威（名字里写没写都不影响）；
 *   2. 上游配置是「规格族」（4核/8核/12核…）时，具体档位只在名字里，必须从名字取；
 *   3. 名字里的型号数字（13900K、3960X）不能被当成规格；
 *   4. 虚拟主机/CDN 这类没有处理器规格的商品不硬凑摘要；
 *   5. 单位统一成紧凑的 核 / G / Mbps，内存用 MB 表述时保留 M。
 */
class ProductSpecSummaryBuilderTest extends TestCase
{
    public function test_it_uses_config_when_each_dimension_has_a_single_value(): void
    {
        $builder = new ProductSpecSummaryBuilder;

        $summary = $builder->build(new Product([
            'custom_display_name' => '宿迁AMD 锐龙 Threadripper 3960X三线BGP 2G2H',
            'config_options' => [
                $this->option('核心', 'cpu', 6, [['option_name' => '2核心', 'option_name_first' => '2']]),
                $this->option('内存', 'mem', 8, [['option_name' => '2G', 'option_name_first' => '2']]),
                $this->option('带宽', 'band', 10, [['option_name' => '30M', 'option_name_first' => '30']]),
            ],
        ]));

        $this->assertSame('2核2G30Mbps', $summary);
    }

    public function test_it_reads_spec_from_name_when_config_is_a_spec_family(): void
    {
        $builder = new ProductSpecSummaryBuilder;

        $summary = $builder->build(new Product([
            'custom_display_name' => '内蒙电信 8核8G45M',
            'config_options' => [
                $this->option('CPU', 'cpu', 6, $this->subs(['4核', '8核', '12核', '16核', '24核', '32核'])),
                $this->option('内存', 'memory', 8, $this->subs(['4GB', '8GB', '12GB', '16GB', '32GB', '64GB'])),
                // 上游把带宽配置项存成占位文案，没有可校验的取值 → 摘要里不带带宽段
                $this->option('带宽', 'bw', 11, [['option_name' => '带宽', 'option_name_first' => '']]),
            ],
        ]));

        $this->assertSame('8核8G', $summary);
    }

    public function test_it_ignores_cpu_model_numbers_in_product_name(): void
    {
        $builder = new ProductSpecSummaryBuilder;

        $summary = $builder->build(new Product([
            'custom_display_name' => '枣庄BGP 13900K 8核8G',
            'config_options' => [
                $this->option('CPU', 'cpu', 6, $this->subs(['4核', '8核', '16核'])),
                $this->option('内存', 'memory', 8, $this->subs(['4GB', '8GB', '16GB'])),
            ],
        ]));

        // 13900K 里的 13900 不在允许核数集合内，必须被忽略
        $this->assertSame('8核8G', $summary);
    }

    public function test_it_supports_reverse_order_h_and_c_cpu_units(): void
    {
        $builder = new ProductSpecSummaryBuilder;

        $summary = $builder->build(new Product([
            'custom_display_name' => '宿迁AMD 锐龙 Threadripper 3960X三线BGP 2G4H',
            'config_options' => [
                $this->option('核心', 'cpu', 6, $this->subs(['2核心', '4核心'])),
                $this->option('内存', 'mem', 8, $this->subs(['2G', '4G'])),
            ],
        ]));

        $this->assertSame('4核2G', $summary);
    }

    public function test_it_returns_empty_summary_for_non_spec_products(): void
    {
        $builder = new ProductSpecSummaryBuilder;

        // 虚拟主机的配置项 field 全部复用成 cpu，名称都与处理器规格无关
        $summary = $builder->build(new Product([
            'custom_display_name' => '香港HK 大带宽主机-A',
            'config_options' => [
                $this->option('绑定域名数', 'cpu', 6, [['option_name' => '不限制', 'option_name_first' => '']]),
                $this->option('流量限制', 'cpu', 6, [['option_name' => '20G', 'option_name_first' => '20']]),
                $this->option('带宽', 'cpu', 6, [['option_name' => '100Mbps峰值共享', 'option_name_first' => '100']]),
                $this->option('WEB空间', 'cpu', 6, [['option_name' => '100M', 'option_name_first' => '100']]),
            ],
        ]));

        $this->assertSame('', $summary);
    }

    public function test_it_keeps_megabyte_memory_unit(): void
    {
        $builder = new ProductSpecSummaryBuilder;

        $summary = $builder->build(new Product([
            'custom_display_name' => '中国台湾(臺灣)彰化 TCVM - Mini',
            'config_options' => [
                $this->option('CPU', 'cpu', 6, [['option_name' => '1核', 'option_name_first' => '1']]),
                $this->option('内存', 'memory', 8, [['option_name' => '512M', 'option_name_first' => '512']]),
                $this->option('带宽', 'bw', 10, [['option_name' => '200Mbps', 'option_name_first' => '200']]),
            ],
        ]));

        $this->assertSame('1核512M200Mbps', $summary);
    }

    public function test_it_formats_upload_and_download_bandwidth(): void
    {
        $builder = new ProductSpecSummaryBuilder;

        $summary = $builder->build(new Product([
            'custom_display_name' => '测试机房 4H4G 10M上行 80M下行',
            'config_options' => [
                $this->option('CPU', 'cpu', 6, [['option_name' => '4核', 'option_name_first' => '4']]),
                $this->option('内存', 'memory', 8, [['option_name' => '4G', 'option_name_first' => '4']]),
                $this->option('带宽', 'bw', 10, [
                    ['option_name' => '10M上行 80M下行', 'option_name_first' => '10'],
                    ['option_name' => '20M上行 100M下行', 'option_name_first' => '20'],
                ]),
            ],
        ]));

        $this->assertSame('4核4G上行10Mbps/下行80Mbps', $summary);
    }

    /**
     * @param  array<int, array<string, mixed>>  $subs
     * @return array<string, mixed>
     */
    private function option(string $name, string $field, int $optionType, array $subs): array
    {
        return [
            'name' => $name,
            'field' => $field,
            'option_type' => $optionType,
            'sub' => $subs,
        ];
    }

    /**
     * @param  array<int, string>  $labels
     * @return array<int, array<string, mixed>>
     */
    private function subs(array $labels): array
    {
        return array_map(
            static fn (string $label): array => ['option_name' => $label, 'option_name_first' => ''],
            $labels,
        );
    }
}
