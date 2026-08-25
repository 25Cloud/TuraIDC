<?php

declare(strict_types=1);

namespace App\Http\Resources\Site\V2\Concerns;

use App\Models\Product;
use App\Models\User;
use App\Services\Finance\AgentDiscountService;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

trait ResolvesSiteAgentDiscount
{
    /**
     * 登录的代理用户按折扣矩阵计算商品主周期的代理价；未命中折扣时返回空折扣字段。
     *
     * @return array<string, mixed>
     */
    private function siteAgentDiscount(Product $product, Request $request, string $primaryPrice): array
    {
        $user = $this->siteAgentUser($request);
        $pricing = app(AgentDiscountService::class)->resolveForProduct($user, $product);
        $rate = (float) ($pricing['discount_rate'] ?? 100);

        if (! $user || $rate >= 100) {
            return [
                'has_agent_discount' => false,
                'agent_group_name' => '',
                'agent_discount_rate' => '100.00',
                'agent_discount_percent' => '',
                'agent_primary_price' => '0.00',
                'agent_primary_discount_amount' => '0.00',
            ];
        }

        $original = (float) $primaryPrice;
        $discounted = Money::round(Money::multiply($original, Money::divide($rate, 100)));

        return [
            'has_agent_discount' => true,
            'agent_group_name' => (string) ($pricing['agent_group_name'] ?? ''),
            'agent_discount_rate' => number_format($rate, 2, '.', ''),
            'agent_discount_percent' => $this->siteAgentDiscountPercent($rate),
            'agent_primary_price' => number_format($discounted, 2, '.', ''),
            'agent_primary_discount_amount' => number_format(Money::subtract($original, $discounted), 2, '.', ''),
        ];
    }

    private function siteAgentDiscountPercent(float $rate): string
    {
        $percent = rtrim(rtrim(number_format($rate / 10, 1, '.', ''), '0'), '.');

        return $percent.'折';
    }

    /**
     * 站点公开路由下 $request->user() 为空，需显式用 sanctum guard 解析 Bearer token 用户。
     */
    private function siteAgentUser(Request $request): ?User
    {
        $requestUser = $request->user();

        if ($requestUser instanceof User) {
            return $requestUser;
        }

        $sanctumUser = Auth::guard('sanctum')->user();

        return $sanctumUser instanceof User ? $sanctumUser : null;
    }
}
