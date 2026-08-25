<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ProductCatalog\CpuModelCatalogService;
use App\Services\ProductCatalog\InstanceSpecCatalogService;
use App\Services\ProductCatalog\SiteCatalogCacheInvalidator;
use App\Services\Site\SiteHomeService;
use App\Support\CacheKey;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 商品目录缓存失效的回归测试。
 *
 * 起因：forgetSiteCatalogCache() 曾有三份实现，各漏不同步骤且无任何测试覆盖。
 * 其中 InstanceSpecCatalogService 不 bump 拆分版本号——而官网目录页全部经
 * ProductSiteService::rememberSitePayload() 读取、键里嵌着版本号，
 * 于是改完实例规格后官网要等 600-900 秒 TTL 才更新。
 *
 * 这几条断言锁的就是「每个目录写入口都必须真正让目录缓存失效」，
 * 任何一处再退化成漏步骤的实现都会在这里失败。
 */
class SiteCatalogCacheInvalidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        DB::purge('sqlite');
        DB::reconnect('sqlite');
        app('db')->setDefaultConnection('sqlite');

        Schema::connection('sqlite')->create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('group_key', 100);
            $table->string('item_key', 100);
            $table->text('item_value')->nullable();
            $table->unique(['group_key', 'item_key'], 'settings_group_item_unique');
        });

        Cache::flush();
    }

    private function invalidator(): SiteCatalogCacheInvalidator
    {
        return app(SiteCatalogCacheInvalidator::class);
    }

    /** 往两个非版本化的目录键里塞值，返回失效前的版本号。 */
    private function seedCatalogCaches(): int
    {
        Cache::put(CacheKey::adminCatalogSummary(), ['products_total' => 1], now()->addHour());
        Cache::put(CacheKey::siteCatalog(), ['stale'], now()->addHour());

        return $this->invalidator()->currentVersion();
    }

    private function assertCatalogCachesInvalidated(int $versionBefore): void
    {
        // 版本号放第一条：它是唯一会让线上显示旧数据的那步，
        // 断言失败时希望直接看到它，而不是被前面的键断言挡住。
        $this->assertSame(
            $versionBefore + 1,
            $this->invalidator()->currentVersion(),
            '目录拆分版本号未递增——官网目录页会继续读旧键直到 600-900 秒 TTL 过期'
        );
        $this->assertNull(
            Cache::get(CacheKey::adminCatalogSummary()),
            '管理端目录概览缓存未失效'
        );
        $this->assertNull(
            Cache::get(CacheKey::siteCatalog()),
            '站点目录缓存未失效'
        );
    }

    public function test_flush_forgets_catalog_keys_and_bumps_version(): void
    {
        $versionBefore = $this->seedCatalogCaches();

        $this->invalidator()->flush();

        $this->assertCatalogCachesInvalidated($versionBefore);
    }

    public function test_versioned_key_follows_current_version(): void
    {
        $before = $this->invalidator()->versionedKey('catalog:site:products');

        $this->invalidator()->flush();

        $after = $this->invalidator()->versionedKey('catalog:site:products');

        $this->assertNotSame($before, $after, '版本号递增后拼出的键必须改变，否则旧缓存仍会命中');
        $this->assertStringStartsWith('catalog:site:products:v', $after);
    }

    public function test_saving_instance_spec_catalog_invalidates_site_catalog(): void
    {
        $versionBefore = $this->seedCatalogCaches();

        app(InstanceSpecCatalogService::class)->saveCatalog([
            ['text' => '2C4G', 'status' => '仅文本'],
        ]);

        $this->assertCatalogCachesInvalidated($versionBefore);
    }

    public function test_saving_cpu_model_catalog_invalidates_site_catalog(): void
    {
        $versionBefore = $this->seedCatalogCaches();

        app(CpuModelCatalogService::class)->saveCatalog([
            ['name' => 'Intel Xeon', 'models' => []],
        ]);

        $this->assertCatalogCachesInvalidated($versionBefore);
    }

    public function test_home_overview_cache_key_changes_when_catalog_changes(): void
    {
        // 首页聚合 payload 里含商品数据，键上却只有文章发布版本号——
        // 改商品时首页曾会继续返回旧值直到 600 秒 TTL 过期。
        $before = SiteHomeService::overviewCacheKey(0, 50, 4);

        $this->invalidator()->flush();

        $this->assertNotSame(
            $before,
            SiteHomeService::overviewCacheKey(0, 50, 4),
            '目录变更后首页聚合缓存键必须改变，否则首页仍展示旧商品'
        );
    }
}
