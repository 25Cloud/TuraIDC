<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\System\CatalogMigrationService;
use Tests\TestCase;

class CatalogMigrationServiceTest extends TestCase
{
    public function test_it_builds_product_name_slug_pricing_config_and_supplier_binding_from_legacy_row(): void
    {
        $service = new CatalogMigrationService;

        $group = [
            'id' => 3,
            'name' => '香港云服务器 / CN2',
            'product_type' => 'vps',
        ];

        $product = [
            'id' => 9,
            'product_group_id' => 3,
            'product_type' => 'vps',
            'remark' => null,
            'meta_title' => null,
            'pricing' => '{"monthly":"50.00","quarterly":"150.00"}',
            'setup_fee' => '0.00',
            'config_options' => '[{"id":61000,"name":"CPU","field":"cpu","required":0,"default_value":"2","option_type":6},{"id":61001,"name":"内存","field":"memory","required":1,"default_value":"4","option_type":8}]',
            'purchase_requires' => '{"require_phone":true}',
            'stock' => -1,
            'status' => 1,
            'sort_order' => 5,
            'supplier_id' => 1,
            'supplier_product_id' => 9001,
            'provision_module' => 'hosting_panel_api',
            'created_at' => '2026-05-18 10:00:00',
            'updated_at' => '2026-05-18 10:00:00',
            'deleted_at' => null,
        ];

        $built = $service->buildProductPayload($product, $group, []);

        $this->assertSame('香港云服务器 / CN2 VPS', $built['name']);
        $this->assertSame('xiang-gang-yun-fu-wu-qi-cn2-vps', $built['slug']);
        $this->assertSame('{"require_phone":true}', $built['purchase_requires_json']);

        $pricingPlans = $service->buildPricingPlans($product);
        $this->assertCount(2, $pricingPlans);
        $this->assertSame('monthly', $pricingPlans[0]['billing_cycle']);
        $this->assertSame('50.00', $pricingPlans[0]['sale_price']);
        $this->assertSame('quarterly', $pricingPlans[1]['billing_cycle']);
        $this->assertSame('150.00', $pricingPlans[1]['sale_price']);
        $this->assertSame('unlimited', $pricingPlans[0]['stock_mode']);
        $this->assertSame(-1, $pricingPlans[0]['stock_value']);

        $configOptions = $service->buildConfigOptions($product);
        $this->assertCount(2, $configOptions);
        $this->assertSame('cpu', $configOptions[0]['option_key']);
        $this->assertSame('CPU', $configOptions[0]['option_label']);
        $this->assertSame('select', $configOptions[0]['option_type']);
        $this->assertSame(0, $configOptions[0]['is_required']);
        $this->assertSame('4', $configOptions[1]['default_value']);
        $this->assertJson($configOptions[0]['option_schema_json']);

        $supplierBinding = $service->buildSupplierProductPayload($product);
        $this->assertSame(1, $supplierBinding['supplier_id']);
        $this->assertSame(9, $supplierBinding['product_id']);
        $this->assertSame('9001', $supplierBinding['upstream_product_code']);
        $this->assertSame('hosting_panel_api', $supplierBinding['provision_module']);
        $this->assertJson($supplierBinding['mapping_config_json']);
    }

    public function test_it_falls_back_to_remark_name_and_generates_unique_slugs(): void
    {
        $service = new CatalogMigrationService;

        $first = $service->buildProductPayload([
            'id' => 23,
            'product_type' => 'vps',
            'remark' => '西安云电脑',
            'meta_title' => null,
            'purchase_requires' => '[]',
            'status' => 1,
            'sort_order' => 0,
            'created_at' => null,
            'updated_at' => null,
            'deleted_at' => null,
        ], null, []);

        $second = $service->buildProductPayload([
            'id' => 24,
            'product_type' => 'vps',
            'remark' => '西安云电脑',
            'meta_title' => null,
            'purchase_requires' => '[]',
            'status' => 1,
            'sort_order' => 0,
            'created_at' => null,
            'updated_at' => null,
            'deleted_at' => null,
        ], null, [$first['slug'] => true]);

        $this->assertSame('西安云电脑', $first['name']);
        $this->assertSame('xi-an-yun-dian-nao', $first['slug']);
        $this->assertSame('xi-an-yun-dian-nao-24', $second['slug']);
    }

    public function test_it_encrypts_supplier_api_key_and_preserves_empty_values_as_null(): void
    {
        $service = new CatalogMigrationService;

        $payload = $service->buildSupplierPayload([
            'id' => 1,
            'name' => '测试供应商',
            'code' => 'demo-supplier',
            'interface_type' => 'hosting_panel_api',
            'api_url' => 'https://example.com',
            'api_username' => 'demo',
            'api_key' => 'secret',
            'contact_name' => '',
            'contact_phone' => '',
            'contact_email' => '',
            'website' => '',
            'status' => 1,
            'sort_order' => 0,
            'notes' => '',
            'created_at' => null,
            'updated_at' => null,
        ]);

        $this->assertSame('测试供应商', $payload['name']);
        $this->assertSame('demo-supplier', $payload['code']);
        $this->assertNull($payload['contact_name']);
        $this->assertNull($payload['website']);
        $this->assertArrayNotHasKey('api_key', $payload);
        $this->assertIsString($payload['api_key_encrypted']);
        $this->assertNotSame('secret', $payload['api_key_encrypted']);
        $this->assertSame('secret', decrypt($payload['api_key_encrypted']));
    }
}
