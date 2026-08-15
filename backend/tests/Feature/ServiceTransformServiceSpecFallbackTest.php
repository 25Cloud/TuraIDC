<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Service;
use App\Services\ClientServiceConsole\ServiceResolverService;
use App\Services\ClientServiceConsole\ServiceTransformService;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ServiceTransformServiceSpecFallbackTest extends TestCase
{
    #[Test]
    public function it_builds_specs_from_host_config_options_when_product_definitions_are_missing(): void
    {
        $serviceTransformService = new ServiceTransformService(new ServiceResolverService);

        $specs = $serviceTransformService->buildSpecs([], [
            'requested_config' => [
                'os' => '112679',
                'cpu' => '2',
                'area' => '14',
                'memory' => '2048',
                'hostname' => 'ser9809005907',
                'network_type' => '112670',
            ],
            'host_config_option' => [
                ['key' => 'area', 'name' => '数据中心', 'type' => 12, 'value' => '成都电信机房'],
                ['key' => 'cpu', 'name' => 'CPU', 'type' => 6, 'value' => '2核'],
                ['key' => 'memory', 'name' => '内存', 'type' => 8, 'value' => '2GB'],
                ['key' => 'system_disk_size', 'name' => '系统盘', 'type' => 19, 'value' => '40G'],
                ['key' => 'network_type', 'name' => '网络类型', 'type' => 1, 'value' => '经典网络'],
                ['key' => 'bw', 'name' => '带宽', 'type' => 11, 'value' => '20'],
                ['key' => 'data_disk_size', 'name' => '数据盘', 'type' => 14, 'value' => '0G'],
                ['key' => 'os', 'name' => '操作系统', 'type' => 5, 'value' => 'Windows-2008R2-Datacenter-cn'],
            ],
        ], []);

        $specMap = [];
        foreach ($specs as $item) {
            $specMap[(string) ($item['key'] ?? '')] = (string) ($item['value'] ?? '');
        }

        $this->assertSame('成都电信机房', $specMap['area'] ?? null);
        $this->assertSame('2核', $specMap['cpu'] ?? null);
        $this->assertSame('2GB', $specMap['memory'] ?? null);
        $this->assertSame('40G', $specMap['system_disk_size'] ?? null);
        $this->assertSame('20Mbps', $specMap['bw'] ?? null);
        $this->assertSame('0G', $specMap['data_disk_size'] ?? null);
        $this->assertSame('Windows-2008R2-Datacenter-cn', $specMap['os'] ?? null);
        $this->assertArrayNotHasKey('network_type', $specMap);
        $this->assertArrayNotHasKey('hostname', $specMap);
    }

    #[Test]
    public function it_formats_detail_name_as_instance_spec_with_cpu_and_memory(): void
    {
        $serviceTransformService = new ServiceTransformService(new ServiceResolverService);
        $product = new Product([
            'id' => 202,
            'name' => '西安云电脑',
            'config_options' => [
                [
                    'field' => 'cpu',
                    'option_type' => 6,
                    'sub' => [
                        ['id' => '2', 'option_name' => '2核'],
                    ],
                ],
                [
                    'field' => 'memory',
                    'option_type' => 8,
                    'sub' => [
                        ['id' => '2048', 'option_name' => '2G'],
                    ],
                ],
            ],
        ]);

        $service = new Service([
            'id' => 202,
            'name' => '西安云电脑 A型',
            'domain' => 'ltser1234567890',
            'status' => 1,
            'billing_cycle' => 'monthly',
            'amount' => 5.00,
            'auto_renew' => 1,
            'provision_data' => [
                'requested_config' => [
                    'hostname' => 'ltser1234567890',
                    'cpu' => '2',
                    'memory' => '2048',
                ],
            ],
        ]);
        $service->setRelation('product', $product);

        $detail = $serviceTransformService->transformDetail($service, [
            'host' => [
                'product_name' => '西安云电脑 A型',
                'domain' => 'ltser1234567890',
                'domainstatus' => 'Active',
            ],
        ]);

        $this->assertSame('西安云电脑 A型', $detail['name'] ?? null);
        $this->assertSame('ltser1234567890', $detail['domain'] ?? null);
    }
}
