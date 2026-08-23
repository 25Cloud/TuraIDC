<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Finance;

use App\Models\AgentGroup;
use App\Models\AgentGroupDiscount;
use App\Models\Coupon;
use App\Models\Product;
use App\Models\ProductDiscountGroup;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AgentDiscountSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_discount_tables_exist(): void
    {
        $this->assertTrue(Schema::hasTable('product_discount_groups'));
        $this->assertTrue(Schema::hasTable('agent_groups'));
        $this->assertTrue(Schema::hasTable('agent_group_discounts'));
    }

    public function test_agent_discount_schema_has_expected_defaults_and_unique_matrix(): void
    {
        $productDiscountGroupColumns = collect(Schema::getColumns('product_discount_groups'))
            ->keyBy('name');
        $agentGroupColumns = collect(Schema::getColumns('agent_groups'))
            ->keyBy('name');
        $productColumns = collect(Schema::getColumns('products'))
            ->keyBy('name');
        $userColumns = collect(Schema::getColumns('users'))
            ->keyBy('name');
        $couponColumns = collect(Schema::getColumns('coupons'))
            ->keyBy('name');

        $this->assertSame(100.0, (float) $productDiscountGroupColumns['min_discount_rate']['default']);
        $this->assertSame(0.0, (float) $productDiscountGroupColumns['cost_rate']['default']);
        $this->assertSame(1, (int) $productDiscountGroupColumns['status']['default']);
        $this->assertSame(0, (int) $productDiscountGroupColumns['sort_order']['default']);
        $this->assertSame(1, (int) $agentGroupColumns['status']['default']);
        $this->assertSame(0, (int) $agentGroupColumns['sort_order']['default']);
        $this->assertSame(1, (int) $couponColumns['allow_agent']['default']);
        $this->assertTrue($productColumns['product_discount_group_id']['nullable']);
        $this->assertTrue($userColumns['agent_group_id']['nullable']);

        $productDiscountGroupId = DB::table('product_discount_groups')->insertGetId([
            'name' => '默认商品折扣组',
            'code' => 'default',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $agentGroupId = DB::table('agent_groups')->insertGetId([
            'name' => '默认代理组',
            'code' => 'default',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $payload = [
            'agent_group_id' => $agentGroupId,
            'product_discount_group_id' => $productDiscountGroupId,
            'discount_rate' => 80,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('agent_group_discounts')->insert($payload);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('agent_group_discounts')->insert($payload);
    }

    public function test_models_define_agent_discount_relationships_and_decimal_casts(): void
    {
        $productDiscountGroup = new ProductDiscountGroup;
        $agentGroup = new AgentGroup;
        $agentGroupDiscount = new AgentGroupDiscount;

        $this->assertInstanceOf(Product::class, $productDiscountGroup->products()->getModel());
        $this->assertInstanceOf(AgentGroupDiscount::class, $productDiscountGroup->agentGroupDiscounts()->getModel());
        $this->assertInstanceOf(User::class, $agentGroup->users()->getModel());
        $this->assertInstanceOf(AgentGroupDiscount::class, $agentGroup->discounts()->getModel());
        $this->assertInstanceOf(AgentGroup::class, $agentGroupDiscount->agentGroup()->getModel());
        $this->assertInstanceOf(ProductDiscountGroup::class, $agentGroupDiscount->productDiscountGroup()->getModel());
        $this->assertSame('decimal:2', $productDiscountGroup->getCasts()['min_discount_rate']);
        $this->assertSame('decimal:2', $productDiscountGroup->getCasts()['cost_rate']);
        $this->assertSame('decimal:2', $agentGroupDiscount->getCasts()['discount_rate']);
        $this->assertSame('decimal:2', (new Product)->getCasts()['setup_fee']);
        $this->assertSame('decimal:2', (new User)->getCasts()['total_sales_amount']);
        $this->assertSame('boolean', (new Coupon)->getCasts()['allow_agent']);
    }
}
