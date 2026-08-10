<?php

namespace App\Services\Site;

use App\Constants\ProductType;
use App\Models\Product;
use App\Services\Finance\CheckoutSecurityService;
use App\Services\Finance\CheckoutService;
use App\Services\Finance\CouponService;
use Illuminate\Database\Eloquent\Builder;

class SiteProductQuoteService
{
    public function __construct(
        private CheckoutService $checkoutService,
        private CheckoutSecurityService $checkoutSecurityService,
        private CouponService $couponService,
    ) {}

    public function resolveQuotePayload(int $productId, array $validated, array $requestContext = []): ?array
    {
        $product = $this->findSaleProductForQuote($productId);

        if (! $product) {
            return null;
        }

        return $this->quote($product, $validated, $requestContext);
    }

    public function quote(Product $product, array $validated, array $requestContext = []): array
    {
        $billingCycle = (string) $validated['billing_cycle'];
        $quantity = max((int) ($validated['quantity'] ?? 1), 1);
        $userId = (int) ($requestContext['user_id'] ?? 0);
        $normalizedConfig = $this->checkoutService->normalizeConfig($product, (array) ($validated['config'] ?? []));
        $quote = $this->checkoutService->quote($product, $billingCycle, $normalizedConfig, $quantity);
        $coupon = $userId > 0
            ? $this->couponService->previewOwnedCoupon(
                isset($validated['user_coupon_id']) ? (int) $validated['user_coupon_id'] : null,
                $userId,
                $product,
                $billingCycle,
                (float) ($quote['total_amount'] ?? 0),
                'new'
            )
            : null;
        $availableCoupons = $userId > 0
            ? $this->couponService->availableCouponsForCheckout(
                $userId,
                $product,
                $billingCycle,
                (float) ($quote['total_amount'] ?? 0),
                'new'
            )
            : [];

        $subtotalAmount = (float) ($quote['total_amount'] ?? 0);
        $discountAmount = (float) ($coupon['discount_amount'] ?? 0);
        $quote['subtotal_amount'] = number_format($subtotalAmount, 2, '.', '');
        $quote['discount_amount'] = number_format($discountAmount, 2, '.', '');
        $quote['total_amount'] = number_format(max($subtotalAmount - $discountAmount, 0), 2, '.', '');
        $quote['coupon'] = $coupon;
        $quote['user_coupon_id'] = (int) ($coupon['user_coupon_id'] ?? 0);
        $quote['available_coupons'] = $availableCoupons;

        $securityPayload = $this->checkoutSecurityService->issueQuoteToken(
            (int) $product->id,
            $billingCycle,
            $normalizedConfig,
            $quote,
            [
                'request_id' => (string) ($requestContext['request_id'] ?? ''),
                'ip_address' => (string) ($requestContext['ip_address'] ?? ''),
            ]
        );

        return array_merge($quote, $securityPayload);
    }

    private function saleProductQuery(): Builder
    {
        $visibleProductTypes = ProductType::visibleValues();

        if ($visibleProductTypes === []) {
            return Product::query()->whereRaw('1 = 0');
        }

        return Product::query()
            ->onSale()
            ->whereNotNull('product_group_id')
            ->withVisibleProductGroupPath($visibleProductTypes);
    }

    private function findSaleProductForQuote(int $productId): ?Product
    {
        return $this->saleProductQuery()
            ->select([
                'id',
                'product_group_id',
                'service_type_code',
                'product_type',
                'pricing',
                'setup_fee',
                'config_options',
                'purchase_requires',
            ])
            ->whereKey($productId)
            ->first();
    }
}
