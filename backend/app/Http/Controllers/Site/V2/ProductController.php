<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\V2;

use App\Http\Controllers\Controller;
use App\Http\Requests\Site\V2\Product\ListProductsRequest;
use App\Http\Requests\Site\V2\Product\ListProductTypesRequest;
use App\Http\Requests\Site\V2\Product\ProductPurchaseContextRequest;
use App\Http\Requests\Site\V2\Product\QuoteProductRequest;
use App\Http\Requests\Site\V2\Product\ShowProductRequest;
use App\Http\Resources\Site\V2\SiteProductDetailResource;
use App\Http\Resources\Site\V2\SiteProductPurchaseContextResource;
use App\Http\Resources\Site\V2\SiteProductQuoteResource;
use App\Http\Resources\Site\V2\SiteProductStockResource;
use App\Http\Resources\Site\V2\SiteProductSummaryResource;
use App\Http\Resources\Site\V2\SiteProductTypeResource;
use App\Models\User;
use App\Services\ProductCatalog\ProductV2QueryService;
use App\Services\Site\SiteProductQuoteService;
use App\Services\Site\SiteProductReadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProductController extends Controller
{
    public function __construct(
        private readonly ProductV2QueryService $products,
        private readonly SiteProductReadService $siteProductReadService,
        private readonly SiteProductQuoteService $siteProductQuoteService,
    ) {}

    public function types(ListProductTypesRequest $request): JsonResponse
    {
        $payload = $this->siteProductReadService->productTypes();

        return $this->success([
            'list' => SiteProductTypeResource::collection((array) ($payload['list'] ?? []))->resolve(),
        ]);
    }

    public function index(ListProductsRequest $request): JsonResponse
    {
        return $this->paginate(
            $this->products->paginateSiteProducts($request->validated()),
            SiteProductSummaryResource::class
        );
    }

    public function show(ShowProductRequest $request, int $product): JsonResponse
    {
        return $this->success([
            'product' => (new SiteProductDetailResource($this->products->findSiteProduct($product)))->resolve(),
        ]);
    }

    public function stock(ShowProductRequest $request, int $product): JsonResponse
    {
        $payload = $this->siteProductReadService->productStock($product);

        if (! is_array($payload)) {
            return $this->error(40400, '商品不存在或已下架');
        }

        return $this->success(SiteProductStockResource::make($payload)->resolve());
    }

    public function quote(QuoteProductRequest $request, int $product): JsonResponse
    {
        $user = $this->resolveClientUser($request);
        $payload = $this->siteProductQuoteService->resolveQuotePayload(
            $product,
            $request->validated(),
            [
                'user_id' => (int) ($user?->id ?? 0),
                'request_id' => (string) $request->header('X-Request-Id', ''),
                'ip_address' => (string) $request->ip(),
                'user' => $user,
            ]
        );

        if (! is_array($payload)) {
            return $this->error(40400, '商品不存在或已下架');
        }

        return $this->success(SiteProductQuoteResource::make(array_merge([
            'product_id' => $product,
        ], $payload))->resolve());
    }

    public function purchaseContext(ProductPurchaseContextRequest $request): JsonResponse
    {
        return $this->success(
            (new SiteProductPurchaseContextResource($this->products->purchaseContext($request->validated())))->resolve()
        );
    }

    private function resolveClientUser(Request $request): ?User
    {
        $requestUser = $request->user();
        if ($requestUser instanceof User) {
            return $requestUser;
        }

        $sanctumUser = Auth::guard('sanctum')->user();

        return $sanctumUser instanceof User ? $sanctumUser : null;
    }
}
