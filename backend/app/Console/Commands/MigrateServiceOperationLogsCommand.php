<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\ServiceMigrationService;

class MigrateServiceOperationLogsCommand extends ServiceMigrateBaseCommand
{
    protected $signature = 'migrate:service:operation-logs
        {--dry-run : 只输出统计信息，不写入新库}
        {--plan : 输出更详细的迁移计划}
        {--force : 忽略幂等保护，强制重新迁移}
        {--batch-size=500 : 每批迁移行数}
        {--json : 以 JSON 输出结果}';

    protected $description = '迁移服务操作日志到 service_operation_logs';

    protected function sourceTable(): string
    {
        return 'operation_logs';
    }

    protected function targetTable(): string
    {
        return 'service_operation_logs';
    }

    protected function migrationName(): string
    {
        return 'service_operation_logs';
    }

    protected function preCheck(ServiceMigrationService $service): ?array
    {
        return [
            '可提取服务操作日志数' => count($service->deriveServiceOperationLogPayloads()),
        ];
    }

    protected function migrateRows(array $commonColumns, int $batchSize): int
    {
        $service = $this->laravel->make(ServiceMigrationService::class);
        $payloads = $service->deriveServiceOperationLogPayloads();

        if ($payloads === []) {
            return 0;
        }

        $columns = array_keys($payloads[0]);
        $chunks = array_chunk($payloads, $batchSize);
        $total = 0;

        foreach ($chunks as $index => $chunk) {
            $total += $service->batchUpsert(
                'service_operation_logs',
                $columns,
                $chunk,
                ['service_instance_id', 'operation_type', 'executed_at'],
                array_values(array_diff($columns, ['service_instance_id', 'operation_type', 'executed_at']))
            );

            $processed = min(($index + 1) * $batchSize, count($payloads));
            $this->line("  已处理 {$processed} 行...");
        }

        return $total;
    }
}
