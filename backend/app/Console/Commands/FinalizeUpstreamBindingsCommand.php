<?php

namespace App\Console\Commands;

use App\Services\ProductCatalog\ProductCatalogService;
use Illuminate\Console\Command;
use Throwable;

class FinalizeUpstreamBindingsCommand extends Command
{
    protected $signature = 'products:finalize-upstream-bindings
        {--product-ids= : 逗号分隔的商品 ID，仅处理指定商品}
        {--force-all : 即使已存在模块和配置，也重新批量固化}
        {--skip-config : 仅补齐模块与自动开通，不同步配置项}
        {--dry-run : 仅输出统计，不写入数据库}';

    protected $description = '批量固化已绑定上游商品的模块与配置项';

    public function handle(ProductCatalogService $productCatalogService): int
    {
        try {
            $summary = $productCatalogService->finalizeUpstreamBindings([
                'product_ids' => (string) $this->option('product-ids'),
                'force_all' => (bool) $this->option('force-all'),
                'skip_config' => (bool) $this->option('skip-config'),
                'dry_run' => (bool) $this->option('dry-run'),
            ]);
        } catch (Throwable $exception) {
            $this->error('批量固化失败: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(($summary['dry_run'] ?? false) ? '商品批量固化预检完成' : '商品批量固化完成');
        $this->line('匹配商品数: '.(int) ($summary['matched_count'] ?? 0));
        $this->line('待处理商品数: '.(int) ($summary['eligible_count'] ?? 0));
        $this->line('实际更新数: '.(int) ($summary['updated_count'] ?? 0));
        $this->line('跳过数: '.(int) ($summary['skipped_count'] ?? 0));
        $this->line('同步配置项: '.((bool) ($summary['sync_config_options'] ?? false) ? '是' : '否'));

        return self::SUCCESS;
    }
}
