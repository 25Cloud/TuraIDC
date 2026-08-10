<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\DatabaseEngineeringService;
use Illuminate\Console\Command;

class DatabaseNormalizeCoreRelationsCommand extends Command
{
    protected $signature = 'db:normalize-core-relations
        {--json : 以 JSON 输出结果}';

    protected $description = '清理核心关系伪引用、孤儿记录，并回填核心表 trace_id';

    public function handle(DatabaseEngineeringService $service): int
    {
        $summary = $service->normalizeCoreRelations();

        if ((bool) $this->option('json')) {
            $this->line(json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('核心关系规范化完成');
        foreach ($summary as $key => $value) {
            $this->line("- {$key}: {$value}");
        }

        return self::SUCCESS;
    }
}
