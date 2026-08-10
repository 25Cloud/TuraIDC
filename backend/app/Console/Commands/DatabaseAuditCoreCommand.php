<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\DatabaseEngineeringService;
use Illuminate\Console\Command;

class DatabaseAuditCoreCommand extends Command
{
    protected $signature = 'db:audit-core
        {--json : 以 JSON 输出结果}
        {--strict : 存在异常项时返回非零退出码}';

    protected $description = '巡检 idc 实库核心表、外键、伪引用、孤儿数据、trace_id 和大表体量';

    public function handle(DatabaseEngineeringService $service): int
    {
        $report = $service->auditCore();
        $strict = (bool) $this->option('strict');

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $strict && $this->hasAnomaly($report) ? self::FAILURE : self::SUCCESS;
        }

        $this->info('数据库：'.$report['database']);
        $this->info('基础表数量：'.$report['table_count']);
        $this->line('基础表：'.implode(', ', $report['tables']));

        $this->newLine();
        $this->info('外键');
        foreach ($report['foreign_keys'] as $fk) {
            $this->line(sprintf(
                '- %s.%s -> %s.%s (%s)',
                $fk['table_name'],
                $fk['column_name'],
                $fk['referenced_table_name'],
                $fk['referenced_column_name'],
                $fk['constraint_name']
            ));
        }

        $this->renderMetricSection('0 伪引用', $report['zero_reference_metrics']);
        $this->renderMetricSection('孤儿记录', $report['orphan_metrics']);
        $this->renderMetricSection('缺失 trace_id', $report['trace_id_metrics']);

        $this->newLine();
        $this->info('大表体量');
        foreach ($report['table_size_metrics'] as $metric) {
            $this->line(sprintf(
                '- %s: rows=%d, size_mb=%.2f, updated_at=%s',
                $metric['table_name'],
                $metric['table_rows'],
                $metric['size_mb'],
                $metric['update_time'] ?? 'NULL'
            ));
        }

        $this->newLine();
        $this->info('索引概况');
        foreach ($report['index_metrics'] as $table => $indexes) {
            $this->line('- '.$table.': '.implode(', ', $indexes));
        }

        return $strict && $this->hasAnomaly($report) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $metrics
     */
    private function renderMetricSection(string $title, array $metrics): void
    {
        $this->newLine();
        $this->info($title);
        foreach ($metrics as $key => $value) {
            $this->line("- {$key}: {$value}");
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function hasAnomaly(array $report): bool
    {
        foreach (['zero_reference_metrics', 'orphan_metrics', 'trace_id_metrics'] as $section) {
            foreach ((array) ($report[$section] ?? []) as $value) {
                if ((int) $value > 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
