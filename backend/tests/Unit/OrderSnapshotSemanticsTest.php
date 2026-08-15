<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\FirstProductGroup;
use App\Models\Order;
use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use App\Services\ProductCatalog\ProductFullPathResolver;
use Tests\TestCase;

class OrderSnapshotSemanticsTest extends TestCase
{
    public function test_it_reads_order_snapshots_from_orders_main_fields(): void
    {
        $order = new Order;
        $order->setRawAttributes([
            'config_snapshot' => json_encode(['hostname' => 'srv-test-01'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'config_pricing_snapshot' => json_encode(['base_amount' => '99.00'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'coupon_snapshot' => json_encode(['name' => 'Test Coupon'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], true);

        $this->assertSame(1, $order->config_snapshot['_schema_version']);
        $this->assertSame('order.config_snapshot', $order->config_snapshot['_schema_type']);
        $this->assertSame('srv-test-01', $order->config_snapshot['hostname']);
        $this->assertSame(1, $order->config_pricing_snapshot['_schema_version']);
        $this->assertSame('99.00', $order->config_pricing_snapshot['base_amount']);
        $this->assertSame(1, $order->coupon_snapshot['_schema_version']);
        $this->assertSame('Test Coupon', $order->coupon_snapshot['name']);
    }

    public function test_it_writes_versioned_order_snapshots(): void
    {
        $order = new Order;
        $order->config_snapshot = ['hostname' => 'srv-test-02'];
        $order->config_pricing_snapshot = ['base_amount' => '199.00'];
        $order->coupon_snapshot = ['name' => 'Versioned Coupon'];

        $this->assertStringContainsString('"_schema_version":1', $order->getAttributes()['config_snapshot']);
        $this->assertStringContainsString('"order.config_pricing_snapshot"', $order->getAttributes()['config_pricing_snapshot']);
        $this->assertStringContainsString('"order.coupon_snapshot"', $order->getAttributes()['coupon_snapshot']);
    }

    public function test_it_prefers_product_spec_snapshot_for_display_fields(): void
    {
        $product = new Product([
            'purchase_requires' => [
                'upstream_default_config' => [
                    'cpu' => '2',
                    'memory' => '2048',
                ],
            ],
        ]);

        $order = new Order([
            'product_spec_snapshot' => 'Snapshot Product Spec',
        ]);
        $order->setRelation('product', $product);

        $this->assertSame('Snapshot Product Spec', $order->product_spec_snapshot);
        $this->assertSame('Snapshot Product Spec', $order->display_product_name);
    }

    public function test_it_falls_back_to_related_product_display_name_when_spec_snapshot_missing(): void
    {
        $product = new Product([
            'product_type' => 'cloud_server',
            'purchase_requires' => [
                'upstream_default_config' => [
                    'cpu' => '2',
                    'memory' => '2048',
                ],
            ],
        ]);

        $order = new Order;
        $order->setRelation('product', $product);

        $this->assertNull($order->product_spec_snapshot);
        $this->assertSame('2 vCPU 2G', $order->display_product_name);
        $this->assertSame('cloud_server', $order->product_type_snapshot);
    }

    public function test_product_full_path_resolver_builds_category_path_from_product_hierarchy(): void
    {
        $product = new Product([
            'product_type' => 'cloud_server',
            'service_type_code' => 'cloud_server',
            'purchase_requires' => [],
            'config_options' => [],
        ]);
        $firstGroup = tap(new FirstProductGroup, function (FirstProductGroup $group): void {
            $group->setRawAttributes(['id' => 1, 'code' => 'vps', 'name' => '云服务器', 'product_type' => 'cloud_server'], true);
        });
        $secondGroup = tap(new SecondProductGroup, function (SecondProductGroup $group) use ($firstGroup): void {
            $group->setRawAttributes(['id' => 2, 'first_product_group_id' => 1, 'name' => '轻量云'], true);
            $group->setRelation('firstProductGroup', $firstGroup);
        });
        $product->setRelation('productGroup', tap(new ThirdProductGroup, function (ThirdProductGroup $group) use ($secondGroup): void {
            $group->setRawAttributes(['id' => 3, 'second_product_group_id' => 2, 'name' => '香港'], true);
            $group->setRelation('secondProductGroup', $secondGroup);
        }));

        $order = new Order([
            'product_spec_snapshot' => 'gscs-2vcpu-2gib',
            'product_type_snapshot' => 'cloud_server',
        ]);
        $order->setRelation('product', $product);

        $this->assertSame(
            '云服务器/轻量云/香港/gscs-2vcpu-2gib',
            (new ProductFullPathResolver)->pathForOrder($order)
        );
    }

    public function test_product_full_path_resolver_prefers_order_snapshot_path(): void
    {
        $product = new Product([
            'product_type' => 'cloud_server',
            'service_type_code' => 'cloud_server',
            'purchase_requires' => [],
            'config_options' => [],
        ]);
        $firstGroup = tap(new FirstProductGroup, function (FirstProductGroup $group): void {
            $group->setRawAttributes(['id' => 1, 'code' => 'vps', 'name' => '云服务器', 'product_type' => 'cloud_server'], true);
        });
        $secondGroup = tap(new SecondProductGroup, function (SecondProductGroup $group) use ($firstGroup): void {
            $group->setRawAttributes(['id' => 2, 'first_product_group_id' => 1, 'name' => '轻量云'], true);
            $group->setRelation('firstProductGroup', $firstGroup);
        });
        $product->setRelation('productGroup', tap(new ThirdProductGroup, function (ThirdProductGroup $group) use ($secondGroup): void {
            $group->setRawAttributes(['id' => 3, 'second_product_group_id' => 2, 'name' => '香港'], true);
            $group->setRelation('secondProductGroup', $secondGroup);
        }));

        $order = new Order([
            'product_spec_snapshot' => 'current-name',
            'config_snapshot' => [
                'product_full_path' => '历史分类/历史节点/history-spec',
            ],
        ]);
        $order->setRelation('product', $product);

        $this->assertSame(
            '历史分类/历史节点/history-spec',
            (new ProductFullPathResolver)->pathForOrder($order)
        );
    }
}
