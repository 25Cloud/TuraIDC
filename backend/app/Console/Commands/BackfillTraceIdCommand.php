<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\TraceIdBackfillService;
use Illuminate\Console\Command;

class BackfillTraceIdCommand extends Command
{
    protected $signature = 'db:backfill-trace-id
        {--dry-run : 只预览缺失和样本，不写入}
        {--execute : 执行回填}
        {--json : 以 JSON 输出结果}
        {--sample=20 : dry-run 样本数量}
        {--chunk=500 : execute 每批处理数量}';

    protected $description = '回填 invoices、payments、services、account_transactions 缺失的 trace_id';

    public function handle(TraceIdBackfillService $service): int
    {
        if ((bool) $this->option('dry-run') && (bool) $this->option('execute')) {
            $this->error('不能同时指定 --dry-run 和 --execute');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $payload = $execute
            ? $service->execute((int) $this->option('chunk'))
            : $service->inspect((int) $this->option('sample'));

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info($execute ? 'trace_id 回填完成' : 'trace_id 回填 dry-run');
        foreach (($payload[$execute ? 'updated' : 'summary'] ?? []) as $table => $count) {
            $this->line("- {$table}: {$count}");
        }

        if ($execute) {
            $this->line('备份文件：'.(string) ($payload['backup_path'] ?? ''));
        }

        return self::SUCCESS;
    }
}
