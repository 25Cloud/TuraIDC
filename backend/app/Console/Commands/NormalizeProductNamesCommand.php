<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ProductCatalog\ProductNameNormalizationService;
use Illuminate\Console\Command;

class NormalizeProductNamesCommand extends Command
{
    protected $signature = 'product-catalog:normalize-product-names
        {--dry-run : 只预览改名清单，不写库（默认行为）}
        {--execute : 执行改名，写库前先在 storage/app 下留 SQL 备份}
        {--status=1 : 只处理该上架状态，-1 表示全部}
        {--json : 以 JSON 输出结果}
        {--sample=20 : dry-run 预览条数}
        {--chunk=200 : 每批更新条数}';

    protected $description = '把商品展示名规范成「三级分组名 + 规范规格摘要」（对接同步后的加工，幂等）';

    public function handle(ProductNameNormalizationService $service): int
    {
        if ((bool) $this->option('dry-run') && (bool) $this->option('execute')) {
            $this->error('不能同时指定 --dry-run 和 --execute');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $status = (int) $this->option('status');

        $payload = $execute
            ? $service->execute($status, (int) $this->option('chunk'))
            : $service->inspect($status, (int) $this->option('sample'));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $summary = (array) ($payload['summary'] ?? []);
        $this->line('扫描商品：'.(int) ($summary['scanned'] ?? 0));
        $this->line('待改名：'.(int) ($summary['pending'] ?? 0));
        $this->line('跳过：'.(int) ($summary['skipped'] ?? 0));
        $this->line('超长待人工：'.(int) ($summary['too_long'] ?? 0));

        foreach ((array) ($payload['samples'] ?? []) as $item) {
            $this->line(sprintf('  #%d %s → %s', (int) $item['id'], (string) $item['old'], (string) $item['new']));
        }

        foreach ((array) ($payload['too_long'] ?? []) as $item) {
            $this->warn(sprintf(
                '  超长未处理 #%d（%d 字符）：%s',
                (int) $item['id'],
                (int) $item['length'],
                (string) $item['new'],
            ));
        }

        if (! $execute) {
            $this->info('dry-run 未写库；确认后加 --execute 执行。');
            $this->warn('注意：该命令会把运营手工起的商品名也按规则重算，只作为同步后的运维步骤手动执行。');

            return self::SUCCESS;
        }

        $this->info('已改名：'.(int) ($payload['updated'] ?? 0));

        $backupPath = (string) ($payload['backup_path'] ?? '');
        if ($backupPath !== '') {
            $this->line('备份文件：'.$backupPath);
        }

        return self::SUCCESS;
    }
}
