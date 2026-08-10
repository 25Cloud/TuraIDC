<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\JsonSchemaVersionBackfillService;
use Illuminate\Console\Command;

class BackfillJsonSchemaVersionCommand extends Command
{
    protected $signature = 'db:backfill-json-schema-version
        {--dry-run : 只预览无版本 JSON，不写入}
        {--execute : 执行版本回填}
        {--json : 以 JSON 输出结果}
        {--sample=20 : dry-run 样本数量}
        {--chunk=500 : execute 每批处理数量}';

    protected $description = '为订单、账单和支付回调 JSON 快照补齐 _schema_version';

    public function handle(JsonSchemaVersionBackfillService $service): int
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

        $this->info($execute ? 'JSON schema version 回填完成' : 'JSON schema version dry-run');
        foreach (($payload[$execute ? 'updated' : 'summary'] ?? []) as $target => $count) {
            $this->line("- {$target}: {$count}");
        }

        if ($execute) {
            $this->line('备份文件：'.(string) ($payload['backup_path'] ?? ''));
        }

        return self::SUCCESS;
    }
}
