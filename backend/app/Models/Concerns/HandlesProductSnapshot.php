<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Product;
use App\Services\ProductCatalog\ProductDisplayNameResolver;

/**
 * Order 和 Invoice 共用的商品快照读写逻辑。
 * product_spec_snapshot / product_name_snapshot / setProductNameSnapshot
 * 三组 accessor/mutator 在两个模型中实现完全相同，统一收敛至此 Trait。
 */
trait HandlesProductSnapshot
{
    public function getProductSpecSnapshotAttribute(mixed $value): ?string
    {
        $resolved = trim((string) $value);

        return $resolved !== '' ? $resolved : null;
    }

    public function getProductNameSnapshotAttribute(mixed $value): ?string
    {
        $resolved = trim((string) ($this->product_spec_snapshot ?? $value));
        if ($resolved !== '') {
            return $resolved;
        }

        $product = $this->product ?? null;
        if ($product instanceof Product) {
            // 同 Product::getNameAttribute()：走容器单例，避免每次访问都新建解析器而丢掉其内部缓存
            $displayName = trim((string) (app(ProductDisplayNameResolver::class)->resolveForProduct($product)['product_display_name'] ?? ''));

            return $displayName !== '' ? $displayName : null;
        }

        return null;
    }

    public function setProductNameSnapshotAttribute(mixed $value): void
    {
        $normalized = trim((string) $value);

        if ($normalized !== '' && trim((string) ($this->attributes['product_spec_snapshot'] ?? '')) === '') {
            $this->attributes['product_spec_snapshot'] = $normalized;
        }

        unset($this->attributes['product_name_snapshot']);
    }

    /**
     * JSON 序列化辅助，供子类 setXxxSnapshotAttribute 调用。
     */
    protected function encodeSnapshotJson(?array $value): ?string
    {
        return is_array($value)
            ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
    }
}
