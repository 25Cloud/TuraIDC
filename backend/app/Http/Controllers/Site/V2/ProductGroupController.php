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
use Illuminate\Http\JsonResponse;

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

        return $this->paginate(
            $this->productGroups->paginateSiteProducts($group, (int) $payload['level'], $payload),
            SiteProductCardResource::class
        );
    }
}
