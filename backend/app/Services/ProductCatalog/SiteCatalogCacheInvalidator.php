<?php

declare(strict_types=1);

namespace App\Services\ProductCatalog;

use App\Support\CacheKey;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 商品目录缓存的唯一失效入口。
 *
 * 此前同名的 forgetSiteCatalogCache() 有三份实现，各自漏掉不同的步骤：
 *
 * | 步骤              | HandlesProductCatalogHelpers | CpuModelCatalogService | InstanceSpecCatalogService |
 * |-------------------|------------------------------|------------------------|----------------------------|
 * | 清 admin_summary  | 有                           | 无                     | 无                         |
 * | 清 catalog:site   | 有                           | 有                     | 清了个没人写的键           |
 * | 刷解析器进程缓存  | 有                           | 有                     | 有                         |
 * | bump 拆分版本号   | 有                           | 有                     | **无**                     |
 *
 * 最后一格是唯一会让线上显示旧数据的：官网目录页全部经 ProductSiteService::rememberSitePayload()
 * 读取，键里嵌着版本号（siteCacheKey()），**bump 版本号才是真正的失效手段**。
 * InstanceSpecCatalogService 不 bump，改完实例规格后目录页要等 600-900 秒 TTL 自然过期。
 * 它另外定义了一个 'catalog:site:instance_spec:v1' 常量并去清，而那个键全仓没有任何写入方，
 * 清它是空操作——名字和 trait 的常量一样，值却不同，看起来像做了事。
 *
 * ## 官网商品卡响应缓存（tags: site-products）必须在这里一起清
 *
 * ProductGroupController::products() / catalog() 把整页商品卡（含 description_html）
 * 按 `Cache::tags(['site-products'])` 缓存 60 / 300 秒。这两条键**不带版本号**，
 * 所以 bump 版本号对它们完全无效，只能显式 flush 标签。
 *
 * 曾经这里不 flush 标签，靠的是模型事件那条链（见 AppServiceProvider::
 * invalidateCatalogCacheOnCatalogChanges）。但管理端保存商品走的是
 * `Product::withoutEvents()`（ProductAdminService::createProduct/updateProduct），
 * 模型事件根本不会触发；于是「后台改完商品描述 → 前台卡片最长 300 秒还是旧的」
 * 就成了稳定可复现的线上现象（实测：调用本方法后 v2-catalog 键仍然命中）。
 * 失效入口自己把标签清掉，才不依赖调用方是用模型还是用 query builder 写库。
 *
 * 关于被移除的 Cache::tags(['site:home','site:products'])->flush()：
 * 全仓（含 plugins/）没有任何一处用 site:home/site:products 这两个标签写缓存。
 * 它想清的 site:home:* 是 SiteHomeService 写的**普通键**，tag flush 碰不到——
 * 那个意图现在由 SiteHomeService::overviewCacheKey() 把目录版本号编进键里真正实现。
 *
 * 用 app() 取本类而不是构造注入：CpuModelCatalogService / InstanceSpecCatalogService /
 * ProductSiteService / SiteHomeService 都存在被直接 new 的调用点
 * （ProductDisplayNameResolver:900 与若干测试），加构造参数会把它们全打断。
 */
final class SiteCatalogCacheInvalidator
{
    /**
     * 券可用商品树缓存键。
     *
     * 由本类统一清理：它在「商品/分组变了，依赖商品树的读缓存必须失效」这条语义上
     * 与官网目录缓存同级，此前 AppServiceProvider 与 ProductNameNormalizationService
     * 各清一次、ProductAdminService 那条链一次都没清。
     */
    private const COUPON_GROUP_TREE_CACHE_KEY = 'coupon_product_group_tree_v1';

    /**
     * 当前目录拆分版本号。读路径用它拼键，失效时 +1。
     */
    public function currentVersion(): int
    {
        return max(1, (int) Cache::get(CacheKey::siteCatalogSplitVersion(), 1));
    }

    /**
     * 给缓存后缀拼上当前版本号。
     */
    public function versionedKey(string $cacheSuffix): string
    {
        return $cacheSuffix.':v'.$this->currentVersion();
    }

    /**
     * 失效全部商品目录缓存。任何改动商品、分组、CPU 型号、实例规格、规格亮点的写操作都应调用它。
     *
     * 整体注册为 afterCommit 回调：多数调用方（如 ProductCategoryService）在 DB::transaction()
     * 内触发失效。若在事务提交前就 bump 版本号，并发请求会用新版本键缓存住提交前的旧目录，
     * 提交后不再有第二次失效，旧数据要挂满整个 TTL。afterCommit 的语义（Laravel 实测）：
     * 无事务立即执行、事务提交后执行、事务回滚则丢弃——三种场景都是想要的行为。
     *
     * 不做「N 秒内合并成一次」的节流：批量写（状态同步、改名、批量同步描述）逐条调用的成本
     * 只是每个标签一次 Redis INCR，而节流本身是一种「该失效却被跳过」的来源，宁可贵一点。
     */
    public function flush(): void
    {
        DB::afterCommit(function (): void {
            // 必须在 bump 之前：官网商品卡响应缓存是按标签存的独立键，与版本号无关。
            Cache::tags(['site-products'])->flush();
            Cache::forget(self::COUPON_GROUP_TREE_CACHE_KEY);
            Cache::forget(CacheKey::adminCatalogSummary());
            Cache::forget(CacheKey::siteCatalog());

            // ProductDisplayNameResolver 是容器 singleton，其显示名与规格文案缓存是进程内长命的。
            // 不刷的话长驻进程（队列 Worker、Octane）会继续返回旧值。
            app(ProductDisplayNameResolver::class)->flushCaches();

            // 放在最后：上面几步读到的都是旧版本下的键，bump 之后新请求一律落到新键。
            Cache::put(CacheKey::siteCatalogSplitVersion(), $this->currentVersion() + 1, now()->addYear());
        });
    }
}
