<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\ServiceMigrationService;

class MigrateServiceRemoteSnapshotsCommand extends ServiceMigrateBaseCommand
{
    protected $signature = 'migrate:service:remote-snapshots
        {--dry-run : 只输出统计信息，不写入新库}
        {--plan : 输出更详细的迁移计划}
        {--force : 忽略幂等保护，强制重新迁移}
        {--batch-size=500 : 每批迁移行数}
        {--json : 以 JSON 输出结果}';

    protected $description = '迁移远程快照到 service_remote_snapshots';

    protected function sourceTable(): string
    {
        return 'automation_logs';
    }

    protected function targetTable(): string
    {
        return 'service_remote_snapshots';
    }

    protected function migrationName(): string
    {
        return 'service_remote_snapshots';
    }

    protected function preCheck(ServiceMigrationService $service): ?array
    {
        return [
            '可提取远程快照数' => count($service->deriveServiceRemoteSnapshotPayloads()),
        ];
    }

    protected function migrateRows(array $commonColumns, int $batchSize): int
    {
        $service = $this->laravel->make(ServiceMigrationService::class);
        $payloads = $service->deriveServiceRemoteSnapshotPayloads();

        if ($payloads === []) {
            return 0;
        }

        $columns = array_keys($payloads[0]);
        $chunks = array_chunk($payloads, $batchSize);
        $total = 0;

        foreach ($chunks as $index => $chunk) {
            $total += $service->batchUpsert(
                'service_remote_snapshots',
                $columns,
                $chunk,
                ['service_instance_id', 'snapshot_key'],
                array_values(array_diff($columns, ['service_instance_id', 'snapshot_key']))
            );

            $processed = min(($index + 1) * $batchSize, count($payloads));
            $this->line("  已处理 {$processed} 行...");
        }

        return $total;
    }
}
