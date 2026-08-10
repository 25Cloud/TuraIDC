<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\IdentityMigrationService;

class MigrateIdentityVerificationHistoriesCommand extends IdentityMigrateBaseCommand
{
    protected $signature = 'migrate:identity:verification-histories
        {--dry-run : 只输出统计信息，不写入新库}
        {--plan : 输出更详细的迁移计划}
        {--force : 忽略幂等保护，强制重新迁移}
        {--batch-size=500 : 每批迁移行数}
        {--json : 以 JSON 输出结果}';

    protected $description = '迁移旧库 verification_histories 到新库 idc';

    protected function sourceTable(): string
    {
        return 'verification_histories';
    }

    protected function targetTable(): string
    {
        return 'verification_histories';
    }

    protected function migrationName(): string
    {
        return 'identity_verification_histories';
    }

    protected function preCheck(IdentityMigrationService $service): ?array
    {
        $orphanRows = $service->sourceQuery(
            'SELECT COUNT(*) AS cnt
             FROM verification_histories vh
             LEFT JOIN users u ON vh.user_id = u.id
             WHERE u.id IS NULL'
        );
        $statusRows = $service->sourceQuery(
            'SELECT verification_status, COUNT(*) AS cnt
             FROM verification_histories
             GROUP BY verification_status
             ORDER BY verification_status ASC'
        );

        $statusSummary = [];
        foreach ($statusRows as $row) {
            $statusSummary['status_'.(string) $row->verification_status] = (int) $row->cnt;
        }

        return array_merge([
            '孤儿 user_id 记录数' => (int) ($orphanRows[0]->cnt ?? 0),
        ], $statusSummary);
    }

    protected function migrateRows(array $commonColumns, int $batchSize): int
    {
        /** @var IdentityMigrationService $service */
        $service = $this->laravel->make(IdentityMigrationService::class);

        $targetColumns = $service->getColumnNames($service->targetConnection(), 'verification_histories');
        $mapping = [
            'id' => 'id',
            'user_id' => 'user_id',
            'real_name' => 'real_name',
            'id_card' => 'id_card',
            'verification_status' => 'verification_status',
            'verification_message' => 'verification_message',
            'verification_certify_id' => 'verification_certify_id',
            'verification_biz_code' => 'verification_biz_code',
            'verification_type' => 'verification_type',
            'submitted_at' => 'submitted_at',
            'completed_at' => 'completed_at',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ];

        $effectiveMapping = [];
        foreach ($mapping as $sourceColumn => $targetColumn) {
            if (in_array($targetColumn, $targetColumns, true)) {
                $effectiveMapping[$sourceColumn] = $targetColumn;
            }
        }

        $sourceColumns = array_keys($effectiveMapping);
        $insertColumns = array_values(array_unique(array_values($effectiveMapping)));
        $totalMigrated = 0;
        $offset = 0;

        do {
            $rows = $service->sourcePaginate('verification_histories', $offset, $batchSize, $sourceColumns);
            $count = count($rows);

            if ($count === 0) {
                break;
            }

            $insertRows = array_map(function (object $row) use ($effectiveMapping): array {
                $raw = (array) $row;
                $mapped = [];
                foreach ($effectiveMapping as $sourceColumn => $targetColumn) {
                    $mapped[$targetColumn] = $raw[$sourceColumn] ?? null;
                }

                return $mapped;
            }, $rows);

            $totalMigrated += $service->batchInsertIgnore('verification_histories', $insertColumns, $insertRows);
            $offset += $count;

            $this->line("  已处理 {$offset} 行...");
        } while ($count === $batchSize);

        return $totalMigrated;
    }
}
