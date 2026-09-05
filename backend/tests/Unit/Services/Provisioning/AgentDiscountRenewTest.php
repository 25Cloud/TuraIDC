<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Provisioning;

use App\Models\AgentGroup;
use App\Models\AgentGroupDiscount;
use App\Models\Product;
use App\Models\ProductDiscountGroup;
use App\Models\Service;
use App\Models\User;
use App\Services\Finance\AgentDiscountService;
use App\Services\Finance\CouponService;
use App\Services\Finance\InvoiceService;
use App\Services\Provisioning\ServiceRenewService;
use App\Services\System\NotificationService;
use App\Services\System\OperationLogService;
use App\Services\System\SettingService;
use App\Services\Upstream\ProviderRegistry;
use App\Services\Upstream\ProviderResolver;
use Tests\TestCase;
use ReflectionMethod;

class AgentDiscountRenewTest extends TestCase
{
    public function test续费配置按用户代理组为每个周期应用代理折扣并保留原价快照(): void
    {
        $discountGroup = (new ProductDiscountGroup)->forceFill([
            'id' => 9,
            'min_discount_rate' => '70.00',
            'cost_rate' => '60.00',
            'status' => 1,
        ]);
        $agentGroup = (new AgentGroup)->forceFill(['id' => 7, 'name' => '渠道一', 'status' => 1]);
        $agentGroup->setRelation('discounts', collect([
            (new AgentGroupDiscount)->forceFill([
                'product_discount_group_id' => 9,
                'discount_rate' => '80.00',
            ]),
        ]));
        $product = (new Product)->forceFill([
            'id' => 11,
            'product_discount_group_id' => 9,
            'pricing' => ['monthly' => '100.00', 'annually' => '1000.00'],
        ]);
        $product->setRelation('productDiscountGroup', $discountGroup);
        $user = (new User)->forceFill(['id' => 3, 'agent_group_id' => 7]);
        $user->setRelation('agentGroup', $agentGroup);
        $service = (new Service)->forceFill([
            'id' => 21,
            'billing_cycle' => 'monthly',
            'pricing' => ['monthly' => '100.00', 'annually' => '1000.00'],
        ]);
        $service->setRelation('product', $product);
        $service->setRelation('user', $user);

        $renewService = new ServiceRenewService(
            $this->createMock(InvoiceService::class),
            new ProviderResolver(new ProviderRegistry([])),
            $this->createMock(CouponService::class),
            $this->createMock(OperationLogService::class),
            $this->createMock(SettingService::class),
            $this->createMock(NotificationService::class),
        );

        $method = new ReflectionMethod($renewService, 'buildRenewConfig');
        $method->setAccessible(true);
        $config = $method->invoke($renewService, $user, $service);

        $monthly = collect($config['cycles'])->firstWhere('billing_cycle', 'monthly');
        $annually = collect($config['cycles'])->firstWhere('billing_cycle', 'annually');

        $this->assertSame('100.00', $monthly['original_amount']);
        $this->assertSame('80.00', $monthly['amount']);
        $this->assertSame('1000.00', $annually['original_amount']);
        $this->assertSame('800.00', $annually['amount']);
        $this->assertSame(7, $monthly['agent_group_id']);
    }

    public function test优惠券基于代理价计算并保存代理折扣快照(): void
    {
        $pricing = (new AgentDiscountService)->apply(
            (new Product)->forceFill(['product_discount_group_id' => 9])->setRelation('productDiscountGroup',
                (new ProductDiscountGroup)->forceFill(['id' => 9, 'min_discount_rate' => '70.00', 'cost_rate' => '60.00', 'status' => 1])
            ),
            (new User)->setRelation('agentGroup',
                (new AgentGroup)->forceFill(['id' => 7, 'name' => '渠道一', 'status' => 1])->setRelation('discounts', collect([
                    (new AgentGroupDiscount)->forceFill(['product_discount_group_id' => 9, 'discount_rate' => '80.00']),
                ]))
            ),
            100
        );

        $this->assertSame(80.0, $pricing['discounted_amount']);
        $this->assertSame(20.0, $pricing['discount_amount']);
        $this->assertSame(7, $pricing['agent_group_id']);
    }
}
