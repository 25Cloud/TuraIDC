<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Finance;

use App\Models\AgentGroup;
use App\Models\AgentGroupDiscount;
use App\Models\Product;
use App\Models\ProductDiscountGroup;
use App\Models\User;
use App\Services\Finance\AgentDiscountService;
use App\Services\Finance\CheckoutSecurityService;
use App\Services\Finance\CheckoutService;
use App\Services\Finance\CouponService;
use App\Services\Site\SiteProductQuoteService;
use Mockery;
use PHPUnit\Framework\TestCase;

class AgentDiscountCheckoutTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test新购报价先计算代理价再计算优惠券(): void
    {
        $product = $this->productWithAgentPricing(9, 80, 60);
        $user = $this->agentUser(7);
        $checkout = Mockery::mock(CheckoutService::class);
        $checkout->shouldReceive('normalizeConfig')->once()->with($product, [])->andReturn([]);
        $checkout->shouldReceive('quote')->once()->with($product, 'monthly', [], 1)->andReturn([
            'total_amount' => '100.00',
            'quantity' => 1,
        ]);
        $agentDiscount = Mockery::mock(AgentDiscountService::class);
        $agentDiscount->shouldReceive('apply')->once()->with($product, $user, 100.0)->andReturn([
            'discount_rate' => 80.0,
            'discount_amount' => 20.0,
            'discounted_amount' => 80.0,
            'cost_amount' => 60.0,
            'agent_group_id' => 7,
            'agent_group_name' => '渠道一',
            'product_discount_group_id' => 9,
            'cost_rate' => 60.0,
        ]);
        $coupon = Mockery::mock(CouponService::class);
        $coupon->shouldReceive('previewOwnedCoupon')->once()->with(3, 7, $product, 'monthly', 80.0, 'new')->andReturn([
            'user_coupon_id' => 3,
            'discount_amount' => '10.00',
        ]);
        $coupon->shouldReceive('availableCouponsForCheckout')->once()->with(7, $product, 'monthly', 80.0, 'new')->andReturn([]);
        $security = Mockery::mock(CheckoutSecurityService::class);
        $security->shouldReceive('issueQuoteToken')->once()->andReturn(['quote_token' => 'quote-token']);

        $payload = (new SiteProductQuoteService($checkout, $security, $coupon, $agentDiscount))->quoteForUser(
            $product,
            ['billing_cycle' => 'monthly', 'config' => [], 'quantity' => 1, 'user_coupon_id' => 3],
            $user
        );

        $this->assertSame('100.00', $payload['original_total_amount']);
        $this->assertSame('80.00', $payload['agent_amount']);
        $this->assertSame('70.00', $payload['total_amount']);
        $this->assertSame('10.00', $payload['discount_amount']);
    }

    public function test普通用户新购报价保持原价(): void
    {
        $product = (new Product)->forceFill(['id' => 9]);
        $checkout = Mockery::mock(CheckoutService::class);
        $checkout->shouldReceive('normalizeConfig')->andReturn([]);
        $checkout->shouldReceive('quote')->andReturn(['total_amount' => '100.00', 'quantity' => 1]);
        $agentDiscount = Mockery::mock(AgentDiscountService::class);
        $agentDiscount->shouldReceive('apply')->once()->andReturn([
            'discount_rate' => 100.0,
            'discount_amount' => 0.0,
            'discounted_amount' => 100.0,
            'cost_amount' => 0.0,
            'agent_group_id' => null,
            'agent_group_name' => null,
            'product_discount_group_id' => null,
            'cost_rate' => 0.0,
        ]);
        $coupon = Mockery::mock(CouponService::class);
        $coupon->shouldReceive('previewOwnedCoupon')->once()->with(null, 7, $product, 'monthly', 100.0, 'new')->andReturn(null);
        $coupon->shouldReceive('availableCouponsForCheckout')->once()->with(7, $product, 'monthly', 100.0, 'new')->andReturn([]);
        $security = Mockery::mock(CheckoutSecurityService::class);
        $security->shouldReceive('issueQuoteToken')->andReturn(['quote_token' => 'quote-token']);

        $payload = (new SiteProductQuoteService($checkout, $security, $coupon, $agentDiscount))->quoteForUser(
            $product,
            ['billing_cycle' => 'monthly', 'config' => [], 'quantity' => 1],
            (new User)->forceFill(['id' => 7])
        );

        $this->assertSame('100.00', $payload['agent_amount']);
        $this->assertSame('100.00', $payload['total_amount']);
    }

    private function productWithAgentPricing(int $discountGroupId, float $discountRate, float $costRate): Product
    {
        $group = (new AgentGroup)->forceFill(['id' => 7, 'name' => '渠道一', 'status' => 1]);
        $discountGroup = (new ProductDiscountGroup)->forceFill([
            'id' => $discountGroupId,
            'min_discount_rate' => '70.00',
            'cost_rate' => (string) $costRate,
            'status' => 1,
        ]);
        $group->setRelation('discounts', collect([
            (new AgentGroupDiscount)->forceFill([
                'product_discount_group_id' => $discountGroupId,
                'discount_rate' => (string) $discountRate,
            ]),
        ]));
        $product = (new Product)->forceFill(['id' => 9, 'product_discount_group_id' => $discountGroupId]);
        $product->setRelation('productDiscountGroup', $discountGroup);

        return $product;
    }

    private function agentUser(int $agentGroupId): User
    {
        $group = (new AgentGroup)->forceFill(['id' => $agentGroupId, 'name' => '渠道一', 'status' => 1]);

        return (new User)->forceFill(['id' => 7, 'agent_group_id' => $agentGroupId])->setRelation('agentGroup', $group);
    }
}
