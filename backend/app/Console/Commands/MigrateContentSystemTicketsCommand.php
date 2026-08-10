<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\ContentSystemMigrationService;

class MigrateContentSystemTicketsCommand extends ContentSystemMigrateBaseCommand
{
    protected $signature = 'migrate:content-system:tickets
        {--dry-run : 只输出统计信息，不写入新库}
        {--plan : 输出更详细的迁移计划}
        {--force : 忽略幂等保护，强制重新迁移}
        {--batch-size=500 : 每批迁移行数}
        {--json : 以 JSON 输出结果}';

    protected $description = '迁移旧库 tickets 到 idc.tickets';

    protected function sourceTable(): string
    {
        return 'tickets';
    }

    protected function targetTable(): string
    {
        return 'tickets';
    }

    protected function migrationName(): string
    {
        return 'content_system_tickets';
    }

    protected function migrateRows(array $commonColumns, int $batchSize): int
    {
        $service = $this->laravel->make(ContentSystemMigrationService::class);
        $payloads = $service->deriveTicketPayloads();

        if ($payloads === []) {
            return 0;
        }

        $columns = array_keys($payloads[0]);
        $chunks = array_chunk($payloads, $batchSize);
        $total = 0;

        foreach ($chunks as $index => $chunk) {
            $total += $service->batchUpsert(
                'tickets',
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
