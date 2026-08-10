<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\AccountMigrationService;
use Illuminate\Console\Command;

abstract class AccountMigrateBaseCommand extends Command
{
    abstract protected function sourceTable(): string;

    abstract protected function targetTable(): string;

    abstract protected function migrationName(): string;

    /**
     * @param  list<string>  $commonColumns
     */
    abstract protected function migrateRows(array $commonColumns, int $batchSize): int;

    protected function preCheck(AccountMigrationService $service): ?array
    {
        return null;
    }

    public function handle(AccountMigrationService $service): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $isPlan = (bool) $this->option('plan');
        $isForce = (bool) $this->option('force');
        $isJson = (bool) $this->option('json');
        $batchSize = max(1, (int) $this->option('batch-size'));

        try {
            $service->ensureConnections();
        } catch (\Throwable $exception) {
            $this->error("连接失败：{$exception->getMessage()}");

            return self::FAILURE;
        }

        $stats = $service->dryRunStats($this->sourceTable(), $this->targetTable(), $this->migrationName());
        $preCheck = $this->preCheck($service);

        if ($isDryRun || $isPlan) {
            return $this->outputDryRun($stats, $preCheck, $isPlan, $isJson);
        }

        if (! $isForce) {
            if ($stats['migration_completed']) {
                $this->warn("迁移 `{$this->migrationName()}` 已完成。使用 --force 可强制重新迁移。");

                return self::SUCCESS;
            }

            if ($stats['target_populated']) {
                $this->warn("目标表 `{$this->targetTable()}` 已有 {$stats['target_row_count']} 行数据。");
                $this->warn('使用 --force 可忽略此保护，或先清空目标表。');

                return self::FAILURE;
            }
        }

        if ($stats['missing_in_target'] !== []) {
            $this->warn('以下列在旧库存在但新库缺失，将被跳过：'.implode(', ', $stats['missing_in_target']));
        }

        $this->info("开始迁移 `{$this->sourceTable()}` → `{$this->targetTable()}` ...");
        $this->info('公共列：'.$this->formatColumns($stats['common_columns']));

        $startTime = microtime(true);
        $migrated = $this->migrateRows($stats['common_columns'], $batchSize);
        $elapsed = round(microtime(true) - $startTime, 2);

        $service->markMigrationCompleted($this->migrationName(), $migrated);

        $result = [
            'migration' => $this->migrationName(),
            'source_table' => $this->sourceTable(),
            'target_table' => $this->targetTable(),
            'rows_migrated' => $migrated,
            'elapsed_sec' => $elapsed,
            'batch_size' => $batchSize,
            'forced' => $isForce,
        ];

        if ($isJson) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            $this->newLine();
            $this->info("迁移完成：{$migrated} 行，耗时 {$elapsed}s");
        }

        return self::SUCCESS;
    }

    private function outputDryRun(array $stats, ?array $preCheck, bool $detailed, bool $json): int
    {
        if ($json) {
            $output = $stats;
            if ($preCheck !== null) {
                $output['pre_check'] = $preCheck;
            }
            $this->line(json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info("=== 迁移计划：{$stats['source_table']} → {$stats['target_table']} ===");
        $this->newLine();
        $this->info('源表（旧库）');
        $this->line("  表名：{$stats['source_table']}");
        $this->line("  行数：{$stats['source_row_count']}");
        $this->newLine();

        $this->info('目标表（新库）');
        $this->line("  表名：{$stats['target_table']}");
        $this->line("  行数：{$stats['target_row_count']}");
        $this->line('  已有数据：'.($stats['target_populated'] ? '是（需 --force）' : '否'));
        $this->line('  已完成迁移：'.($stats['migration_completed'] ? '是' : '否'));
        $this->newLine();

        $this->info('公共列（将被迁移）');
        $this->line('  '.$this->formatColumns($stats['common_columns']));
        $this->newLine();

        if ($stats['missing_in_target'] !== []) {
            $this->warn('旧库独有列（将被跳过）');
            $this->line('  '.$this->formatColumns($stats['missing_in_target']));
            $this->newLine();
        }

        if ($stats['extra_in_target'] !== []) {
            $this->info('新库独有列（需默认值或后续填充）');
            $this->line('  '.$this->formatColumns($stats['extra_in_target']));
            $this->newLine();
        }

        if ($detailed) {
            $this->info('执行摘要');
            if ($stats['source_row_count'] === 0) {
                $this->line('  源表为空，无需迁移。');
            } elseif ($stats['migration_completed']) {
                $this->line('  迁移已完成，使用 --force 可重新执行。');
            } elseif ($stats['target_populated']) {
                $this->warn('  目标表已有数据，需要 --force 才能继续。');
            } else {
                $this->line("  预计迁移 {$stats['source_row_count']} 行，使用公共列 ".count($stats['common_columns']).' 个。');
            }

            if ($preCheck !== null) {
                $this->newLine();
                $this->info('前置检查');
                foreach ($preCheck as $key => $value) {
                    $this->line("  {$key}：{$value}");
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $columns
     */
    private function formatColumns(array $columns): string
    {
        if ($columns === []) {
            return '（无）';
        }

        return implode(', ', $columns);
    }
}
