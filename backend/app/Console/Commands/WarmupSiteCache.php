<?php

namespace App\Console\Commands;

use App\Services\ProductCatalog\ProductCatalogService;
use App\Services\Site\SiteHomeService;
use Illuminate\Console\Command;

class WarmupSiteCache extends Command
{
    protected $signature = 'app:warmup-site-cache';

    protected $description = '预热首页和产品目录缓存';

    public function handle(
        SiteHomeService $homeService,
        ProductCatalogService $catalogService,
    ): int {
        $this->info('开始预热缓存...');

        $startTime = microtime(true);

        // 预热首页数据（包含 hero、公告、产品分组）
        $this->info('预热首页数据...');
        $homeService->overview();
        $this->info('首页缓存预热完成');

        // 预热产品类型
        $this->info('预热产品类型...');
        $catalogService->siteProductTypes();
        $this->info('产品类型缓存预热完成');

        // 预热产品分组
        $this->info('预热产品分组...');
        $catalogService->siteRootGroups();
        $this->info('产品分组缓存预热完成');

        $elapsed = round(microtime(true) - $startTime, 2);
        $this->info("所有缓存预热完成，耗时 {$elapsed}s");

        return self::SUCCESS;
    }
}
