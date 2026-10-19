<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Site\V2\ProductGroup\ListProductGroupCatalogRequest;
use App\Http\Requests\Site\V2\ProductGroup\ListProductGroupChildrenRequest;
use App\Http\Requests\Site\V2\ProductGroup\ListProductGroupProductsRequest;
use App\Http\Requests\Site\V2\ProductGroup\ListProductGroupsRequest;
use App\Http\Resources\Site\V2\SiteProductCardResource;
use App\Http\Resources\Site\V2\SiteProductGroupResource;
use App\Models\Product;
use App\Models\ThirdProductGroup;
use App\Services\ProductCatalog\ProductGroupV2QueryService;
use App\Support\ApiResponseBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class ProductGroupController extends Controller
{
    public function __construct(
        private readonly ProductGroupV2QueryService $productGroups,
    ) {}

    public function index(ListProductGroupsRequest $request): JsonResponse
    {
        return $this->paginate(
            $this->productGroups->paginateSiteRootGroups($request->validated()),
            SiteProductGroupResource::class
        );
    }

    public function children(ListProductGroupChildrenRequest $request, int $group): JsonResponse
    {
        return $this->paginate(
            $this->productGroups->paginateSiteChildren($group, $request->validated()),
            SiteProductGroupResource::class
        );
    }

    public function products(ListProductGroupProductsRequest $request, int $group): JsonResponse
    {
        $payload = $request->validated();
        $level = (int) $payload['level'];

        // 站点商品卡响应缓存（tags: site-products）：目录页递归展开时每个组/分页都命中缓存，
        // 消掉查询与资源拼装成本。管理端对商品/分组/折扣组的增删改会经模型事件主动失效
        // （见 AppServiceProvider），展示实时性不依赖 TTL 过期。
        $cacheKey = 'v1:'.$group.':'.$level.':'.md5(json_encode($payload));

        $data = Cache::tags(['site-products'])->remember($cacheKey, 60, function () use ($group, $level, $payload): array {
            return ApiResponseBuilder::pagination(
                $this->productGroups->paginateSiteProducts($group, $level, $payload),
                SiteProductCardResource::class
            );
        });

        return $this->success($data);
    }

    /**
     * 选购页目录聚合接口：一次请求返回「全部可见三级分组 + 当前要展示的商品」，
     * 替代前端逐个三级分组拉商品列表（30 个子分组就是 30 个请求）。其余三级
     * 分组的商品由前端按需再拉。
     *
     * 有三级分组时只下发该三级分组的商品（页面只展示它）；没有三级分组时
     * 才下发二级分组自己的商品。
     *
     * 响应同样缓存到 tags: site-products，商品/分组变更时由模型事件失效。
     */
    public function catalog(ListProductGroupCatalogRequest $request, int $group): JsonResponse
    {
        $payload = $request->validated();
        $childGroupId = (int) ($payload['child_group_id'] ?? 0);
        $resolveId = (int) ($payload['resolve_id'] ?? 0);
        $cacheKey = 'v2-catalog:'.$group.':'.$childGroupId.':'.$resolveId;

        $data = Cache::tags(['site-products'])->remember($cacheKey, 300, function () use ($request, $group, $childGroupId, $resolveId): array {
            $childrenPaginator = $this->productGroups->paginateSiteChildren($group, [
                'page' => 1,
                'page_size' => 50,
            ]);
            $childModels = $childrenPaginator->items();
            $children = SiteProductGroupResource::collection($childModels)->resolve($request);
            $childIds = array_map(static fn (mixed $child): int => (int) $child->id, $childModels);

            $targetChildId = $this->resolveCatalogChildId($group, $childIds, $childGroupId, $resolveId);

            // 有可见三级分组时页面只展示三级分组的商品，二级分组自己的商品列表
            // 前端根本不用（一条 200 个商品就 700KB+）。只有没有三级分组时才下发。
            $items = [];

            if ($targetChildId <= 0) {
                $items[] = [
                    'effective_product_group_id' => $group,
                    'effective_product_group_level' => 2,
                    'products' => $this->catalogProducts($group, 2, $request),
                ];
            } else {
                $items[] = [
                    'effective_product_group_id' => $targetChildId,
                    'effective_product_group_level' => 3,
                    'products' => $this->catalogProducts($targetChildId, 3, $request),
                ];
            }

            return [
                'effective_product_group_id' => $group,
                'effective_product_group_level' => 2,
                'children' => $children,
                'items_by_group' => $items,
            ];
        });

        return $this->success($data);
    }

    /**
     * @param  list<int>  $childIds
     */
    private function resolveCatalogChildId(int $group, array $childIds, int $childGroupId, int $resolveId): int
    {
        if ($childGroupId > 0 && in_array($childGroupId, $childIds, true)) {
            return $childGroupId;
        }

        if ($resolveId > 0) {
            if (in_array($resolveId, $childIds, true)) {
                return $resolveId;
            }

            $resolvedByProduct = $this->resolveCatalogChildByProductId($group, $resolveId);
            if ($resolvedByProduct > 0) {
                return $resolvedByProduct;
            }
        }

        return (int) ($childIds[0] ?? 0);
    }

    /**
     * 旧三段商品深链：第三段是商品 ID 时，解析出它所属的三级分组。
     */
    private function resolveCatalogChildByProductId(int $group, int $productId): int
    {
        $thirdGroupId = (int) Product::query()
            ->onSale()
            ->whereKey($productId)
            ->value('product_group_id');

        if ($thirdGroupId <= 0) {
            return 0;
        }

        $visible = ThirdProductGroup::query()
            ->whereKey($thirdGroupId)
            ->where('is_visible', 1)
            ->whereHas('secondProductGroup', static function ($query) use ($group): void {
                $query->whereKey($group)->where('is_visible', 1);
            })
            ->exists();

        return $visible ? $thirdGroupId : 0;
    }

    /**
     * 拉取某分组的全部商品卡（分页上限 50，最多 4 页），避免目录聚合接口被截断。
     *
     * @return list<array<string, mixed>>
     */
    private function catalogProducts(int $groupId, int $level, Request $request): array
    {
        $products = [];
        $page = 1;

        do {
            $paginator = $this->productGroups->paginateSiteProducts($groupId, $level, [
                'page' => $page,
                'page_size' => 50,
            ]);
            $items = $paginator->items();

            if ($items === []) {
                break;
            }

            $products = array_merge(
                $products,
                SiteProductCardResource::collection($items)->resolve($request)
            );
            $page++;
        } while (count($products) < $paginator->total() && $page <= 4);

        return $products;
    }
}
