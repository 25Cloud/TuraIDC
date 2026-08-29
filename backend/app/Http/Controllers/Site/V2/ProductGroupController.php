<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Site\V2\ProductGroup\ListProductGroupChildrenRequest;
use App\Http\Requests\Site\V2\ProductGroup\ListProductGroupProductsRequest;
use App\Http\Requests\Site\V2\ProductGroup\ListProductGroupsRequest;
use App\Http\Resources\Site\V2\SiteProductCardResource;
use App\Http\Resources\Site\V2\SiteProductGroupResource;
use App\Services\ProductCatalog\ProductGroupV2QueryService;
use App\Support\ApiResponseBuilder;
use Illuminate\Http\JsonResponse;
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
}
