<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Finance;

use App\Exceptions\BusinessException;
use App\Http\Requests\Admin\V2\AgentDiscount\SaveAgentGroupDiscountsRequest;
use App\Models\AgentGroup;
use App\Models\AgentGroupDiscount;
use App\Models\Product;
use App\Models\ProductDiscountGroup;
use App\Models\User;
use App\Services\Finance\AgentDiscountService;
use PHPUnit\Framework\TestCase;

class AgentDiscountServiceTest extends TestCase
{
    public function test普通用户返回原价且无代理上下文(): void
    {
        $pricing = (new AgentDiscountService)->resolveForProduct(new User, new Product);

        $this->assertSame([
            'agent_group_id' => null,
            'agent_group_name' => null,
            'product_discount_group_id' => null,
            'discount_rate' => 100.0,
            'discount_amount' => 0.0,
            'original_amount' => 0.0,
            'discounted_amount' => 0.0,
            'cost_rate' => 0.0,
            'cost_amount' => 0.0,
        ], $pricing);
    }

    public function test有效矩阵按代理折扣和成本系数计算金额(): void
    {
        $group = (new AgentGroup)->forceFill(['id' => 7, 'name' => '渠道一', 'status' => 1]);
        $discountGroup = (new ProductDiscountGroup)->forceFill([
            'id' => 9,
            'min_discount_rate' => '70.00',
            'cost_rate' => '60.00',
            'status' => 1,
        ]);
        $group->setRelation('discounts', collect([
            (new AgentGroupDiscount)->forceFill([
                'product_discount_group_id' => 9,
                'discount_rate' => '80.00',
            ]),
        ]));
        $product = (new Product)->forceFill(['product_discount_group_id' => 9]);
        $product->setRelation('productDiscountGroup', $discountGroup);
        $user = (new User)->forceFill(['agent_group_id' => 7]);
        $user->setRelation('agentGroup', $group);

        $service = new AgentDiscountService;
        $pricing = $service->apply($product, $user, 100);

        $this->assertSame(80.0, $pricing['discount_rate']);
        $this->assertSame(20.0, $pricing['discount_amount']);
        $this->assertSame(80.0, $pricing['discounted_amount']);
        $this->assertSame(60.0, $pricing['cost_amount']);
        $this->assertSame(7, $pricing['agent_group_id']);
        $this->assertSame('渠道一', $pricing['agent_group_name']);
    }

    public function test禁用代理组或无匹配矩阵返回原价(): void
    {
        $group = (new AgentGroup)->forceFill(['id' => 7, 'name' => '渠道一', 'status' => 0]);
        $user = (new User)->forceFill(['agent_group_id' => 7]);
        $user->setRelation('agentGroup', $group);

        $pricing = (new AgentDiscountService)->apply(new Product, $user, 100);

        $this->assertSame(100.0, $pricing['discount_rate']);
        $this->assertSame(100.0, $pricing['discounted_amount']);
    }

    public function test折后金额低于成本基准抛出业务异常(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('代理折后金额不能低于成本价');

        (new AgentDiscountService)->assertAboveCost([
            'discounted_amount' => 59.99,
            'cost_amount' => 60.0,
        ]);
    }

    public function test折扣率必须在零到一百且不低于最低折扣率(): void
    {
        $service = new AgentDiscountService;

        $this->expectException(BusinessException::class);
        $service->assertDiscountRate(69.99, 70.0);
    }

    public function test矩阵请求校验折扣率范围和最低折扣率(): void
    {
        $request = new SaveAgentGroupDiscountsRequest;
        $rules = $request->rules();

        $this->assertContains('max:100', $rules['discounts.*.discount_rate']);
        $this->assertContains('min:0', $rules['discounts.*.discount_rate']);
    }
}
