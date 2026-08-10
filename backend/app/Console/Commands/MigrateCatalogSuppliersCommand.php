<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\CatalogMigrationService;

class MigrateCatalogSuppliersCommand extends CatalogMigrateBaseCommand
{
    protected $signature = 'migrate:catalog:suppliers
        {--dry-run : 只输出统计信息，不写入新库}
        {--plan : 输出更详细的迁移计划}
        {--force : 忽略幂等保护，强制重新迁移}
        {--batch-size=500 : 每批迁移行数}
        {--json : 以 JSON 输出结果}';

    protected $description = '迁移旧库 suppliers 到新库 idc.suppliers';

    protected function sourceTable(): string
    {
        return 'suppliers';
    }

    protected function targetTable(): string
    {
        return 'suppliers';
    }

    protected function migrationName(): string
    {
        return 'catalog_suppliers';
    }

    protected function migrateRows(array $commonColumns, int $batchSize): int
    {
        $service = $this->laravel->make(CatalogMigrationService::class);
        $total = 0;
        $offset = 0;

        do {
            $rows = $service->sourcePaginate('suppliers', $offset, $batchSize);
            if ($rows === []) {
                break;
            }

            $payloads = array_map(
                static fn (object $row): array => $service->buildSupplierPayload((array) $row),
                $rows
            );

            $columns = array_keys($payloads[0]);
            $total += $service->batchUpsert(
                'suppliers',
                $columns,
                $payloads,
                ['id'],
                array_values(array_diff($columns, ['id']))
            );

            $offset += count($rows);
            $this->line("  已处理 {$offset} 行...");
        } while (count($rows) === $batchSize);

        return $total;
    }
}
