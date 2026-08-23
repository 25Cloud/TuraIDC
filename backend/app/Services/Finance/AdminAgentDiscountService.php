<?php

declare(strict_types=1);

namespace App\Services\Finance;

use App\Exceptions\BusinessException;
use App\Models\AgentGroup;
use App\Models\AgentGroupDiscount;
use App\Models\ProductDiscountGroup;
use Illuminate\Support\Facades\DB;

class AdminAgentDiscountService
{
    public function listAgentGroups()
    {
        return AgentGroup::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    public function listProductGroups()
    {
        return ProductDiscountGroup::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    public function saveAgentGroup(?AgentGroup $group, array $data): AgentGroup
    {
        $this->assertUnique(AgentGroup::class, $data, $group);
        return $group ? tap($group)->update($data)->refresh() : AgentGroup::query()->create($data);
    }

    public function saveProductGroup(?ProductDiscountGroup $group, array $data): ProductDiscountGroup
    {
        $this->assertUnique(ProductDiscountGroup::class, $data, $group);
        return $group ? tap($group)->update($data)->refresh() : ProductDiscountGroup::query()->create($data);
    }

    public function deleteAgentGroup(AgentGroup $group): void
    {
        if ($group->users()->exists()) {
            throw new BusinessException('当前代理组下仍有用户，无法删除');
        }
        $group->delete();
    }

    public function deleteProductGroup(ProductDiscountGroup $group): void
    {
        if ($group->products()->exists()) {
            throw new BusinessException('当前商品折扣组仍有商品，无法删除');
        }
        $group->delete();
    }

    public function matrix(): array
    {
        $groups = $this->listAgentGroups();
        $products = $this->listProductGroups();
        $discounts = AgentGroupDiscount::query()->get()->groupBy('product_discount_group_id');

        return $products->map(fn (ProductDiscountGroup $product) => [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'code' => (string) $product->code,
            'min_discount_rate' => number_format((float) $product->min_discount_rate, 2, '.', ''),
            'discounts' => $groups->map(function (AgentGroup $group) use ($product, $discounts) {
                $discount = $discounts->get($product->id, collect())->firstWhere('agent_group_id', $group->id);
                return ['agent_group_id' => (int) $group->id, 'discount_rate' => $discount ? number_format((float) $discount->discount_rate, 2, '.', '') : null];
            })->values()->all(),
        ])->values()->all();
    }

    public function saveMatrix(array $items): array
    {
        DB::transaction(function () use ($items): void {
            foreach ($items as $item) {
                $product = ProductDiscountGroup::query()->findOrFail($item['product_discount_group_id']);
                if ((float) $item['discount_rate'] < (float) $product->min_discount_rate) {
                    throw new BusinessException('折扣率不能低于商品折扣组最低折扣率');
                }
                AgentGroupDiscount::query()->updateOrCreate(
                    ['agent_group_id' => $item['agent_group_id'], 'product_discount_group_id' => $item['product_discount_group_id']],
                    ['discount_rate' => $item['discount_rate']],
                );
            }
        });
        return AgentGroupDiscount::query()->whereIn('agent_group_id', array_column($items, 'agent_group_id'))->whereIn('product_discount_group_id', array_column($items, 'product_discount_group_id'))->get()->all();
    }

    private function assertUnique(string $model, array $data, object|null $current): void
    {
        $query = $model::query()->where('code', $data['code']);
        if ($current) {
            $query->where($current->getKeyName(), '!=', $current->getKey());
        }
        if ($query->exists()) {
            throw new BusinessException('编码已存在');
        }
    }
}
