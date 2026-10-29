<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ProductCatalog\SiteCatalogCacheInvalidator;
use App\Support\CacheKey;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 商品目录失效入口的回归。
 *
 * 锁死一条线上事故：官网商品卡的响应缓存是按标签存的独立键
 * （ProductGroupController::products / catalog 写入 Cache::tags(['site-products'])，
 * TTL 60 / 300 秒），键里**不带目录版本号**，所以 bump 版本号清不掉它们。
 *
 * 失效入口此前只清 admin_summary / catalog:site / 券树并 bump 版本号，
 * 漏了标签缓存；而管理端保存商品走 Product::withoutEvents()，模型事件那条
 * 失效链根本不会触发。两者叠加的结果是：后台改完商品描述并提示「同步成功」，
 * 前台卡片最长 300 秒仍在展示旧描述（生产实测：调用失效入口后
 * v2-catalog 键依然命中，必须显式 flush 标签才消失）。
 */
class SiteCatalogCacheInvalidatorTest extends TestCase
{
    public function test_flush_clears_the_site_product_card_response_cache(): void
    {
        $cache = Cache::tags(['site-products']);
        $cache->put('v2-catalog:104:2004:0:0', ['items_by_group' => []], 300);
        $cache->put('v1:104:2:0:abc', ['list' => []], 60);

        $this->assertNotNull($cache->get('v2-catalog:104:2004:0:0'));

        app(SiteCatalogCacheInvalidator::class)->flush();

        $this->assertNull($cache->get('v2-catalog:104:2004:0:0'));
        $this->assertNull($cache->get('v1:104:2:0:abc'));
    }

    public function test_flush_clears_the_coupon_group_tree_and_bumps_the_split_version(): void
    {
        Cache::put('coupon_product_group_tree_v1', ['tree' => []], 300);
        $before = app(SiteCatalogCacheInvalidator::class)->currentVersion();

        app(SiteCatalogCacheInvalidator::class)->flush();

        $this->assertNull(Cache::get('coupon_product_group_tree_v1'));
        $this->assertSame($before + 1, app(SiteCatalogCacheInvalidator::class)->currentVersion());
    }

    /**
     * 版本号必须真的写进键里：官网目录页全部经 versionedKey() 拼键读取，
     * 只有 bump 而不拼键，整条失效链等于没做。
     */
    public function test_versioned_key_changes_after_flush(): void
    {
        $invalidator = app(SiteCatalogCacheInvalidator::class);
        $beforeKey = $invalidator->versionedKey(CacheKey::siteCatalog());

        $invalidator->flush();

        $this->assertNotSame($beforeKey, $invalidator->versionedKey(CacheKey::siteCatalog()));
    }
}
