<?php

declare(strict_types=1);

namespace App\Http\Controllers\Open\V2;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Open\V2\OpenQuoteRequest;
use App\Models\Product;
use App\Services\Site\SiteProductQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpenProductController extends Controller
{
    /** 列表默认页大小（与旧 limit 行为一致），page_size 可放大但不超过上限 */
    private const LIST_DEFAULT_PAGE_SIZE = 100;

    private const LIST_MAX_PAGE_SIZE = 200;

    public function __construct(
        private readonly SiteProductQuoteService $quoteService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $page = max((int) $request->query('page', '1'), 1);
        $pageSize = min(max((int) $request->query('page_size', (string) self::LIST_DEFAULT_PAGE_SIZE), 1), self::LIST_MAX_PAGE_SIZE);

        $query = Product::query()
            ->onSale()
            ->whereNotNull('product_group_id')
            ->orderByDesc('id');
        $total = (int) $query->count();
        $products = $query->forPage($page, $pageSize)->get();

        return $this->success([
            'list' => $products->map(fn (Product $product) => $this->present($product)),
            'total' => $total,
            'page' => $page,
            'page_size' => $pageSize,
        ]);
    }

    public function show(Product $product): JsonResponse
    {
        $this->assertOnSaleProduct($product);

        return $this->success($this->present($product));
    }

    public function quotes(OpenQuoteRequest $request, Product $product): JsonResponse
    {
        $this->assertOnSaleProduct($product);

        $data = $request->validated();
        $user = $request->attributes->get('api_key_user');

        $quote = $this->quoteService->quoteForUser($product, $data, $user, [
            'request_id' => 'open:'.$request->attributes->get('api_key')->key_prefix,
            'ip_address' => (string) $request->ip(),
        ]);

        return $this->success([
            'product_id' => (int) $product->id,
            'billing_cycle' => (string) $data['billing_cycle'],
            'original_total_amount' => (string) ($quote['original_total_amount'] ?? $quote['total_amount'] ?? '0.00'),
            'agent_amount' => (string) ($quote['agent_amount'] ?? $quote['total_amount'] ?? '0.00'),
            'total_amount' => (string) ($quote['total_amount'] ?? '0.00'),
            'agent_discount_rate' => (string) ($quote['agent_discount_rate'] ?? '100.00'),
            'quote_token' => (string) ($quote['quote_token'] ?? ''),
        ]);
    }

    private function assertOnSaleProduct(Product $product): void
    {
        if ((int) $product->status !== 1 || $product->product_group_id === null) {
            throw new BusinessException('商品不存在或已下架', 40400, 404);
        }
    }

    private function present(Product $product): array
    {
        return [
            'id' => (int) $product->id,
            'name' => (string) $product->name,
            'product_type' => (string) $product->product_type,
            'stock' => (int) $product->stock,
        ];
    }
}
