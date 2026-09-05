<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Models\ProductDiscountGroup;
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

        if (! $agentGroup || (int) $agentGroup->status !== 1) {
            return $result;
        }

        $discount = null;
        if ($group instanceof ProductDiscountGroup && (int) $group->status === 1) {
            $discounts = $agentGroup->relationLoaded('discounts')
                ? $agentGroup->discounts
                : $agentGroup->discounts()->get();
            $discount = $discounts->firstWhere('product_discount_group_id', $group->getKey());

            if ($discount) {
                $rate = (float) $discount->discount_rate;
                $this->assertDiscountRate($rate, (float) $group->min_discount_rate);

                $result['agent_group_id'] = (int) $agentGroup->getKey();
                $result['agent_group_name'] = (string) $agentGroup->name;
                $result['product_discount_group_id'] = (int) $group->getKey();
                $result['discount_rate'] = Money::round($rate);
                $result['cost_rate'] = Money::round($group->cost_rate);
                $result['discount_source'] = 'matrix';

                return $result;
            }
        }

        // 矩阵未覆盖（商品未挂折扣组、折扣组停用或矩阵无记录）时回退代理组全局默认折扣率：
        // 站点预期代理折扣全局生效，矩阵只承载「按商品组差异化」的例外配置。
        // 未配置默认折扣率（null）则保持仅矩阵生效的既有行为。
        $defaultRate = $agentGroup->default_discount_rate;
        if ($defaultRate === null) {
            return $result;
        }

        $rate = (float) $defaultRate;
        $this->assertDiscountRate($rate);

        $result['agent_group_id'] = (int) $agentGroup->getKey();
        $result['agent_group_name'] = (string) $agentGroup->name;
        $result['product_discount_group_id'] = null;
        $result['discount_rate'] = Money::round($rate);
        $result['cost_rate'] = 0.0;
        $result['discount_source'] = 'default';

        return $result;
    }

    public function apply(Product $product, ?User $user, float $amount): array
    {
        $pricing = $this->resolveForProduct($user, $product);
        $originalAmount = Money::round($amount);
        // 折扣率是百分比：除以 100 后再按原金额折算，除法阶段保留精度（如 87.50 -> 0.8750），
        // 仅在最终金额处统一四舍五入到分，避免把比率提前量化成整百分点导致多收/少收。
        $discountRate = (float) $pricing['discount_rate'] / 100;
        $costRate = (float) $pricing['cost_rate'] / 100;
        $discountedAmount = Money::round($originalAmount * $discountRate);
        $pricing['original_amount'] = $originalAmount;
        $pricing['discounted_amount'] = $discountedAmount;
        $pricing['discount_amount'] = Money::subtract($originalAmount, $discountedAmount);
        $pricing['cost_amount'] = Money::round($originalAmount * $costRate);

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
            'discount_source' => null,
        ];
    }
}
