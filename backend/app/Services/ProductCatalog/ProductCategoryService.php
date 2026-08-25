<?php

declare(strict_types=1);

namespace App\Services\ProductCatalog;

use App\Constants\ProductType;
use App\Exceptions\BusinessException;
use App\Models\FirstProductGroup;
use App\Models\Product;
use App\Models\SecondProductGroup;
use App\Models\Service;
use App\Models\ThirdProductGroup;
use App\Services\ProductCatalog\Concerns\HandlesProductCatalogHelpers;
use App\Support\CacheKey;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ProductCategoryService
{
    use HandlesProductCatalogHelpers;

    private const ADMIN_SUMMARY_CACHE_TTL_SECONDS = 60;

    private readonly ProductGroupHierarchyService $hierarchyService;

    public function __construct(?ProductGroupHierarchyService $hierarchyService = null)
    {
        $this->hierarchyService = $hierarchyService ?? app(ProductGroupHierarchyService::class);
    }

    public function adminSummary(): array
    {
        return Cache::remember(
            CacheKey::adminCatalogSummary(),
            now()->addSeconds(self::ADMIN_SUMMARY_CACHE_TTL_SECONDS),
            fn () => [
                'first_product_groups_total' => FirstProductGroup::query()->count(),
                'second_product_groups_total' => SecondProductGroup::query()->count(),
                'third_product_groups_total' => ThirdProductGroup::query()->count(),
                'products_total' => Product::query()->count(),
                'products_deleted' => Product::onlyTrashed()->count(),
                'products_active' => Product::query()->where('status', 1)->count(),
                'products_low_stock' => Product::query()->where('stock', '>=', 0)->where('stock', '<=', 5)->count(),
            ]
        );
    }

    public function adminCategoryTree(?string $serviceTypeCode = null): array
    {
        return FirstProductGroup::query()
            ->when(
                trim((string) $serviceTypeCode) !== '',
                fn (Builder $query) => $query->where('code', trim((string) $serviceTypeCode))
            )
            ->withCount('secondProductGroups')
            ->selectSub($this->physicalProductCountSubquery(1), 'products_count')
            ->selectSub($this->physicalProductCountSubquery(1, true), 'products_with_trashed_count')
            ->with([
                'secondProductGroups' => fn ($query) => $query
                    ->withCount('thirdProductGroups')
                    ->selectSub($this->physicalProductCountSubquery(2), 'products_count')
                    ->selectSub($this->physicalProductCountSubquery(2, true), 'products_with_trashed_count')
                    ->with([
                        'thirdProductGroups' => fn ($thirdQuery) => $thirdQuery
                            ->withCount([
                                'products',
                                'products as products_with_trashed_count' => fn ($productsQuery) => $productsQuery->withTrashed(),
                            ])
                            ->orderBy('sort_order')
                            ->orderBy('id'),
                    ])
                    ->orderBy('sort_order')
                    ->orderBy('id'),
            ])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (FirstProductGroup $group): array => $this->firstGroupPayload($group))
            ->values()
            ->all();
    }

    public function categoryOptions(?string $serviceTypeCode = null): array
    {
        return collect($this->adminCategoryTree($serviceTypeCode))
            ->flatMap(fn (array $firstGroup): array => $this->flattenGroupOptions($firstGroup))
            ->values()
            ->all();
    }

    public function createCategory(array $data): array
    {
        $level = $this->resolveLevel($data);

        return DB::transaction(function () use ($data, $level): array {
            $name = $this->resolveName($data);
            $payload = $this->sharedGroupPayload($data, $name);

            if ($level === 1) {
                $code = trim((string) ($data['first_product_group_code'] ?? $data['code'] ?? ''));
                throw_if($code === '', new BusinessException('请输入一级分类编码'));

                $group = FirstProductGroup::query()->create([
                    ...$payload,
                    'code' => $code,
                    'slug' => $this->generateUniqueSlug(FirstProductGroup::query(), $data['slug'] ?? $name),
                    'is_system' => (int) (($data['is_system'] ?? 0) ? 1 : 0),
                    'product_type' => ProductType::normalizeBusinessValue($data['product_type'] ?? $code),
                ]);

                $this->forgetSiteCatalogCache();

                return $this->firstGroupPayload($this->loadFirstGroup((int) $group->id));
            }

            if ($level === 2) {
                $firstGroup = $this->resolveFirstGroupForCategory($data);
                $group = SecondProductGroup::query()->create([
                    ...$payload,
                    'first_product_group_id' => (int) $firstGroup->id,
                    'slug' => $this->generateUniqueSlug(
                        SecondProductGroup::query()->where('first_product_group_id', (int) $firstGroup->id),
                        $data['slug'] ?? $name
                    ),
                ]);

                $this->forgetSiteCatalogCache();

                return $this->secondGroupPayload($this->loadSecondGroup((int) $group->id), $firstGroup);
            }

            $secondGroup = $this->findSecondGroup((int) ($data['second_product_group_id'] ?? 0));
            $group = ThirdProductGroup::query()->create([
                ...$payload,
                'second_product_group_id' => (int) $secondGroup->id,
                'slug' => $this->generateUniqueSlug(
                    ThirdProductGroup::query()->where('second_product_group_id', (int) $secondGroup->id),
                    $data['slug'] ?? $name
                ),
            ]);

            $this->forgetSiteCatalogCache();

            return $this->thirdGroupPayload($this->loadThirdGroup((int) $group->id), $secondGroup);
        });
    }

    public function updateCategory(int $groupId, array $data): array
    {
        $level = $this->resolveLevel($data);

        return DB::transaction(function () use ($groupId, $data, $level): array {
            $payload = $this->sharedGroupPayload($data, $this->resolveName($data, false));
            $shouldCascadeVisibility = array_key_exists('is_visible', $data);

            if ($level === 1) {
                $group = $this->findFirstGroup($groupId);
                if (array_key_exists('first_product_group_code', $data) || array_key_exists('code', $data)) {
                    $code = trim((string) ($data['first_product_group_code'] ?? $data['code'] ?? ''));
                    throw_if($code === '', new BusinessException('一级分类编码不能为空'));
                    $payload['code'] = $code;
                }
                if (array_key_exists('product_type', $data)) {
                    $payload['product_type'] = ProductType::normalizeBusinessValue($data['product_type']);
                }
                $group->update($payload);
                if ($shouldCascadeVisibility) {
                    $this->cascadeVisibility(1, (int) $group->id, (int) $group->is_visible);
                }
                $this->forgetSiteCatalogCache();

                return $this->firstGroupPayload($this->loadFirstGroup((int) $group->id));
            }

            if ($level === 2) {
                $group = $this->findSecondGroup($groupId);
                if (isset($data['first_product_group_id'])) {
                    $firstGroup = $this->findFirstGroup((int) $data['first_product_group_id']);
                    $payload['first_product_group_id'] = (int) $firstGroup->id;
                }
                $group->update($payload);
                if ($shouldCascadeVisibility) {
                    $this->cascadeVisibility(2, (int) $group->id, (int) $group->is_visible);
                }
                $this->forgetSiteCatalogCache();

                return $this->secondGroupPayload($this->loadSecondGroup((int) $group->id));
            }

            $group = $this->findThirdGroup($groupId);
            if (isset($data['second_product_group_id'])) {
                $secondGroup = $this->findSecondGroup((int) $data['second_product_group_id']);
                $payload['second_product_group_id'] = (int) $secondGroup->id;
            }
            $group->update($payload);
            if ($shouldCascadeVisibility) {
                $this->cascadeVisibility(3, (int) $group->id, (int) $group->is_visible);
            }
            $this->forgetSiteCatalogCache();

            return $this->thirdGroupPayload($this->loadThirdGroup((int) $group->id));
        });
    }

    public function deleteCategory(int $groupId, int $level): void
    {
        DB::transaction(function () use ($groupId, $level): void {
            if ($level === 1) {
                $group = $this->findFirstGroup($groupId);
                throw_if($group->secondProductGroups()->exists(), new BusinessException('请先删除下级分类'));
                $this->assertNoActiveProductsInGroup($groupId, fn (Builder $query) => $query->inFirstProductGroup($groupId));
                $this->forceDeleteSoftDeletedProductsInGroup($groupId, fn (Builder $query) => $query->inFirstProductGroup($groupId));
                $group->delete();
            } elseif ($level === 2) {
                $group = $this->findSecondGroup($groupId);
                throw_if($group->thirdProductGroups()->exists(), new BusinessException('请先删除下级分类'));
                $this->assertNoActiveProductsInGroup($groupId, fn (Builder $query) => $query->inSecondProductGroup($groupId));
                $this->forceDeleteSoftDeletedProductsInGroup($groupId, fn (Builder $query) => $query->inSecondProductGroup($groupId));
                $group->delete();
            } else {
                $group = $this->findThirdGroup($groupId);
                $this->assertNoActiveProductsInGroup($groupId, fn (Builder $query) => $query->where('product_group_id', $groupId));
                $this->forceDeleteSoftDeletedProductsInGroup($groupId, fn (Builder $query) => $query->where('product_group_id', $groupId));
                $group->delete();
            }
        });

        $this->forgetSiteCatalogCache();
    }

    /**
     * 强制删除分类：级联删除其下全部商品（含软删除），并同步软删除相关服务实例，
     * 自下而上物理删除三级/二级/一级分组。
     *
     * @return array{deleted_groups: int, deleted_products: int, deleted_services: int}
     */
    public function forceDeleteCategory(int $groupId, int $level): array
    {
        return DB::transaction(function () use ($groupId, $level): array {
            [$productIds, $thirdGroupIds, $secondGroupIds, $firstGroupId] = $this->collectCascadeDeleteScope($groupId, $level);

            $productIds = collect($productIds)
                ->map(fn ($id): int => (int) $id)
                ->filter(fn (int $id): bool => $id > 0)
                ->unique()
                ->values();

            $deletedServices = 0;
            if ($productIds->isNotEmpty()) {
                $deletedServices = $this->forceDeleteServicesByProducts($productIds);

                if (Schema::hasTable('product_upstream_bindings')) {
                    DB::table('product_upstream_bindings')
                        ->whereIn('product_id', $productIds->all())
                        ->delete();
                }

                Product::query()
                    ->withoutGlobalScopes()
                    ->whereIn('products.id', $productIds->all())
                    ->forceDelete();
            }

            $deletedGroups = $this->deleteGroupHierarchy($level, $firstGroupId, $secondGroupIds, $thirdGroupIds);

            return [
                'deleted_groups' => $deletedGroups,
                'deleted_products' => $productIds->count(),
                'deleted_services' => $deletedServices,
            ];
        });
    }

    /**
     * 分组级批量更新：修改该分组（含下级分组）下所有商品的折扣分组 / 控制台类型。
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function batchUpdateGroupProducts(int $groupId, int $level, array $data): array
    {
        [, , $thirdGroupIds] = $this->collectCascadeDeleteScope($groupId, $level);

        $query = Product::query();
        if ($thirdGroupIds !== []) {
            $query->whereIn('product_group_id', $thirdGroupIds);
        } else {
            $query->whereRaw('1 = 0');
        }

        $productIds = (clone $query)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values();

        $update = $this->buildProductBatchUpdatePayload($data);
        if ($update === [] || $productIds->isEmpty()) {
            return [
                'requested_count' => $productIds->count(),
                'updated_count' => 0,
                'fields' => array_keys($update),
            ];
        }

        $updatedCount = DB::transaction(fn (): int => (int) $query->update([...$update, 'updated_at' => now()]));

        $this->forgetSiteCatalogCache();

        return [
            'requested_count' => $productIds->count(),
            'updated_count' => $updatedCount,
            'fields' => array_keys($update),
        ];
    }

    /**
     * 收集级联删除范围：商品 ID（含软删除）、三级分组 ID、二级分组 ID、一级分组 ID。
     *
     * @return array{0: list<int>, 1: list<int>, 2: list<int>, 3: int}
     */
    private function collectCascadeDeleteScope(int $groupId, int $level): array
    {
        if ($level === 1) {
            $firstGroup = $this->findFirstGroup($groupId);
            $secondGroupIds = $this->secondGroupIdsByFirst((int) $firstGroup->id);
            $thirdGroupIds = $secondGroupIds === []
                ? []
                : ThirdProductGroup::query()
                    ->whereIn('second_product_group_id', $secondGroupIds)
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->values()
                    ->all();

            return [
                $this->productIdsUnderThirdGroups($thirdGroupIds),
                $thirdGroupIds,
                $secondGroupIds,
                (int) $firstGroup->id,
            ];
        }

        if ($level === 2) {
            $secondGroup = $this->findSecondGroup($groupId);
            $thirdGroupIds = $this->thirdGroupIdsBySecond((int) $secondGroup->id);

            return [
                $this->productIdsUnderThirdGroups($thirdGroupIds),
                $thirdGroupIds,
                [(int) $secondGroup->id],
                (int) ($secondGroup->first_product_group_id ?? 0),
            ];
        }

        $thirdGroup = $this->findThirdGroup($groupId);
        $productIds = Product::query()
            ->withTrashed()
            ->where('product_group_id', (int) $thirdGroup->id)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();

        return [
            $productIds,
            [(int) $thirdGroup->id],
            [],
            (int) ($thirdGroup->second_product_group_id ?? 0),
        ];
    }

    /**
     * @param  list<int>  $thirdGroupIds
     * @return list<int>
     */
    private function productIdsUnderThirdGroups(array $thirdGroupIds): array
    {
        if ($thirdGroupIds === []) {
            return [];
        }

        return Product::query()
            ->withTrashed()
            ->whereIn('product_group_id', $thirdGroupIds)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    /**
     * 软删除商品下的服务实例：解绑订单/工单引用，清理上游绑定后软删服务记录，
     * 保留账单等财务记录。
     */
    private function forceDeleteServicesByProducts(Collection $productIds): int
    {
        $serviceIds = Service::query()
            ->whereIn('product_id', $productIds->all())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($serviceIds->isEmpty()) {
            return 0;
        }

        if (Schema::hasTable('orders')) {
            DB::table('orders')->whereIn('service_id', $serviceIds->all())->update(['service_id' => null]);
        }

        if (Schema::hasTable('tickets')) {
            DB::table('tickets')->whereIn('service_id', $serviceIds->all())->update(['service_id' => null]);
        }

        if (Schema::hasTable('service_upstream_bindings')) {
            DB::table('service_upstream_bindings')->whereIn('service_id', $serviceIds->all())->delete();
        }

        Service::query()->whereIn('id', $serviceIds->all())->delete();

        return $serviceIds->count();
    }

    /**
     * @param  list<int>  $secondGroupIds
     * @param  list<int>  $thirdGroupIds
     */
    private function deleteGroupHierarchy(int $level, int $firstGroupId, array $secondGroupIds, array $thirdGroupIds): int
    {
        $deleted = 0;
        if ($thirdGroupIds !== []) {
            $deleted += ThirdProductGroup::query()->whereIn('id', $thirdGroupIds)->delete();
        }
        if ($secondGroupIds !== []) {
            $deleted += SecondProductGroup::query()->whereIn('id', $secondGroupIds)->delete();
        }
        if ($level === 1 && $firstGroupId > 0) {
            $deleted += FirstProductGroup::query()->whereKey($firstGroupId)->delete();
        }

        return $deleted;
    }

    /**
     * 校验分组下不存在未删除商品（软删除商品由 forceDeleteSoftDeletedProductsInGroup 清理）。
     *
     * @param  \Closure(Builder): Builder  $scopeQuery
     */
    private function assertNoActiveProductsInGroup(int $groupId, \Closure $scopeQuery): void
    {
        $hasActive = Product::query()
            ->withoutGlobalScopes()
            ->whereNull('products.deleted_at')
            ->tap($scopeQuery)
            ->exists();

        throw_if($hasActive, new BusinessException('请先迁移或删除该分类下的商品'));
    }

    /**
     * 物理删除分组下已软删除的商品，避免其外键引用阻塞分组删除。
     * 软删除商品的上游绑定（product_upstream_bindings）一并清除。
     *
     * @param  \Closure(Builder): Builder  $scopeQuery
     */
    private function forceDeleteSoftDeletedProductsInGroup(int $groupId, \Closure $scopeQuery): void
    {
        $trashedProductIds = Product::query()
            ->withoutGlobalScopes()
            ->whereNotNull('products.deleted_at')
            ->tap($scopeQuery)
            ->pluck('products.id')
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($trashedProductIds === []) {
            return;
        }

        if (Schema::hasTable('product_upstream_bindings')) {
            DB::table('product_upstream_bindings')
                ->whereIn('product_id', $trashedProductIds)
                ->delete();
        }

        Product::query()
            ->withoutGlobalScopes()
            ->whereIn('products.id', $trashedProductIds)
            ->forceDelete();
    }

    public function reorderAdminCategories(int $level, ?int $parentGroupId, array $groupIds): array
    {
        $orderedIds = collect($groupIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        throw_if($orderedIds->count() < 2, new BusinessException('至少需要两个分类才能拖动排序'));

        $scopeQuery = match ($level) {
            1 => FirstProductGroup::query(),
            2 => SecondProductGroup::query()->where('first_product_group_id', (int) $parentGroupId),
            3 => ThirdProductGroup::query()->where('second_product_group_id', (int) $parentGroupId),
            default => throw new BusinessException('分类层级不正确'),
        };

        if ($level > 1) {
            throw_if((int) $parentGroupId <= 0, new BusinessException('请选择上级分类'));
        }

        $currentIds = $scopeQuery
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values();

        if (
            $currentIds->count() !== $orderedIds->count()
            || $currentIds->sort()->values()->all() !== $orderedIds->sort()->values()->all()
        ) {
            throw new BusinessException('分类列表已发生变化，请刷新后重新拖动排序');
        }

        $sortMap = [];
        foreach ($orderedIds as $index => $id) {
            $sortMap[(int) $id] = $index + 1;
        }

        DB::transaction(fn () => $this->resequenceGroupIds($level, $sortMap));
        $this->forgetSiteCatalogCache();

        return [
            'updated_count' => count($sortMap),
            'level' => $level,
            'parent_id' => $parentGroupId,
        ];
    }

    public function moveAdminCategory(
        int $level,
        int $groupId,
        ?int $targetParentId,
        ?int $referenceGroupId,
        string $position = 'append',
    ): array {
        throw_if(! in_array($position, ['before', 'after', 'append'], true), new BusinessException('拖动位置参数不正确'));
        throw_if(! in_array($level, [2, 3], true), new BusinessException('只支持移动二级或三级分类'));

        return DB::transaction(function () use ($level, $groupId, $targetParentId, $referenceGroupId, $position): array {
            if ($level === 2) {
                $group = $this->findSecondGroup($groupId);
                $targetFirstGroup = $this->findFirstGroup((int) $targetParentId);
                $sourceParentId = (int) $group->first_product_group_id;
                $targetParentId = (int) $targetFirstGroup->id;
                $sourceIds = $this->secondGroupIdsByFirst($sourceParentId);
                $targetIds = $sourceParentId === $targetParentId ? $sourceIds : $this->secondGroupIdsByFirst($targetParentId);
                $reorderedTargetIds = $this->buildReorderedIds($targetIds, (int) $group->id, $referenceGroupId, $position, '分类');
                $remainingSourceIds = $sourceParentId === $targetParentId
                    ? []
                    : array_values(array_filter($sourceIds, fn (int $id): bool => $id !== (int) $group->id));

                if ($sourceParentId !== $targetParentId) {
                    $group->update(['first_product_group_id' => $targetParentId]);
                }

                $this->resequenceGroupIds(2, $this->sortMap($reorderedTargetIds));
                $this->resequenceGroupIds(2, $this->sortMap($remainingSourceIds));
            } else {
                $group = $this->findThirdGroup($groupId);
                $targetSecondGroup = $this->findSecondGroup((int) $targetParentId);
                $sourceParentId = (int) $group->second_product_group_id;
                $targetParentId = (int) $targetSecondGroup->id;
                $sourceIds = $this->thirdGroupIdsBySecond($sourceParentId);
                $targetIds = $sourceParentId === $targetParentId ? $sourceIds : $this->thirdGroupIdsBySecond($targetParentId);
                $reorderedTargetIds = $this->buildReorderedIds($targetIds, (int) $group->id, $referenceGroupId, $position, '分类');
                $remainingSourceIds = $sourceParentId === $targetParentId
                    ? []
                    : array_values(array_filter($sourceIds, fn (int $id): bool => $id !== (int) $group->id));

                if ($sourceParentId !== $targetParentId) {
                    $group->update(['second_product_group_id' => $targetParentId]);
                }

                $this->resequenceGroupIds(3, $this->sortMap($reorderedTargetIds));
                $this->resequenceGroupIds(3, $this->sortMap($remainingSourceIds));
            }

            $this->forgetSiteCatalogCache();

            return [
                'effective_product_group_id' => $groupId,
                'effective_product_group_level' => $level,
                'target_parent_id' => $targetParentId,
                'position' => $position,
            ];
        });
    }

    private function firstGroupPayload(FirstProductGroup $group): array
    {
        $children = $group->relationLoaded('secondProductGroups') ? $group->secondProductGroups : collect();
        $productType = ProductType::businessValueForFirstGroup($group, $group->code);

        return [
            'id' => (int) $group->id,
            'first_product_group_id' => (int) $group->id,
            'first_product_group_code' => (string) $group->code,
            'first_product_group_name' => (string) $group->name,
            'service_type_code' => $productType,
            'service_type_label' => ProductType::businessLabelOf($productType),
            'product_type' => $productType,
            'product_type_label' => ProductType::businessLabelOf($productType),
            'effective_product_group_id' => (int) $group->id,
            'effective_product_group_level' => 1,
            'level' => 1,
            'name' => (string) $group->name,
            'description' => (string) ($group->description ?? ''),
            'slug' => (string) $group->slug,
            'sort_order' => (int) $group->sort_order,
            'is_visible' => (int) $group->is_visible,
            'status' => (int) $group->is_visible,
            'products_count' => (int) ($group->products_count ?? 0),
            'products_with_trashed_count' => (int) ($group->products_with_trashed_count ?? 0),
            'children_count' => (int) ($group->second_product_groups_count ?? $children->count()),
            'children' => $children
                ->map(fn (SecondProductGroup $child): array => $this->secondGroupPayload($child, $group))
                ->values()
                ->all(),
            'created_at' => $group->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $group->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function secondGroupPayload(SecondProductGroup $group, ?FirstProductGroup $firstGroup = null): array
    {
        $firstGroup = $firstGroup ?? $group->firstProductGroup;
        $children = $group->relationLoaded('thirdProductGroups') ? $group->thirdProductGroups : collect();
        $productType = ProductType::businessValueForFirstGroup($firstGroup, $firstGroup?->code);

        return [
            'id' => (int) $group->id,
            'first_product_group_id' => (int) $group->first_product_group_id,
            'first_product_group_code' => (string) ($firstGroup?->code ?? ''),
            'first_product_group_name' => (string) ($firstGroup?->name ?? ''),
            'second_product_group_id' => (int) $group->id,
            'second_product_group_name' => (string) $group->name,
            'second_product_group_parent_id' => (int) $group->first_product_group_id,
            'effective_product_group_id' => (int) $group->id,
            'effective_product_group_level' => 2,
            'level' => 2,
            'service_type_code' => $productType,
            'service_type_label' => ProductType::businessLabelOf($productType),
            'product_type' => $productType,
            'product_type_label' => ProductType::businessLabelOf($productType),
            'name' => (string) $group->name,
            'description' => (string) ($group->description ?? ''),
            'slug' => (string) $group->slug,
            'sort_order' => (int) $group->sort_order,
            'is_visible' => (int) $group->is_visible,
            'status' => (int) $group->is_visible,
            'parent_id' => (int) $group->first_product_group_id,
            'parent_name' => (string) ($firstGroup?->name ?? ''),
            'products_count' => (int) ($group->products_count ?? 0),
            'products_with_trashed_count' => (int) ($group->products_with_trashed_count ?? 0),
            'children_count' => (int) ($group->third_product_groups_count ?? $children->count()),
            'children' => $children
                ->map(fn (ThirdProductGroup $child): array => $this->thirdGroupPayload($child, $group))
                ->values()
                ->all(),
            'created_at' => $group->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $group->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function thirdGroupPayload(ThirdProductGroup $group, ?SecondProductGroup $secondGroup = null): array
    {
        $secondGroup = $secondGroup ?? $group->secondProductGroup;
        $firstGroup = $secondGroup?->firstProductGroup;
        $productType = ProductType::businessValueForFirstGroup($firstGroup, $firstGroup?->code);

        return [
            'id' => (int) $group->id,
            'first_product_group_id' => (int) ($secondGroup?->first_product_group_id ?? 0),
            'first_product_group_code' => (string) ($firstGroup?->code ?? ''),
            'first_product_group_name' => (string) ($firstGroup?->name ?? ''),
            'second_product_group_id' => (int) $group->second_product_group_id,
            'second_product_group_name' => (string) ($secondGroup?->name ?? ''),
            'third_product_group_id' => (int) $group->id,
            'third_product_group_name' => (string) $group->name,
            'effective_product_group_id' => (int) $group->id,
            'effective_product_group_level' => 3,
            'level' => 3,
            'service_type_code' => $productType,
            'service_type_label' => ProductType::businessLabelOf($productType),
            'product_type' => $productType,
            'product_type_label' => ProductType::businessLabelOf($productType),
            'name' => (string) $group->name,
            'description' => (string) ($group->description ?? ''),
            'slug' => (string) $group->slug,
            'sort_order' => (int) $group->sort_order,
            'is_visible' => (int) $group->is_visible,
            'status' => (int) $group->is_visible,
            'parent_id' => (int) $group->second_product_group_id,
            'parent_name' => (string) ($secondGroup?->name ?? ''),
            'products_count' => (int) ($group->products_count ?? 0),
            'products_with_trashed_count' => (int) ($group->products_with_trashed_count ?? 0),
            'children_count' => 0,
            'children' => [],
            'created_at' => $group->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $group->updated_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function flattenGroupOptions(array $group): array
    {
        $children = collect($group['children'] ?? [])
            ->flatMap(fn (array $child): array => $this->flattenGroupOptions($child))
            ->all();

        $labelPrefix = match ((int) ($group['level'] ?? 0)) {
            1 => '',
            2 => trim((string) ($group['first_product_group_name'] ?? '')),
            3 => trim((string) ($group['first_product_group_name'] ?? '')).' / '.trim((string) ($group['second_product_group_name'] ?? '')),
            default => '',
        };
        $name = trim((string) ($group['name'] ?? ''));
        $group['label'] = trim($labelPrefix) !== '' ? trim($labelPrefix.' / '.$name, ' /') : $name;

        return [$group, ...$children];
    }

    private function sharedGroupPayload(array $data, ?string $name): array
    {
        $payload = [];

        if ($name !== null) {
            $payload['name'] = $name;
        }

        if (array_key_exists('description', $data) || array_key_exists('slogan', $data)) {
            $payload['description'] = $this->normalizeNullableString($data['description'] ?? $data['slogan'] ?? null);
        }

        if (array_key_exists('banner_image', $data)) {
            $payload['banner_image'] = $this->normalizeNullableString($data['banner_image']);
        }

        if (array_key_exists('sort_order', $data)) {
            $payload['sort_order'] = max((int) $data['sort_order'], 0);
        }

        if (array_key_exists('is_visible', $data)) {
            $payload['is_visible'] = (int) (($data['is_visible'] ?? 1) ? 1 : 0);
        }

        return $payload;
    }

    private function resolveName(array $data, bool $required = true): ?string
    {
        if (! array_key_exists('name', $data)) {
            throw_if($required, new BusinessException('分类名称不能为空'));

            return null;
        }

        $name = trim((string) $data['name']);
        throw_if($required && $name === '', new BusinessException('分类名称不能为空'));

        return $name !== '' ? $name : null;
    }

    private function resolveLevel(array $data): int
    {
        $level = (int) ($data['effective_product_group_level'] ?? $data['level'] ?? 0);

        if ($level <= 0) {
            $level = isset($data['second_product_group_id']) ? 3 : 2;
        }

        throw_if(! in_array($level, [1, 2, 3], true), new BusinessException('分类层级不正确'));

        return $level;
    }

    private function resolveFirstGroupForCategory(array $data): FirstProductGroup
    {
        $id = (int) ($data['first_product_group_id'] ?? 0);
        if ($id > 0) {
            $group = FirstProductGroup::query()->find($id);
            if ($group instanceof FirstProductGroup) {
                return $group;
            }
        }

        $code = trim((string) ($data['first_product_group_code'] ?? $data['code'] ?? ''));
        if ($code !== '') {
            $group = FirstProductGroup::query()->where('code', $code)->first();
            if ($group instanceof FirstProductGroup) {
                return $group;
            }

            $group = $this->hierarchyService->ensureFirstProductGroupForType($code);
            if ($group instanceof FirstProductGroup) {
                return $group;
            }
        }

        throw new BusinessException('一级分类不存在');
    }

    private function findFirstGroup(int $id): FirstProductGroup
    {
        $group = FirstProductGroup::query()->find($id);
        throw_if(! $group, new BusinessException('一级分类不存在'));

        return $group;
    }

    private function findSecondGroup(int $id): SecondProductGroup
    {
        $group = SecondProductGroup::query()->find($id);
        throw_if(! $group, new BusinessException('二级分类不存在'));

        return $group;
    }

    private function findThirdGroup(int $id): ThirdProductGroup
    {
        $group = ThirdProductGroup::query()->find($id);
        throw_if(! $group, new BusinessException('三级分类不存在'));

        return $group;
    }

    private function loadFirstGroup(int $id): FirstProductGroup
    {
        return FirstProductGroup::query()
            ->withCount('secondProductGroups')
            ->selectSub($this->physicalProductCountSubquery(1), 'products_count')
            ->selectSub($this->physicalProductCountSubquery(1, true), 'products_with_trashed_count')
            ->with([
                'secondProductGroups' => fn ($query) => $query
                    ->withCount('thirdProductGroups')
                    ->selectSub($this->physicalProductCountSubquery(2), 'products_count')
                    ->selectSub($this->physicalProductCountSubquery(2, true), 'products_with_trashed_count')
                    ->with([
                        'thirdProductGroups' => fn ($thirdQuery) => $thirdQuery
                            ->withCount([
                                'products',
                                'products as products_with_trashed_count' => fn ($productsQuery) => $productsQuery->withTrashed(),
                            ])
                            ->orderBy('sort_order')
                            ->orderBy('id'),
                    ])
                    ->orderBy('sort_order')
                    ->orderBy('id'),
            ])
            ->findOrFail($id);
    }

    private function loadSecondGroup(int $id): SecondProductGroup
    {
        return SecondProductGroup::query()
            ->with(['firstProductGroup'])
            ->withCount('thirdProductGroups')
            ->selectSub($this->physicalProductCountSubquery(2), 'products_count')
            ->selectSub($this->physicalProductCountSubquery(2, true), 'products_with_trashed_count')
            ->with([
                'thirdProductGroups' => fn ($query) => $query
                    ->withCount([
                        'products',
                        'products as products_with_trashed_count' => fn ($productsQuery) => $productsQuery->withTrashed(),
                    ])
                    ->orderBy('sort_order')
                    ->orderBy('id'),
            ])
            ->findOrFail($id);
    }

    private function loadThirdGroup(int $id): ThirdProductGroup
    {
        return ThirdProductGroup::query()
            ->with(['secondProductGroup.firstProductGroup'])
            ->withCount([
                'products',
                'products as products_with_trashed_count' => fn ($query) => $query->withTrashed(),
            ])
            ->findOrFail($id);
    }

    private function cascadeVisibility(int $level, int $groupId, int $isVisible): void
    {
        if ($level === 1) {
            SecondProductGroup::query()->where('first_product_group_id', $groupId)->update(['is_visible' => $isVisible]);
            ThirdProductGroup::query()
                ->whereIn('second_product_group_id', SecondProductGroup::query()->select('id')->where('first_product_group_id', $groupId))
                ->update(['is_visible' => $isVisible]);
            Product::query()->inFirstProductGroup($groupId)->update(['status' => $isVisible]);

            return;
        }

        if ($level === 2) {
            ThirdProductGroup::query()->where('second_product_group_id', $groupId)->update(['is_visible' => $isVisible]);
            Product::query()->inSecondProductGroup($groupId)->update(['status' => $isVisible]);

            return;
        }

        Product::query()->inCurrentProductGroup($groupId)->update(['status' => $isVisible]);
    }

    private function resequenceGroupIds(int $level, array $sortMap): void
    {
        if ($sortMap === []) {
            return;
        }

        $model = match ($level) {
            1 => new FirstProductGroup,
            2 => new SecondProductGroup,
            3 => new ThirdProductGroup,
            default => throw new BusinessException('分类层级不正确'),
        };

        $bindings = [];
        $caseSql = collect($sortMap)
            ->map(function (int $sortOrder, int $id) use (&$bindings): string {
                $bindings[] = $id;
                $bindings[] = $sortOrder;

                return 'WHEN ? THEN ?';
            })
            ->implode(' ');
        $placeholders = implode(',', array_fill(0, count($sortMap), '?'));
        $bindings[] = now();
        array_push($bindings, ...array_keys($sortMap));

        DB::statement(
            "UPDATE {$model->getTable()} SET sort_order = CASE id {$caseSql} END, updated_at = ? WHERE id IN ({$placeholders})",
            $bindings
        );
    }

    private function sortMap(array $ids): array
    {
        $sortMap = [];
        foreach (array_values($ids) as $index => $id) {
            $sortMap[(int) $id] = $index + 1;
        }

        return $sortMap;
    }

    private function secondGroupIdsByFirst(int $firstGroupId): array
    {
        return SecondProductGroup::query()
            ->where('first_product_group_id', $firstGroupId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function thirdGroupIdsBySecond(int $secondGroupId): array
    {
        return ThirdProductGroup::query()
            ->where('second_product_group_id', $secondGroupId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function physicalProductCountSubquery(int $level, bool $includeTrashed = false): Builder
    {
        $query = Product::query();
        if ($includeTrashed) {
            $query->withTrashed();
        }

        $query->selectRaw('COUNT(*)')
            ->join('third_product_groups as product_count_third', 'product_count_third.id', '=', 'products.product_group_id');

        if ($level === 1) {
            return $query
                ->join('second_product_groups as product_count_second', 'product_count_second.id', '=', 'product_count_third.second_product_group_id')
                ->whereColumn('product_count_second.first_product_group_id', 'first_product_groups.id');
        }

        return $query->whereColumn('product_count_third.second_product_group_id', 'second_product_groups.id');
    }

    private function generateUniqueSlug(Builder $query, mixed $source): string
    {
        $slug = Str::slug(trim((string) $source));
        if ($slug === '') {
            $slug = 'group';
        }

        $candidate = $slug;
        $suffix = 1;

        while ((clone $query)->where('slug', $candidate)->exists()) {
            $suffix++;
            $candidate = $slug.'-'.$suffix;
        }

        return $candidate;
    }
}
