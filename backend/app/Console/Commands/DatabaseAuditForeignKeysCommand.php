<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\DatabaseEngineeringService;
use Illuminate\Console\Command;

class DatabaseAuditForeignKeysCommand extends Command
{
    protected $signature = 'db:audit-foreign-keys
        {--json : 以 JSON 输出结果}
        {--strict : 存在未分类字段或候选外键孤儿数据时返回非零退出码}';

    protected $description = '审计所有 *_id 字段的数据库级外键覆盖、候选外键和不可补约束字段';

    public function handle(DatabaseEngineeringService $service): int
    {
        $report = $service->auditForeignKeyCoverage();
        $strict = (bool) $this->option('strict');

        if ((bool) $this->option('json')) {
            $this->line(json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return $strict && $this->hasAnomaly($report) ? self::FAILURE : self::SUCCESS;
        }

        $this->info('数据库：'.$report['database']);
        foreach ((array) ($report['counts'] ?? []) as $key => $count) {
            $this->line("- {$key}: {$count}");
        }

        $this->renderGroup('候选外键', (array) ($report['groups']['candidate_fk'] ?? []), true);
        $this->renderGroup('多态/快照字段', (array) ($report['groups']['polymorphic_or_snapshot'] ?? []), false);
        $this->renderGroup('未分类字段', (array) ($report['groups']['unclassified'] ?? []), false);

        return $strict && $this->hasAnomaly($report) ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderGroup(string $title, array $rows, bool $withTarget): void
    {
        $this->newLine();
        $this->info($title);

        foreach ($rows as $row) {
            $target = $withTarget
                ? sprintf(' -> %s.%s', (string) $row['referenced_table_name'], (string) $row['referenced_column_name'])
                : '';
            $orphan = array_key_exists('orphan_count', $row)
                ? sprintf(' orphan=%d', (int) $row['orphan_count'])
                : '';

            $this->line(sprintf(
                '- %s.%s%s%s %s',
                (string) $row['table_name'],
                (string) $row['column_name'],
                $target,
                $orphan,
                (string) ($row['reason'] ?? '')
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function hasAnomaly(array $report): bool
    {
        if ((int) ($report['counts']['unclassified'] ?? 0) > 0) {
            return true;
        }

        foreach ((array) ($report['groups']['candidate_fk'] ?? []) as $row) {
            if ((int) ($row['orphan_count'] ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }
}
