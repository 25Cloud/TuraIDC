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

class CouponProductGroupController extends Controller
{
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
}
