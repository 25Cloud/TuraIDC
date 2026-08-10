<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\ContentSystemMigrationService;

class MigrateContentSystemOperationLogsCommand extends ContentSystemMigrateBaseCommand
{
    protected $signature = 'migrate:content-system:operation-logs
        {--dry-run : 只输出统计信息，不写入新库}
        {--plan : 输出更详细的迁移计划}
        {--force : 忽略幂等保护，强制重新迁移}
        {--batch-size=500 : 每批迁移行数}
        {--json : 以 JSON 输出结果}';

    protected $description = '迁移旧库 operation_logs 到 idc.operation_logs';

    protected function sourceTable(): string
    {
        return 'operation_logs';
    }

    protected function targetTable(): string
    {
        return 'operation_logs';
    }

    protected function migrationName(): string
    {
        return 'content_system_operation_logs';
    }

    protected function migrateRows(array $commonColumns, int $batchSize): int
    {
        $service = $this->laravel->make(ContentSystemMigrationService::class);
        $payloads = $service->deriveOperationLogPayloads();

        if ($payloads === []) {
            return 0;
        }

        $columns = array_keys($payloads[0]);
        $chunks = array_chunk($payloads, $batchSize);
        $total = 0;

        foreach ($chunks as $index => $chunk) {
            $total += $service->batchUpsert(
                'operation_logs',
                $columns,
                $chunk,
                ['id'],
                array_values(array_diff($columns, ['id']))
            );

            $processed = min(($index + 1) * $batchSize, count($payloads));
            $this->line("  已处理 {$processed} 行...");
        }

        return $total;
    }
}
