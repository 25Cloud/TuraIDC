<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\DatabaseEngineeringService;
use Illuminate\Console\Command;

class DatabaseNormalizeCoreRelationsCommand extends Command
{
    protected $signature = 'db:normalize-core-relations
        {--execute : 实际写入；未指定时默认 dry-run，仅报告影响范围}
        {--json : 以 JSON 输出结果}';

    protected $description = '预览或执行核心关系伪引用规范化，并报告孤儿记录与 trace_id 缺口';

    public function handle(DatabaseEngineeringService $service): int
    {
        $execute = (bool) $this->option('execute');
        $summary = $service->normalizeCoreRelations($execute);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info($execute ? '核心关系规范化执行完成' : '核心关系规范化 dry-run 完成');
        if (! $execute) {
            $this->warn('当前为 dry-run，未写入数据库、未删除任何记录；确认影响范围后追加 --execute 执行可逆字段修复。');
        }
        foreach ($summary as $key => $value) {
            $this->line("- {$key}: {$value}");
        }

        return self::SUCCESS;
    }
}
