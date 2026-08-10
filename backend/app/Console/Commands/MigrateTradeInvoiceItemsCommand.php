<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\TradeMigrationService;

class MigrateTradeInvoiceItemsCommand extends TradeMigrateBaseCommand
{
    protected $signature = 'migrate:trade:invoice-items
        {--dry-run : 只输出统计信息，不写入新库}
        {--plan : 输出更详细的迁移计划}
        {--force : 忽略幂等保护，强制重新迁移}
        {--batch-size=500 : 每批迁移行数}
        {--json : 以 JSON 输出结果}';

    protected $description = '迁移旧库 invoice_items 到 idc.invoice_items';

    protected function sourceTable(): string
    {
        return 'invoice_items';
    }

    protected function targetTable(): string
    {
        return 'invoice_items';
    }

    protected function migrationName(): string
    {
        return 'invoice_items';
    }

    protected function migrateRows(array $commonColumns, int $batchSize): int
    {
        $service = $this->laravel->make(TradeMigrationService::class);
        $payloads = $service->deriveInvoiceItemPayloads();

        if ($payloads === []) {
            return 0;
        }

        $columns = array_keys($payloads[0]);
        $chunks = array_chunk($payloads, $batchSize);
        $total = 0;

        foreach ($chunks as $index => $chunk) {
            $total += $service->batchUpsert(
                'invoice_items',
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
