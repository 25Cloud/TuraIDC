<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;

class AgentDiscountService
{
    public function resolveForProduct(?User $user, Product $product): array
    {
        $result = $this->emptyPricing();
        $group = $product->relationLoaded('productDiscountGroup')
            ? $product->productDiscountGroup
            : (! $product->getKey() ? null : $product->productDiscountGroup()->first());
        $agentGroup = $user?->relationLoaded('agentGroup')
            ? $user->agentGroup
            : (! $user || ! $user->getKey() ? null : $user->agentGroup()->first());

        if (! $group || ! $agentGroup || (int) $group->status !== 1 || (int) $agentGroup->status !== 1) {
            return $result;
        }

        $discounts = $agentGroup->relationLoaded('discounts')
            ? $agentGroup->discounts
            : $agentGroup->discounts()->get();
        $discount = $discounts->firstWhere('product_discount_group_id', $group->getKey());

        if (! $discount) {
            return $result;
        }

        $rate = (float) $discount->discount_rate;
        $this->assertDiscountRate($rate, (float) $group->min_discount_rate);

        $result['agent_group_id'] = (int) $agentGroup->getKey();
        $result['agent_group_name'] = (string) $agentGroup->name;
        $result['product_discount_group_id'] = (int) $group->getKey();
        $result['discount_rate'] = Money::round($rate);
        $result['cost_rate'] = Money::round($group->cost_rate);

        return $result;
    }

    public function apply(Product $product, ?User $user, float $amount): array
    {
        $pricing = $this->resolveForProduct($user, $product);
        $originalAmount = Money::round($amount);
        $discountedAmount = Money::multiply($originalAmount, Money::divide($pricing['discount_rate'], 100));
        $discountedAmount = Money::round($discountedAmount);
        $pricing['original_amount'] = $originalAmount;
        $pricing['discounted_amount'] = $discountedAmount;
        $pricing['discount_amount'] = Money::subtract($originalAmount, $discountedAmount);
        $pricing['cost_amount'] = Money::multiply($originalAmount, Money::divide($pricing['cost_rate'], 100));

        $this->assertAboveCost($pricing);

        return $pricing;
    }

    public function assertAboveCost(array $pricing): void
    {
        if (Money::round($pricing['discounted_amount'] ?? 0) < Money::round($pricing['cost_amount'] ?? 0)) {
            throw new BusinessException('代理折后金额不能低于成本价');
        }
    }

    public function assertDiscountRate(float $discountRate, float $minDiscountRate = 0): void
    {
        if ($discountRate < 0 || $discountRate > 100) {
            throw new BusinessException('折扣率必须在 0 到 100 之间');
        }

        if ($discountRate < $minDiscountRate) {
            throw new BusinessException('折扣率不能低于商品折扣组最低折扣率');
        }
    }

    private function emptyPricing(): array
    {
        return [
            'agent_group_id' => null,
            'agent_group_name' => null,
            'product_discount_group_id' => null,
            'discount_rate' => 100.0,
            'discount_amount' => 0.0,
            'original_amount' => 0.0,
            'discounted_amount' => 0.0,
            'cost_rate' => 0.0,
            'cost_amount' => 0.0,
        ];
    }
}
