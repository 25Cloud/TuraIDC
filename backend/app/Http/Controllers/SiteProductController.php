<?php

namespace App\Http\Controllers;

use App\Http\Requests\Site\IndexProductRequest;
use App\Http\Requests\Site\InitProductRequest;
use App\Http\Requests\Site\ProductGroupsRequest;
use App\Http\Requests\Site\QuoteProductRequest;
use App\Models\User;
use App\Services\Site\SiteProductQuoteService;
use App\Services\Site\SiteProductReadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SiteProductController extends Controller
{
    public function __construct(
        private SiteProductReadService $siteProductReadService,
        private SiteProductQuoteService $siteProductQuoteService,
    ) {}

    public function init(InitProductRequest $request)
    {
        $validated = $request->validated();

        return $this->success(
            $this->siteProductReadService->productsInit($validated['product_type'] ?? null)
        );
    }

    public function productTypes()
    {
        return $this->success($this->siteProductReadService->productTypes());
    }

    public function productGroups(ProductGroupsRequest $request)
    {
        $validated = $request->validated();

        return $this->success(
            $this->siteProductReadService->productGroups($validated['product_type'] ?? null)
        );
    }

    public function childGroups(int $groupId)
    {
        return $this->success($this->siteProductReadService->childGroups($groupId));
    }

    public function groupCatalog(int $groupId)
    {
        return $this->success($this->siteProductReadService->groupCatalog($groupId));
    }

    public function index(IndexProductRequest $request)
    {
        $validated = $request->validated();

        return $this->success($this->siteProductReadService->products($validated));
    }

    public function show(int $productId)
    {
        $product = $this->siteProductReadService->productDetail($productId);

        if (! $product) {
            return $this->error(40400, '商品不存在或已下架');
        }

        return $this->success([
            'product' => $product,
        ]);
    }

    public function stock(int $productId)
    {
        $payload = $this->siteProductReadService->productStock($productId);

        if (! $payload) {
            return $this->error(40400, '商品不存在或已下架');
        }

        return $this->success($payload);
    }

    public function quote(QuoteProductRequest $request, int $productId)
    {
        $validated = $request->validated();

        $user = $this->resolveClientUser($request);
        $payload = $this->siteProductQuoteService->resolveQuotePayload(
            $productId,
            $validated,
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

        return $this->success($payload);
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
