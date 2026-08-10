<?php

declare(strict_types=1);

namespace App\Services\Admin\V2;

use App\Constants\CouponStatus;
use App\Models\Coupon;
use App\Models\CouponCampaign;
use App\Models\Product;
use App\Services\ClientServiceConsole\ServiceTrafficPackageService;
use App\Services\Finance\CouponCampaignService;
use App\Services\Finance\CouponService;
use App\Services\ProductCatalog\ProductCatalogService;

class AdminCatalogActionV2Service
{
    public function __construct(
        private readonly ProductCatalogService $products,
        private readonly CouponService $coupons,
        private readonly CouponCampaignService $campaigns,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function updateProductStatus(Product $product, bool $enabled): array
    {
        $targetStatus = $enabled ? 1 : 0;

        if ((int) $product->status !== $targetStatus) {
            $product = $this->products->toggleProductStatus($product);
        } else {
            $product = $product->refresh();
        }

        return $this->statusResult(
            (int) $product->id,
            '商品状态已更新',
            'product',
            [
                'id' => (int) $product->id,
                'status' => (int) $product->status,
            ],
            $enabled
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createProduct(array $payload): Product
    {
        return $this->products->createProduct($this->normalizeProductPayload($payload));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateProduct(Product $product, array $payload): Product
    {
        return $this->products->updateProduct($product, $this->normalizeProductPayload($payload));
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteProduct(Product|int $product): array
    {
        $product = $product instanceof Product ? $product : Product::withTrashed()->findOrFail($product);

        if (! $product->trashed()) {
            $this->products->deleteProduct($product);
        }

        return $this->statusResult((int) $product->id, '商品删除成功', 'product', [
            'id' => (int) $product->id,
            'lifecycle_status' => 'deleted',
        ], true);
    }

    public function restoreProduct(int $productId): Product
    {
        $product = Product::withTrashed()->findOrFail($productId);

        return $this->products->restoreProduct($product);
    }

    /**
     * @return array<string, mixed>
     */
    public function forceDeleteProduct(int $productId): array
    {
        $product = Product::withTrashed()->findOrFail($productId);
        $this->products->forceDeleteProduct($product);

        return $this->statusResult($productId, '商品已彻底删除', 'product', [
            'id' => $productId,
            'lifecycle_status' => 'purged',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function reorderProduct(array $payload): array
    {
        $product = Product::query()->findOrFail((int) $payload['product_id']);

        return $this->products->moveAdminProduct(
            $product,
            [
                'first_product_group_id' => $payload['target_first_product_group_id'] ?? null,
                'second_product_group_id' => $payload['target_second_product_group_id'] ?? null,
                'third_product_group_id' => $payload['target_third_product_group_id'] ?? null,
            ],
            isset($payload['reference_product_id']) ? (int) $payload['reference_product_id'] : null,
            (string) $payload['position']
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function splitPreview(array $payload): array
    {
        return $this->products->previewSplitProducts($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function splitProducts(array $payload): array
    {
        return $this->products->splitProducts($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function batchUpdateCategory(array $payload): array
    {
        return $this->products->batchUpdateCategory($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function batchUpdateProvisionHostname(array $payload, array $context): array
    {
        return $this->products->batchUpdateProvisionHostname($payload, $context);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function pullTrafficPackageCatalog(array $payload): array
    {
        return app(ServiceTrafficPackageService::class)
            ->pullCatalogTemplateForAdmin(
                (int) ($payload['second_product_group_id'] ?? 0),
                isset($payload['third_product_group_id']) ? (int) $payload['third_product_group_id'] : null,
                trim((string) ($payload['product_type'] ?? '')),
                (int) ($payload['supplier_id'] ?? 0),
                (int) ($payload['upstream_product_id'] ?? 0),
                (int) ($payload['source_product_id'] ?? 0),
            );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function updateCouponStatus(Coupon $coupon, bool $enabled, array $context = []): array
    {
        $targetStatus = $enabled ? CouponStatus::ACTIVE : CouponStatus::DISABLED;
        $snapshot = (int) $coupon->status === $targetStatus
            ? [
                'id' => (int) $coupon->id,
                'status' => (int) $coupon->refresh()->status,
            ]
            : $this->coupons->toggleCouponStatus($coupon, $context);

        return $this->statusResult(
            (int) ($snapshot['id'] ?? $coupon->id),
            '优惠券状态已更新',
            'coupon',
            [
                'id' => (int) ($snapshot['id'] ?? $coupon->id),
                'status' => (int) ($snapshot['status'] ?? $targetStatus),
            ],
            $enabled
        );
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function updateCouponCampaignStatus(CouponCampaign $campaign, bool $enabled, array $context = []): array
    {
        $targetStatus = $enabled ? CouponStatus::ACTIVE : CouponStatus::DISABLED;
        $snapshot = (int) $campaign->status === $targetStatus
            ? [
                'id' => (int) $campaign->id,
                'status' => (int) $campaign->refresh()->status,
            ]
            : $this->campaigns->toggleCampaignStatus($campaign, $context);

        return $this->statusResult(
            (int) ($snapshot['id'] ?? $campaign->id),
            '活动状态已更新',
            'coupon_campaign',
            [
                'id' => (int) ($snapshot['id'] ?? $campaign->id),
                'status' => (int) ($snapshot['status'] ?? $targetStatus),
            ],
            $enabled
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function runCouponCampaignTask(CouponCampaign $campaign, string $type, array $payload = [], array $context = []): array
    {
        unset($payload);

        $result = $this->campaigns->triggerCampaign($campaign, $context);

        return [
            'id' => (int) $campaign->id,
            'status' => 'completed',
            'message' => '活动批次已发放',
            'detail' => [
                'type' => $type,
                'result' => [
                    'campaign_id' => (int) data_get($result, 'campaign.id', $campaign->id),
                    'campaign_status' => (int) data_get($result, 'campaign.status', CouponStatus::ACTIVE),
                    'coupon_id' => (int) data_get($result, 'coupon.id', 0),
                    'coupon_code' => (string) data_get($result, 'coupon.code', ''),
                    'triggered_at' => data_get($result, 'triggered_at'),
                ],
            ],
        ];
    }

    /**
     * @param  array{id: int, status: int}  $target
     * @return array<string, mixed>
     */
    private function statusResult(int $id, string $message, string $targetType, array $target, bool $enabled): array
    {
        return [
            'id' => $id,
            'status' => 'completed',
            'message' => $message,
            'detail' => [
                'type' => 'status',
                'target' => $targetType,
                'enabled' => $enabled,
                $targetType => $target,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeProductPayload(array $payload): array
    {
        if (! isset($payload['type']) && isset($payload['product_type'])) {
            $payload['type'] = $payload['product_type'];
        }

        return $payload;
    }
}
