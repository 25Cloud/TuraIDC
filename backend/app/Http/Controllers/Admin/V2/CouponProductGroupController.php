<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\V2\CouponProductGroup\ListCouponProductGroupChildrenRequest;
use App\Http\Requests\Admin\V2\CouponProductGroup\ListCouponProductGroupProductsRequest;
use App\Http\Requests\Admin\V2\CouponProductGroup\ListCouponProductGroupsRequest;
use App\Http\Resources\Admin\V2\CouponProductGroupResource;
use App\Http\Resources\Admin\V2\CouponProductResource;
use App\Services\ProductCatalog\CouponProductGroupQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class CouponProductGroupController extends Controller
{
    /**
     * 整树接口缓存键。整树体积大（三层分组 + 全量产品），
     * 一次性序列化/展示名解析成本不低，60s 缓存吸收掉。
     */
    private const TREE_CACHE_KEY = 'coupon_product_group_tree_v1';

    public function __construct(
        private readonly CouponProductGroupQueryService $queryService,
    ) {}

    public function index(ListCouponProductGroupsRequest $request): JsonResponse
    {
        return $this->paginate(
            $this->queryService->paginateFirstGroups(
                $request->validated(),
                $request->pageNumber(),
                $request->pageSize()
            ),
            CouponProductGroupResource::class
        );
    }

    /**
     * 一次性返回整棵树（全部分组 + 全量产品），60s 缓存。
     * 前端用它替代「递归逐层分页拉取」，一次请求即可构建商品选择树。
     */
    public function tree(Request $request): JsonResponse
    {
        $payload = Cache::remember(self::TREE_CACHE_KEY, 60, function () use ($request): array {
            $data = $this->queryService->fullTree();

            $products = [];
            foreach ($data['products'] as $product) {
                $key = '3:'.(int) $product->product_group_id;
                $products[$key][] = CouponProductResource::make($product)->resolve($request);
            }

            return [
                'groups' => CouponProductGroupResource::collection($data['groups'])->resolve($request),
                'products' => $products,
            ];
        });

        return $this->success($payload);
    }

    public function children(ListCouponProductGroupChildrenRequest $request): JsonResponse
    {
        return $this->paginate(
            $this->queryService->paginateChildren(
                $request->groupId(),
                $request->level(),
                $request->validated(),
                $request->pageNumber(),
                $request->pageSize()
            ),
            CouponProductGroupResource::class
        );
    }

    public function products(ListCouponProductGroupProductsRequest $request): JsonResponse
    {
        return $this->paginate(
            $this->queryService->paginateProducts(
                $request->groupId(),
                $request->level(),
                $request->validated(),
                $request->pageNumber(),
                $request->pageSize()
            ),
            CouponProductResource::class
        );
    }

    public function batchProducts(Request $request): JsonResponse
    {
        $groups = $request->input('groups', []);

        if (! is_array($groups) || empty($groups)) {
            return $this->success([]);
        }

        $result = $this->queryService->batchProducts($groups);
        $data = [];

        foreach ($result as $groupKey => $products) {
            $data[$groupKey] = CouponProductResource::collection($products)->resolve($request);
        }

        return $this->success($data);
    }
}
