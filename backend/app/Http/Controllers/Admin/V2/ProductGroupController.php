<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\ProductGroup\BatchUpdateGroupProductsRequest;
use App\Http\Requests\Admin\V2\ProductGroup\DeleteProductGroupRequest;
use App\Http\Requests\Admin\V2\ProductGroup\ListProductGroupChildrenRequest;
use App\Http\Requests\Admin\V2\ProductGroup\ListProductGroupsRequest;
use App\Http\Requests\Admin\V2\ProductGroup\ReorderProductGroupsRequest;
use App\Http\Requests\Admin\V2\ProductGroup\ShowProductGroupRequest;
use App\Http\Requests\Admin\V2\ProductGroup\StoreProductGroupRequest;
use App\Http\Requests\Admin\V2\ProductGroup\UpdateProductGroupRequest;
use App\Http\Resources\Admin\V2\AdminProductGroupDetailResource;
use App\Http\Resources\Admin\V2\AdminProductGroupListResource;
use App\Http\Resources\Admin\V2\AdminProductOperationPayloadResource;
use App\Services\ProductCatalog\ProductCatalogService;
use App\Services\ProductCatalog\ProductGroupV2QueryService;
use Illuminate\Http\JsonResponse;

class ProductGroupController extends Controller
{
    public function __construct(
        private readonly ProductGroupV2QueryService $productGroups,
        private readonly ProductCatalogService $catalog,
    ) {}

    public function index(ListProductGroupsRequest $request): JsonResponse
    {
        return $this->paginate(
            $this->productGroups->paginateAdminRootGroups($request->validated()),
            AdminProductGroupListResource::class
        );
    }

    public function tree(ListProductGroupsRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $tree = $this->adminGroupTree($this->productGroups->listAdminTreeGroups($payload));
        $keyword = trim((string) ($payload['keyword'] ?? ''));

        if ($keyword !== '') {
            $tree = $this->filterAdminGroupTree($tree, $keyword);
        }

        return $this->success([
            'tree' => $tree,
            'list' => $tree,
            'total' => count($tree),
        ]);
    }

    public function show(ShowProductGroupRequest $request, int $group): JsonResponse
    {
        $payload = $request->validated();
        $productGroup = $this->productGroups->findAdminGroup($group, (int) $payload['level']);

        return $this->success([
            'group' => (new AdminProductGroupDetailResource($productGroup))->resolve(),
        ]);
    }

    public function children(ListProductGroupChildrenRequest $request, int $group): JsonResponse
    {
        $payload = $request->validated();

        return $this->paginate(
            $this->productGroups->paginateAdminChildGroups($group, (int) $payload['level'], $payload),
            AdminProductGroupListResource::class
        );
    }

    public function store(StoreProductGroupRequest $request): JsonResponse
    {
        $created = $this->catalog->createCategory($request->validated());

        return $this->success([
            'group' => $this->adminGroupResource($created),
        ], '分类已创建');
    }

    public function update(UpdateProductGroupRequest $request, int $group): JsonResponse
    {
        $updated = $this->catalog->updateCategory($group, $request->validated());

        return $this->success([
            'group' => $this->adminGroupResource($updated),
        ], '分类已更新');
    }

    public function destroy(DeleteProductGroupRequest $request, int $group): JsonResponse
    {
        $payload = $request->validated();

        $this->catalog->deleteCategory($group, (int) $payload['effective_product_group_level']);

        return $this->success(null, '分类已删除');
    }

    public function forceDelete(DeleteProductGroupRequest $request, int $group): JsonResponse
    {
        $payload = $request->validated();

        $result = $this->catalog->forceDeleteCategory($group, (int) $payload['effective_product_group_level']);

        return $this->success(
            AdminProductOperationPayloadResource::make($result)->resolve(),
            '分类及其商品已强制删除'
        );
    }

    public function batchUpdateProducts(BatchUpdateGroupProductsRequest $request, int $group): JsonResponse
    {
        $payload = $request->validated();
        $level = (int) $payload['effective_product_group_level'];

        return $this->success(
            AdminProductOperationPayloadResource::make(
                $this->catalog->batchUpdateGroupProducts($group, $level, $payload)
            )->resolve(),
            '分类下商品已批量更新'
        );
    }

    public function reorder(ReorderProductGroupsRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $level = (int) $payload['effective_product_group_level'];
        $parentId = match ($level) {
            2 => (int) ($payload['first_product_group_id'] ?? 0),
            3 => (int) ($payload['second_product_group_id'] ?? 0),
            default => null,
        };
        $groupIds = match ($level) {
            1 => (array) ($payload['first_product_group_ids'] ?? []),
            2 => (array) ($payload['second_product_group_ids'] ?? []),
            3 => (array) ($payload['third_product_group_ids'] ?? []),
        };

        return $this->success(
            AdminProductOperationPayloadResource::make(
                $this->catalog->reorderAdminCategories($level, $parentId, $groupIds)
            )->resolve(),
            '分类排序已更新'
        );
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array<string, mixed>
     */
    private function adminGroupResource(array $group): array
    {
        $model = $this->productGroups->findAdminGroup(
            (int) ($group['effective_product_group_id'] ?? $group['id'] ?? 0),
            (int) ($group['effective_product_group_level'] ?? $group['level'] ?? 0)
        );

        return AdminProductGroupDetailResource::make($model)->resolve();
    }

    /**
     * @param  array<string, mixed>  $groups
     * @return list<array<string, mixed>>
     */
    private function adminGroupTree(array $groups): array
    {
        $secondsByFirst = $groups['seconds_by_first'];
        $thirdsBySecond = $groups['thirds_by_second'];

        return $groups['roots']
            ->map(function ($root) use ($secondsByFirst, $thirdsBySecond): array {
                $children = ($secondsByFirst->get($root->id) ?? collect())
                    ->map(function ($second) use ($thirdsBySecond): array {
                        $grandchildren = ($thirdsBySecond->get($second->id) ?? collect())
                            ->map(fn ($third): array => [
                                ...AdminProductGroupListResource::make($third)->resolve(),
                                'children' => [],
                            ])
                            ->values()
                            ->all();

                        return [
                            ...AdminProductGroupListResource::make($second)->resolve(),
                            'children' => $grandchildren,
                        ];
                    })
                    ->values()
                    ->all();

                return [
                    ...AdminProductGroupListResource::make($root)->resolve(),
                    'children' => $children,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function filterAdminGroupTree(array $nodes, string $keyword): array
    {
        $normalizedKeyword = strtolower($keyword);
        $filtered = [];

        foreach ($nodes as $node) {
            $children = $this->filterAdminGroupTree((array) ($node['children'] ?? []), $keyword);
            $haystack = strtolower(implode(' ', [
                (string) ($node['name'] ?? ''),
                (string) ($node['label'] ?? ''),
                (string) ($node['slug'] ?? ''),
                (string) ($node['service_type_code'] ?? ''),
                (string) ($node['service_type_label'] ?? ''),
            ]));

            if (str_contains($haystack, $normalizedKeyword) || $children !== []) {
                $node['children'] = $children;
                $filtered[] = $node;
            }
        }

        return $filtered;
    }
}
