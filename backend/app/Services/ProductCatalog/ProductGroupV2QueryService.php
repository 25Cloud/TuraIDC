<?php

declare(strict_types=1);

namespace App\Services\ProductCatalog;

use App\Constants\ProductType;
use App\Exceptions\BusinessException;
use App\Models\FirstProductGroup;
use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ProductGroupV2QueryService
{
    public function __construct(
        private readonly ProductSiteService $siteProducts,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateAdminRootGroups(array $filters): LengthAwarePaginator
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $firstGroupCode = trim((string) ($filters['first_product_group_code'] ?? ''));
        $productType = trim((string) ($filters['product_type'] ?? ''));

        return FirstProductGroup::query()
            ->select('first_product_groups.*')
            ->when($firstGroupCode !== '', fn (Builder $query) => $query->where('code', $firstGroupCode))
            ->when($productType !== '', fn (Builder $query) => $query->where('product_type', ProductType::normalizeBusinessValue($productType)))
            ->when($keyword !== '', function (Builder $query) use ($keyword): void {
                $query->where(function (Builder $keywordQuery) use ($keyword): void {
                    $keywordQuery
                        ->where('name', 'like', "%{$keyword}%")
                        ->orWhere('code', 'like', "%{$keyword}%")
                        ->orWhere('slug', 'like', "%{$keyword}%");
                });
            })
            ->when(array_key_exists('status', $filters) && $filters['status'] !== null, fn (Builder $query) => $query->where('is_visible', (int) $filters['status']))
            ->withCount([
                'secondProductGroups as children_count',
            ])
            ->selectSub($this->productTreeCountSubquery('first_product_groups.id', 1), 'products_count')
            ->selectSub($this->directProductCountSubquery('first_product_groups.id', 1), 'direct_products_count')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($this->perPage($filters, 50, 100), ['*'], 'page', $this->page($filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     roots: Collection<int, FirstProductGroup>,
     *     seconds_by_first: Collection<int, Collection<int, SecondProductGroup>>,
     *     thirds_by_second: Collection<int, Collection<int, ThirdProductGroup>>
     * }
     */
    public function listAdminTreeGroups(array $filters): array
    {
        $firstGroupCode = trim((string) ($filters['first_product_group_code'] ?? ''));
        $productType = trim((string) ($filters['product_type'] ?? ''));
        $hasStatus = array_key_exists('status', $filters) && $filters['status'] !== null;
        $status = $hasStatus ? (int) $filters['status'] : null;

        $roots = FirstProductGroup::query()
            ->select('first_product_groups.*')
            ->when($firstGroupCode !== '', fn (Builder $query) => $query->where('code', $firstGroupCode))
            ->when($productType !== '', fn (Builder $query) => $query->where('product_type', ProductType::normalizeBusinessValue($productType)))
            ->when($hasStatus, fn (Builder $query) => $query->where('is_visible', $status))
            ->withCount([
                'secondProductGroups as children_count',
            ])
            ->selectSub($this->productTreeCountSubquery('first_product_groups.id', 1), 'products_count')
            ->selectSub($this->directProductCountSubquery('first_product_groups.id', 1), 'direct_products_count')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($roots->isEmpty()) {
            return [
                'roots' => $roots,
                'seconds_by_first' => collect(),
                'thirds_by_second' => collect(),
            ];
        }

        $seconds = SecondProductGroup::query()
            ->select('second_product_groups.*')
            ->whereIn('first_product_group_id', $roots->pluck('id'))
            ->when($hasStatus, fn (Builder $query) => $query->where('is_visible', $status))
            ->with(['firstProductGroup'])
            ->withCount([
                'thirdProductGroups as children_count',
            ])
            ->selectSub($this->productTreeCountSubquery('second_product_groups.id', 2), 'products_count')
            ->selectSub($this->directProductCountSubquery('second_product_groups.id', 2), 'direct_products_count')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        if ($seconds->isEmpty()) {
            return [
                'roots' => $roots,
                'seconds_by_first' => $seconds->groupBy('first_product_group_id'),
                'thirds_by_second' => collect(),
            ];
        }

        $thirds = ThirdProductGroup::query()
            ->whereIn('second_product_group_id', $seconds->pluck('id'))
            ->when($hasStatus, fn (Builder $query) => $query->where('is_visible', $status))
            ->with(['secondProductGroup.firstProductGroup'])
            ->withCount([
                'products as products_count',
                'products as direct_products_count',
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();

        return [
            'roots' => $roots,
            'seconds_by_first' => $seconds->groupBy('first_product_group_id'),
            'thirds_by_second' => $thirds->groupBy('second_product_group_id'),
        ];
    }

    public function findAdminGroup(int $groupId, int $level): Model
    {
        $group = match ($level) {
            1 => FirstProductGroup::query()
                ->select('first_product_groups.*')
                ->withCount([
                    'secondProductGroups as children_count',
                ])
                ->selectSub($this->productTreeCountSubquery('first_product_groups.id', 1), 'products_count')
                ->selectSub($this->directProductCountSubquery('first_product_groups.id', 1), 'direct_products_count')
                ->find($groupId),
            2 => SecondProductGroup::query()
                ->select('second_product_groups.*')
                ->with(['firstProductGroup'])
                ->withCount([
                    'thirdProductGroups as children_count',
                ])
                ->selectSub($this->productTreeCountSubquery('second_product_groups.id', 2), 'products_count')
                ->selectSub($this->directProductCountSubquery('second_product_groups.id', 2), 'direct_products_count')
                ->find($groupId),
            3 => ThirdProductGroup::query()
                ->with(['secondProductGroup.firstProductGroup'])
                ->withCount([
                    'products as products_count',
                    'products as direct_products_count',
                ])
                ->find($groupId),
            default => null,
        };

        if (! $group instanceof Model) {
            throw new BusinessException('商品分组不存在', 40400, 404);
        }

        return $group;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateAdminChildGroups(int $parentGroupId, int $parentLevel, array $filters): LengthAwarePaginator
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));

        $query = match ($parentLevel) {
            1 => $this->adminSecondProductGroupQuery($parentGroupId),
            2 => $this->adminThirdProductGroupQuery($parentGroupId),
            default => throw new BusinessException('商品分组层级不正确'),
        };

        return $query
            ->when($keyword !== '', function (Builder $builder) use ($keyword): void {
                $builder->where(function (Builder $keywordQuery) use ($keyword): void {
                    $keywordQuery
                        ->where('name', 'like', "%{$keyword}%")
                        ->orWhere('slug', 'like', "%{$keyword}%");
                });
            })
            ->when(array_key_exists('status', $filters) && $filters['status'] !== null, fn (Builder $builder) => $builder->where('is_visible', (int) $filters['status']))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($this->perPage($filters, 50, 100), ['*'], 'page', $this->page($filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateSiteRootGroups(array $filters): LengthAwarePaginator
    {
        $productType = trim((string) ($filters['first_product_group_code'] ?? $filters['product_type'] ?? ''));

        return $this->visibleSecondProductGroupQuery($productType !== '' ? $productType : null)
            ->with(['firstProductGroup'])
            ->withCount([
                'thirdProductGroups as children_count' => fn (Builder $query) => $query->where('is_visible', 1),
            ])
            ->selectSub($this->directProductCountSubquery('second_product_groups.id', 2), 'direct_products_count')
            ->selectSub(
                Product::query()
                    ->selectRaw('COUNT(*)')
                    ->join('third_product_groups', 'third_product_groups.id', '=', 'products.product_group_id')
                    ->whereColumn('third_product_groups.second_product_group_id', 'second_product_groups.id')
                    ->where('third_product_groups.is_visible', 1)
                    ->where('products.status', 1),
                'child_products_count'
            )
            ->orderBy('second_product_groups.sort_order')
            ->orderBy('second_product_groups.id')
            ->paginate($this->perPage($filters, 20, 50), ['second_product_groups.*'], 'page', $this->page($filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateSiteChildren(int $secondGroupId, array $filters): LengthAwarePaginator
    {
        $secondGroup = $this->resolveVisibleSecondProductGroup($secondGroupId);
        if (! $secondGroup instanceof SecondProductGroup) {
            return $this->emptyPaginator(new ThirdProductGroup, $filters, 20, 50);
        }

        return ThirdProductGroup::query()
            ->where('second_product_group_id', (int) $secondGroup->id)
            ->where('is_visible', 1)
            ->with(['secondProductGroup.firstProductGroup'])
            ->withCount([
                'products as product_count' => fn (Builder $query) => $query->onSale(),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($this->perPage($filters, 20, 50), ['*'], 'page', $this->page($filters));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginateSiteProducts(int $groupId, int $level, array $filters): LengthAwarePaginator
    {
        if ($level === 2 && ! $this->resolveVisibleSecondProductGroup($groupId) instanceof SecondProductGroup) {
            return $this->emptyPaginator(new Product, $filters, 20, 50);
        }

        if ($level === 3 && ! $this->resolveVisibleThirdProductGroup($groupId) instanceof ThirdProductGroup) {
            return $this->emptyPaginator(new Product, $filters, 20, 50);
        }

        $paginator = Product::query()
            ->onSale()
            ->when(
                $level === 2,
                fn (Builder $query) => $query->whereIn('product_group_id', ThirdProductGroup::query()
                    ->select('id')
                    ->where('second_product_group_id', $groupId)),
                fn (Builder $query) => $query->inCurrentProductGroup($groupId),
            )
            ->with([
                'productGroup.secondProductGroup.firstProductGroup',
            ])
            ->select([
                'id',
                'product_group_id',
                'service_type_code',
                'product_type',
                'custom_display_name',
                'pricing',
                'setup_fee',
                'config_options',
                'purchase_requires',
                'stock',
                'status',
                'sort_order',
                'auto_setup',
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate($this->perPage($filters, 20, 50), ['*'], 'page', $this->page($filters));

        return $this->attachCpuModelPayloads($paginator);
    }

    private function visibleSecondProductGroupQuery(?string $productType = null): Builder
    {
        $visibleProductTypes = ProductType::visibleValues();

        if ($visibleProductTypes === []) {
            return SecondProductGroup::query()->whereRaw('1 = 0');
        }

        return SecondProductGroup::query()
            ->select('second_product_groups.*')
            ->join('first_product_groups', 'first_product_groups.id', '=', 'second_product_groups.first_product_group_id')
            ->where('second_product_groups.is_visible', 1)
            ->where('first_product_groups.is_visible', 1)
            ->whereIn('first_product_groups.code', $visibleProductTypes)
            ->when($productType !== null, function (Builder $query) use ($productType): void {
                $businessType = ProductType::normalizeBusinessValue($productType);
                $query->where(function (Builder $typeQuery) use ($productType, $businessType): void {
                    $typeQuery
                        ->where('first_product_groups.code', $productType)
                        ->orWhere('first_product_groups.product_type', $businessType);
                });
            });
    }

    private function adminSecondProductGroupQuery(int $firstGroupId): Builder
    {
        if (! FirstProductGroup::query()->whereKey($firstGroupId)->exists()) {
            throw new BusinessException('商品分组不存在', 40400, 404);
        }

        return SecondProductGroup::query()
            ->select('second_product_groups.*')
            ->where('first_product_group_id', $firstGroupId)
            ->with(['firstProductGroup'])
            ->withCount([
                'thirdProductGroups as children_count',
            ])
            ->selectSub($this->productTreeCountSubquery('second_product_groups.id', 2), 'products_count')
            ->selectSub($this->directProductCountSubquery('second_product_groups.id', 2), 'direct_products_count');
    }

    private function adminThirdProductGroupQuery(int $secondGroupId): Builder
    {
        if (! SecondProductGroup::query()->whereKey($secondGroupId)->exists()) {
            throw new BusinessException('商品分组不存在', 40400, 404);
        }

        return ThirdProductGroup::query()
            ->where('second_product_group_id', $secondGroupId)
            ->with(['secondProductGroup.firstProductGroup'])
            ->withCount([
                'products as products_count',
                'products as direct_products_count',
            ]);
    }

    private function resolveVisibleSecondProductGroup(int $groupId): ?SecondProductGroup
    {
        if ($groupId <= 0) {
            return null;
        }

        $group = $this->visibleSecondProductGroupQuery()
            ->with(['firstProductGroup'])
            ->where('second_product_groups.id', $groupId)
            ->first();

        return $group instanceof SecondProductGroup ? $group : null;
    }

    private function resolveVisibleThirdProductGroup(int $groupId): ?ThirdProductGroup
    {
        if ($groupId <= 0 || ProductType::visibleValues() === []) {
            return null;
        }

        $group = ThirdProductGroup::query()
            ->select('third_product_groups.*')
            ->join('second_product_groups', 'second_product_groups.id', '=', 'third_product_groups.second_product_group_id')
            ->join('first_product_groups', 'first_product_groups.id', '=', 'second_product_groups.first_product_group_id')
            ->where('third_product_groups.id', $groupId)
            ->where('third_product_groups.is_visible', 1)
            ->where('second_product_groups.is_visible', 1)
            ->where('first_product_groups.is_visible', 1)
            ->whereIn('first_product_groups.code', ProductType::visibleValues())
            ->with(['secondProductGroup.firstProductGroup'])
            ->first();

        return $group instanceof ThirdProductGroup ? $group : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function emptyPaginator(Model $model, array $filters, int $default, int $max): LengthAwarePaginator
    {
        return $model->newQuery()
            ->whereRaw('1 = 0')
            ->paginate($this->perPage($filters, $default, $max), ['*'], 'page', $this->page($filters));
    }

    private function attachCpuModelPayloads(LengthAwarePaginator $paginator): LengthAwarePaginator
    {
        foreach ($paginator->items() as $item) {
            if (! $item instanceof Product) {
                continue;
            }

            foreach ($this->siteProducts->cpuModelPayloadForProduct($item) as $key => $value) {
                $item->setAttribute($key, $value);
            }
        }

        return $paginator;
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
     * @param  array<string, mixed>  $filters
     */
    private function page(array $filters): int
    {
        return max((int) ($filters['page'] ?? 1), 1);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function perPage(array $filters, int $default, int $max): int
    {
        $pageSize = (int) ($filters['page_size'] ?? $default);

        return min(max($pageSize, 1), $max);
    }
}
