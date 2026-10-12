<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Supplier;
use App\Services\Integrations\Plugins\PluginFileLoader;
use App\Services\Integrations\Plugins\PluginScanner;
use App\Services\Upstream\Drivers\HostingPanelApi\HostingPanelApiTransport;
use Tests\TestCase;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfCloudConfigTemplate;
use TuraIDC\Plugins\Servers\ZjmfFinance\Lib\ZjmfFinanceAdapter;

/**
 * 魔方 IDC 上游配置项的 field 归一化回归。
 *
 * 编号语义以 zjmf376/app/app.php 的 configurable_option_type 为准：
 * 6=CpuDropdown 7=CpuQuantity 16=CpuQuantityStage，
 * 8=MemDropdown 9=MemQuantity 17=MemQuantityStage，
 * 15=QuantityStage，20=RadioLevelLinkAge。
 *
 * 修复前 resolveConfigOptionField() 对中文显示名做 Str::slug() 兜底，
 * 「处理器核心」会变成 _chu_l_i_he_xin，与下游 field==='cpu' 的取值失配，
 * 表现为「自选 CPU 和内存对接不过来」。
 */
class ZjmfConfigOptionFieldMappingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(PluginFileLoader::class)->ensureLoaded(
            app(PluginScanner::class)->requireManifest('upstream', 'zjmf_finance')
        );
    }

    public function test_it_maps_chinese_display_names_to_cpu_and_memory_without_pinyin_slug(): void
    {
        $options = $this->fetchConfigOptions([
            // option_type 1 是通用 Dropdown，CPU/内存也可能用它建项，
            // 此时没有任何 type 映射可用，只能靠显示名判定。
            ['id' => 31, 'option_type' => 1, 'option_name' => '处理器核心', 'sub' => []],
            ['id' => 32, 'option_type' => 1, 'option_name' => '内存', 'sub' => []],
            // 大写 key：cPanel 模板的 "CPU|处理器核心"，不归一会被当成 'CPU'。
            ['id' => 33, 'option_type' => 6, 'option_name' => 'CPU|处理器核心', 'sub' => []],
            ['id' => 34, 'option_type' => 8, 'option_name' => 'Memory|内存', 'sub' => []],
        ]);

        $this->assertSame('cpu', $options[31]['field']);
        $this->assertSame('memory', $options[32]['field']);
        $this->assertSame('cpu', $options[33]['field']);
        $this->assertSame('memory', $options[34]['field']);
    }

    public function test_it_maps_range_option_types_to_their_fields(): void
    {
        $options = $this->fetchConfigOptions([
            ['id' => 41, 'option_type' => 7, 'option_name' => 'CPU|处理器核心', 'sub' => []],
            ['id' => 42, 'option_type' => 9, 'option_name' => 'memory|内存', 'sub' => []],
            ['id' => 43, 'option_type' => 16, 'option_name' => 'cpu|处理器核心', 'sub' => []],
            ['id' => 44, 'option_type' => 17, 'option_name' => 'memory|内存', 'sub' => []],
            // 15=QuantityStage 是区间型，修复前映成 null 会掉进中文兜底。
            ['id' => 45, 'option_type' => 15, 'option_name' => '', 'sub' => []],
        ]);

        $this->assertSame('cpu', $options[41]['field']);
        $this->assertSame('memory', $options[42]['field']);
        $this->assertSame('cpu', $options[43]['field']);
        $this->assertSame('memory', $options[44]['field']);
        $this->assertSame('quantity', $options[45]['field']);
    }

    public function test_range_options_keep_quantity_bounds_and_unit(): void
    {
        $options = $this->fetchConfigOptions([
            [
                'id' => 51,
                'option_type' => 7,
                'option_name' => 'cpu|处理器核心',
                'unit' => '核',
                'qty_minimum' => 1,
                'qty_maximum' => 32,
                'qty_stage' => 1,
                'sub' => [],
            ],
        ]);

        $this->assertSame('cpu', $options[51]['field']);
        $this->assertSame(1, $options[51]['qty_minimum']);
        $this->assertSame(32, $options[51]['qty_maximum']);
        $this->assertSame('核', $options[51]['unit']);
    }

    public function test_data_disk_and_system_disk_are_distinguished_by_option_name(): void
    {
        // type 14 同时被系统盘区间与数据盘复用，只能靠 option_name 区分。
        $options = $this->fetchConfigOptions([
            ['id' => 61, 'option_type' => 14, 'option_name' => 'data_disk_size|数据盘', 'sub' => []],
            ['id' => 62, 'option_type' => 14, 'option_name' => 'system_disk_size|系统盘', 'sub' => []],
        ]);

        $this->assertSame('data_disk_size', $options[61]['field']);
        $this->assertSame('system_disk_size', $options[62]['field']);
    }

    public function test_inbound_and_outbound_bandwidth_are_not_swallowed_by_generic_bandwidth(): void
    {
        $options = $this->fetchConfigOptions([
            ['id' => 71, 'option_type' => 1, 'option_name' => '上行带宽', 'sub' => []],
            ['id' => 72, 'option_type' => 1, 'option_name' => '下行带宽', 'sub' => []],
        ]);

        $this->assertSame('out_bw', $options[71]['field']);
        $this->assertSame('in_bw', $options[72]['field']);
    }

    /**
     * @param  list<array<string, mixed>>  $configOptions
     * @return array<int, array<string, mixed>>
     */
    private function fetchConfigOptions(array $configOptions): array
    {
        $transport = new class($configOptions) extends HostingPanelApiTransport
        {
            public function __construct(private readonly array $configOptions) {}

            public function request(
                Supplier $supplier,
                string $method,
                string $uri,
                array|string $payload = [],
                ?string $jwt = null,
                array $headers = [],
                array $query = []
            ): array {
                if ($uri === '/zjmf_api_login') {
                    return ['status' => 200, 'jwt' => 'zjmf-jwt'];
                }

                if ($uri === '/cart/all') {
                    return [
                        'status' => 200,
                        'data' => [
                            'products' => [
                                [
                                    'id' => 'test-cloud-group',
                                    'name' => '云服务器',
                                    'products' => [
                                        ['id' => 1001, 'name' => '香港 CN2 云服务器', 'type' => 'dcimcloud'],
                                    ],
                                ],
                            ],
                        ],
                    ];
                }

                if ($uri === '/cart/get_product_config') {
                    return [
                        'status' => 200,
                        'data' => ['config_groups' => [['options' => $this->configOptions]]],
                    ];
                }

                return ['status' => 200, 'data' => []];
            }
        };

        $supplier = (new Supplier)->forceFill([
            'id' => 1,
            'interface_type' => 'zjmf_finance_api',
            'api_url' => 'https://zjmf.example.test',
            'api_username' => 'demo',
            'api_key' => 'secret',
        ]);

        $template = (new ZjmfFinanceAdapter($transport, new ZjmfCloudConfigTemplate))
            ->getProductConfigTemplate($supplier, 1001);

        return collect($template['config_options'])->keyBy('id')->all();
    }
}
