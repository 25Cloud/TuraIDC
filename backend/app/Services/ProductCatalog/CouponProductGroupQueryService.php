<?php

declare(strict_types=1);

namespace App\Services\ProductCatalog;

use App\Exceptions\BusinessException;
use App\Models\FirstProductGroup;
use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class CouponProductGroupQueryService
{
    public function paginateFirstGroups(array $filters, int $page, int $pageSize): LengthAwarePaginator
    {
        return FirstProductGroup::query()
            ->select(['id', 'code', 'name', 'sort_order', 'is_visible'])
            ->withCount([
                'secondProductGroups as children_count',
            ])
            ->selectSub($this->productTreeCountSubquery('first_product_groups.id', 1), 'products_count')
            ->selectSub($this->directProductCountSubquery('first_product_groups.id', 1), 'direct_products_count')
            ->when($this->keyword($filters) !== '', function (Builder $query) use ($filters): void {
                $keyword = $this->keyword($filters);
                $query->where(function (Builder $builder) use ($keyword): void {
                    $builder
                        ->where('name', 'like', '%'.$keyword.'%')
                        ->orWhere('code', 'like', '%'.$keyword.'%');
                });
            })
            ->when($this->status($filters) !== null, fn (Builder $query) => $query->where('is_visible', $this->status($filters)))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($pageSize, ['*'], 'page', $page);
    }

    public function paginateChildren(
        int $groupId,
        int $level,
        array $filters,
        int $page,
        int $pageSize,
    ): LengthAwarePaginator {
        if ($level === 1) {
            $this->firstGroup($groupId);

            return SecondProductGroup::query()
                ->select(['id', 'first_product_group_id', 'name', 'sort_order', 'is_visible'])
                ->where('first_product_group_id', $groupId)
                ->with(['firstProductGroup:id,code,name'])
                ->withCount([
                    'thirdProductGroups as children_count',
                ])
                ->selectSub($this->productTreeCountSubquery('second_product_groups.id', 2), 'products_count')
                ->selectSub($this->directProductCountSubquery('second_product_groups.id', 2), 'direct_products_count')
                ->when($this->keyword($filters) !== '', fn (Builder $query) => $query->where('name', 'like', '%'.$this->keyword($filters).'%'))
                ->when($this->status($filters) !== null, fn (Builder $query) => $query->where('is_visible', $this->status($filters)))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->paginate($pageSize, ['*'], 'page', $page);
        }

        if ($level === 2) {
            $this->secondGroup($groupId);

            return ThirdProductGroup::query()
                ->select(['id', 'second_product_group_id', 'name', 'sort_order', 'is_visible'])
                ->where('second_product_group_id', $groupId)
                ->with(['secondProductGroup:id,first_product_group_id,name', 'secondProductGroup.firstProductGroup:id,code,name'])
                ->withCount([
                    'products as products_count',
                    'products as direct_products_count',
                ])
                ->when($this->keyword($filters) !== '', fn (Builder $query) => $query->where('name', 'like', '%'.$this->keyword($filters).'%'))
                ->when($this->status($filters) !== null, fn (Builder $query) => $query->where('is_visible', $this->status($filters)))
                ->orderBy('sort_order')
                ->orderBy('id')
                ->paginate($pageSize, ['*'], 'page', $page);
        }

        throw new BusinessException('商品分组层级不正确');
    }

    public function paginateProducts(
        int $groupId,
        int $level,
        array $filters,
        int $page,
        int $pageSize,
    ): LengthAwarePaginator {
        $query = Product::query()
            ->select($this->productColumns())
            ->with([
                'productGroup.secondProductGroup.firstProductGroup',
            ]);

        if ($level === 1) {
            $this->firstGroup($groupId);
            $query->whereIn('product_group_id', ThirdProductGroup::query()
                ->select('third_product_groups.id')
                ->join('second_product_groups', 'second_product_groups.id', '=', 'third_product_groups.second_product_group_id')
                ->where('second_product_groups.first_product_group_id', $groupId));
        } elseif ($level === 2) {
            $this->secondGroup($groupId);
            $query->whereIn('product_group_id', ThirdProductGroup::query()
                ->select('id')
                ->where('second_product_group_id', $groupId));
        } elseif ($level === 3) {
            $this->thirdGroup($groupId);
            $query->inCurrentProductGroup($groupId);
        } else {
            throw new BusinessException('商品分组层级不正确');
        }

        return $query
            ->when($this->keyword($filters) !== '', function (Builder $query) use ($filters): void {
                $keyword = $this->keyword($filters);
                $query->where(function (Builder $builder) use ($keyword): void {
                    $builder
                        ->where('custom_display_name', 'like', '%'.$keyword.'%')
                        ->orWhere('product_type', 'like', '%'.$keyword.'%')
                        ->orWhere('service_type_code', 'like', '%'.$keyword.'%');
                });
            })
            ->when($this->status($filters) !== null, fn (Builder $query) => $query->where('status', $this->status($filters)))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($pageSize, ['*'], 'page', $page);
    }

    /**
     * 批量拉取多个分组的产品，每个分组最多返回 500 条。
     *
     * 结果以“层级:分组ID”为键，避免不同分组表中相同的数值 ID 相互覆盖。
     *
     * @param  array<int, array{id: int, level: int}>  $groups
     * @return array<string, Collection<int, Product>>
     */
    public function batchProducts(array $groups): array
    {
        $result = [];

        foreach ($groups as $group) {
            $groupId = (int) ($group['id'] ?? 0);
            $level = (int) ($group['level'] ?? 0);

            if ($groupId <= 0 || ! in_array($level, [1, 2, 3], true)) {
                continue;
            }

            $query = Product::query()
                ->select($this->productColumns())
                ->with([
                    'productGroup.secondProductGroup.firstProductGroup',
                ]);

            if ($level === 1) {
                $query->whereIn('product_group_id', ThirdProductGroup::query()
                    ->select('third_product_groups.id')
                    ->join('second_product_groups', 'second_product_groups.id', '=', 'third_product_groups.second_product_group_id')
                    ->where('second_product_groups.first_product_group_id', $groupId));
            } elseif ($level === 2) {
                $query->whereIn('product_group_id', ThirdProductGroup::query()
                    ->select('id')
                    ->where('second_product_group_id', $groupId));
            } else {
                $query->inCurrentProductGroup($groupId);
            }

            $products = $query
                ->orderBy('sort_order')
                ->orderBy('id')
                ->limit(500)
                ->get();

            $result[$level.':'.$groupId] = $products;
        }

        return $result;
    }

    /**
     * 一次性返回商品分组整树（三层全部分组 + 全部产品）。
     *
     * 供优惠券/活动等「按分类选择商品」场景一次请求构建选择树，
     * 替代前端递归逐层分页拉取（整树动辄几十个请求）造成的请求风暴。
     * 返回的分组保持 sort_order/id 排序，与分页接口一致。
     *
     * @return array{
     *     groups: \Illuminate\Support\Collection<int, FirstProductGroup|SecondProductGroup|ThirdProductGroup>,
     *     products: \Illuminate\Support\Collection<int, Product>
     * }
     */
    public function fullTree(): array
    {
        $firstGroups = FirstProductGroup::query()
            ->select(['id', 'code', 'name', 'sort_order', 'is_visible'])
            ->withCount(['secondProductGroups as children_count'])
            ->selectSub($this->productTreeCountSubquery('first_product_groups.id', 1), 'products_count')
            ->selectSub($this->directProductCountSubquery('first_product_groups.id', 1), 'direct_products_count')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $firstIds = $firstGroups->pluck('id')->all();

        $secondGroups = SecondProductGroup::query()
            ->select(['id', 'first_product_group_id', 'name', 'sort_order', 'is_visible'])
            ->whereIn('first_product_group_id', $firstIds)
            ->with(['firstProductGroup:id,code,name'])
            ->withCount(['thirdProductGroups as children_count'])
            ->selectSub($this->productTreeCountSubquery('second_product_groups.id', 2), 'products_count')
            ->selectSub($this->directProductCountSubquery('second_product_groups.id', 2), 'direct_products_count')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $secondIds = $secondGroups->pluck('id')->all();

        $thirdGroups = ThirdProductGroup::query()
            ->select(['id', 'second_product_group_id', 'name', 'sort_order', 'is_visible'])
            ->whereIn('second_product_group_id', $secondIds)
            ->with(['secondProductGroup:id,first_product_group_id,name', 'secondProductGroup.firstProductGroup:id,code,name'])
            ->withCount(['products as products_count', 'products as direct_products_count'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        $thirdIds = $thirdGroups->pluck('id')->all();

        $products = Product::query()
            ->select($this->productColumns())
            ->with(['productGroup.secondProductGroup.firstProductGroup'])
            ->whereIn('product_group_id', $thirdIds)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return [
            'groups' => $firstGroups->concat($secondGroups)->concat($thirdGroups),
            'products' => $products,
        ];
    }

    private function firstGroup(int $id): FirstProductGroup
    {
        $group = FirstProductGroup::query()->find($id);

        if (! $group instanceof FirstProductGroup) {
            throw new BusinessException('商品分组不存在', 40400, 404);
        }

        return $group;
    }

    private function secondGroup(int $id): SecondProductGroup
    {
        $group = SecondProductGroup::query()->find($id);

        if (! $group instanceof SecondProductGroup) {
            throw new BusinessException('商品分组不存在', 40400, 404);
        }

        return $group;
    }

    private function thirdGroup(int $id): ThirdProductGroup
    {
        $group = ThirdProductGroup::query()->find($id);

        if (! $group instanceof ThirdProductGroup) {
            throw new BusinessException('商品分组不存在', 40400, 404);
        }

        return $group;
    }

    private function productTreeCountSubquery(string $outerColumn, int $level): Builder
    {
        $query = Product::query()->selectRaw('COUNT(*)');

        return match ($level) {
            1 => $query
                ->join('third_product_groups as group_tree', 'group_tree.id', '=', 'products.product_group_id')
                ->join('second_product_groups as group_tree_parent', 'group_tree_parent.id', '=', 'group_tree.second_product_group_id')
                ->whereColumn('group_tree_parent.first_product_group_id', $outerColumn),
            2 => $query
                ->join('third_product_groups as group_tree', 'group_tree.id', '=', 'products.product_group_id')
                ->whereColumn('group_tree.second_product_group_id', $outerColumn),
            default => $query->whereColumn('products.product_group_id', $outerColumn),
        };
    }

    private function directProductCountSubquery(string $outerColumn, int $level): Builder
    {
        if ($level !== 3) {
            return Product::query()->selectRaw('COUNT(*)')->whereRaw('1 = 0');
        }

        return Product::query()
            ->selectRaw('COUNT(*)')
            ->whereColumn('products.product_group_id', $outerColumn);
    }

    /**
     * @return list<string>
     */
    private function productColumns(): array
    {
        return Product::optionalSelectColumns([
            'id',
            'product_group_id',
            'service_type_code',
            'product_type',
            'custom_display_name',
            'pricing',
            'status',
            'sort_order',
            'purchase_requires',
            'config_options',
            'updated_at',
            'deleted_at',
        ]) ?: ['products.*'];
    }

    private function keyword(array $filters): string
    {
        return trim((string) ($filters['keyword'] ?? ''));
    }

    private function status(array $filters): ?int
    {
        if (! array_key_exists('status', $filters) || $filters['status'] === null || $filters['status'] === '') {
            return null;
        }

        return (int) $filters['status'];
    }
}
