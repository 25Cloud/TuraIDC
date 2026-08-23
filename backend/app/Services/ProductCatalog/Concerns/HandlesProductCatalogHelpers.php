<?php

declare(strict_types=1);

namespace App\Services\ProductCatalog\Concerns;

use App\Constants\BillingCycle;
use App\Exceptions\BusinessException;
use App\Models\Product;
use App\Services\ProductCatalog\ProductDisplayNameResolver;
use App\Support\CacheKey;
use Illuminate\Support\Facades\Cache;

trait HandlesProductCatalogHelpers
{
    private const ADMIN_SUMMARY_CACHE_KEY = 'catalog:admin_summary:v1';

    private const SITE_CATALOG_CACHE_KEY = 'catalog:site:v1';

    private const SITE_PRODUCT_TYPES_CACHE_KEY = 'catalog:site:types';

    private const SITE_ROOT_GROUPS_CACHE_KEY = 'catalog:site:root_groups';

    private const SITE_CHILD_GROUPS_CACHE_KEY = 'catalog:site:children';

    private const SITE_GROUP_CATALOG_CACHE_KEY = 'catalog:site:group_catalog';

    private const SITE_PRODUCTS_CACHE_KEY = 'catalog:site:products';

    private const SITE_PRODUCT_DETAIL_CACHE_KEY = 'catalog:site:product_detail';

    private const SITE_PRODUCT_STOCK_CACHE_KEY = 'catalog:site:product_stock';

    private const SITE_PRODUCT_TYPES_CACHE_TTL_SECONDS = 900; // 优化：从 300s 提升到 900s（15分钟）

    private const SITE_GROUPS_CACHE_TTL_SECONDS = 900; // 优化：从 300s 提升到 900s（15分钟）

    private const SITE_PRODUCTS_CACHE_TTL_SECONDS = 600; // 优化：从 120s 提升到 600s（10分钟）

    private const SITE_PRODUCT_DETAIL_CACHE_TTL_SECONDS = 600; // 优化：从 120s 提升到 600s（10分钟）

    private const SITE_PRODUCT_STOCK_CACHE_TTL_SECONDS = 10; // 保持实时性，不调整

    private function forgetSiteCatalogCache(): void
    {
        Cache::forget(self::ADMIN_SUMMARY_CACHE_KEY);
        Cache::forget(self::SITE_CATALOG_CACHE_KEY);

        // ProductDisplayNameResolver 现为容器 singleton，其显示名与规格文案缓存是进程内长命的。
        // 商品目录发生变更时必须一并失效，否则长驻进程（队列 Worker、Octane）会继续返回旧值。
        app(ProductDisplayNameResolver::class)->flushCaches();

        // 清理所有 site:home 相关缓存
        if (Cache::supportsTags()) {
            Cache::tags(['site:home'])->flush();
            Cache::tags(['site:products'])->flush();
        }

        $this->bumpSiteCatalogCacheVersion();
    }

    private function bumpSiteCatalogCacheVersion(): void
    {
        Cache::put(CacheKey::siteCatalogSplitVersion(), $this->siteCacheVersion() + 1, now()->addYear());
    }

    private function siteCacheVersion(): int
    {
        return max(1, (int) Cache::get(CacheKey::siteCatalogSplitVersion(), 1));
    }

    private function siteCacheKey(string $cacheSuffix): string
    {
        return $cacheSuffix.':v'.$this->siteCacheVersion();
    }

    private function resolveDisplayStock(Product $product): int
    {
        $liveStock = $product->getAttribute('live_stock');

        if ($liveStock === null || $liveStock === '') {
            return (int) ($product->stock ?? 0);
        }

        return (int) $liveStock;
    }

    private function buildReorderedIds(
        array $scopeIds,
        int $movingId,
        ?int $referenceId,
        string $position,
        string $entityLabel,
    ): array {
        $currentIds = collect($scopeIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values()
            ->all();

        if ($referenceId !== null && $referenceId === $movingId) {
            return $currentIds;
        }

        $filteredIds = array_values(array_filter($currentIds, fn (int $id) => $id !== $movingId));

        if ($referenceId === null || $position === 'append') {
            $filteredIds[] = $movingId;

            return $filteredIds;
        }

        $referenceIndex = array_search((int) $referenceId, $filteredIds, true);
        throw_if($referenceIndex === false, new BusinessException("目标{$entityLabel}位置已失效，请刷新后重试"));

        $insertIndex = $position === 'before' ? (int) $referenceIndex : ((int) $referenceIndex + 1);
        array_splice($filteredIds, $insertIndex, 0, [$movingId]);

        return $filteredIds;
    }

    private function normalizePricing(mixed $pricing): array
    {
        if (! is_array($pricing)) {
            return [];
        }

        $normalized = [];

        foreach ($pricing as $cycle => $amount) {
            if ($amount === null || $amount === '') {
                continue;
            }

            $value = (float) $amount;
            if ($value <= 0) {
                continue;
            }

            $normalized[(string) $cycle] = number_format($value, 2, '.', '');
        }

        $monthly = isset($normalized['monthly']) && is_numeric($normalized['monthly'])
            ? (float) $normalized['monthly']
            : null;

        if ($monthly !== null && $monthly > 0) {
            foreach (BillingCycle::RENEWABLE_MONTHS as $cycle => $months) {
                if (! array_key_exists($cycle, $normalized)) {
                    $normalized[$cycle] = number_format($monthly * $months, 2, '.', '');
                }
            }
        }

        return $normalized;
    }

    private function normalizeConfigOptions(mixed $configOptions): array
    {
        return is_array($configOptions) ? $configOptions : [];
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }
}
