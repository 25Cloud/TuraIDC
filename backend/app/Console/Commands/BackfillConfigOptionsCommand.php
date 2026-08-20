<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 跨库补齐 products.config_options：
 * 从 source_connection（备份库，如 turaidc）读取非空 config_options，
 * 回填当前库中缺失（NULL / 空 JSON）的同 id 产品。
 */
class BackfillConfigOptionsCommand extends Command
{
    protected $signature = 'app:backfill-config-options
        {--product-ids= : 逗号分隔的商品 ID，仅处理指定商品}
        {--dry-run : 只统计，不写入数据库}
        {--json : 以 JSON 输出结果}';

    protected $description = '从备份库回填缺失的 products.config_options';

    public function handle(): int
    {
        $sourceConnection = (string) config('catalog_migration.source_connection', 'mysql');
        $dryRun = (bool) $this->option('dry-run');
        $json = (bool) $this->option('json');
        $idsRaw = trim((string) $this->option('product-ids'));

        if (! $this->tableReady('products', 'config_options')) {
            $this->error('当前库 products.config_options 不存在');

            return self::FAILURE;
        }

        $sourceReady = false;
        try {
            $sourceReady = $this->tableReady('products', 'config_options', $sourceConnection);
        } catch (\Throwable $exception) {
            $this->warn("源连接不可用：{$exception->getMessage()}");
        }

        if (! $sourceReady) {
            $this->error('源库（source_connection）不可用或无 products.config_options 列');

            return self::FAILURE;
        }

        $candidateQuery = DB::table('products')
            ->where(function ($query): void {
                $query->whereNull('config_options')
                    ->orWhere('config_options', '=', '')
                    ->orWhereRaw('JSON_LENGTH(config_options) = 0');
            });

        if ($idsRaw !== '') {
            $ids = array_filter(array_map('intval', explode(',', $idsRaw)));
            if ($ids === []) {
                $this->error('--product-ids 参数格式错误');

                return self::INVALID;
            }
            $candidateQuery->whereIn('id', $ids);
        }

        $candidates = $candidateQuery->pluck('id')->all();
        if ($candidates === []) {
            $this->info('当前库没有缺失 config_options 的产品');

            return self::SUCCESS;
        }

        // 从源库取同 id 产品的非空 config_options
        $sourceRows = DB::connection($sourceConnection)
            ->table('products')
            ->select(['id', 'config_options'])
            ->whereIn('id', $candidates)
            ->whereNotNull('config_options')
            ->where('config_options', '<>', '')
            ->whereRaw('JSON_LENGTH(config_options) > 0')
            ->orderBy('id')
            ->get();

        $updated = 0;
        $skipped = 0;
        foreach ($sourceRows as $row) {
            $decoded = json_decode((string) $row->config_options, true);
            if (! is_array($decoded) || $decoded === []) {
                $skipped++;
                continue;
            }

            $updated++;
            if (! $dryRun) {
                DB::table('products')
                    ->where('id', (int) $row->id)
                    ->update(['config_options' => $row->config_options]);
            }
        }

        if ($json) {
            $this->line(json_encode([
                'candidates' => count($candidates),
                'source_rows' => $sourceRows->count(),
                'updated' => $updated,
                'skipped' => $skipped,
                'dry_run' => $dryRun,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info($dryRun ? '=== config_options 补齐预检（--dry-run）===' : '=== config_options 补齐完成 ===');
        $this->line('待补齐产品: '.count($candidates));
        $this->line('源库有配置: '.$sourceRows->count());
        $this->line('实际回填: '.$updated);
        $this->line('跳过（源 JSON 非法）: '.$skipped);

        return self::SUCCESS;
    }

    private function tableReady(string $table, string $column, ?string $connection = null): bool
    {
        $schema = $connection !== null && $connection !== ''
            ? DB::connection($connection)->getSchemaBuilder()
            : DB::getSchemaBuilder();

        return $schema->hasTable($table) && $schema->hasColumn($table, $column);
    }
}
