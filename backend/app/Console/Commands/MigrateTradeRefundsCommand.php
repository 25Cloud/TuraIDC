<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\TradeMigrationService;

class MigrateTradeRefundsCommand extends TradeMigrateBaseCommand
{
    protected $signature = 'migrate:trade:refunds
        {--dry-run : 只输出统计信息，不写入新库}
        {--plan : 输出更详细的迁移计划}
        {--force : 忽略幂等保护，强制重新迁移}
        {--batch-size=500 : 每批迁移行数}
        {--json : 以 JSON 输出结果}';

    protected $description = '从旧库 payments / 回调线索重建 idc.refunds';

    protected function sourceTable(): string
    {
        return 'payments';
    }

    protected function targetTable(): string
    {
        return 'refunds';
    }

    protected function migrationName(): string
    {
        return 'refunds';
    }

    protected function preCheck(TradeMigrationService $service): ?array
    {
        $refunded = $service->sourceQuery('SELECT COUNT(*) AS cnt FROM payments WHERE status = 3');

        return [
            '旧库退款态支付数' => (int) ($refunded[0]->cnt ?? 0),
        ];
    }

    protected function migrateRows(array $commonColumns, int $batchSize): int
    {
        $service = $this->laravel->make(TradeMigrationService::class);
        $payloads = $service->deriveRefundPayloads();

        if ($payloads === []) {
            return 0;
        }

        $columns = array_keys($payloads[0]);
        $chunks = array_chunk($payloads, $batchSize);
        $total = 0;

        foreach ($chunks as $index => $chunk) {
            $total += $service->batchUpsert(
                'refunds',
                $columns,
                $chunk,
                ['refund_no'],
                array_values(array_diff($columns, ['refund_no']))
            );

            $processed = min(($index + 1) * $batchSize, count($payloads));
            $this->line("  已处理 {$processed} 行...");
        }

        return $total;
    }
}
