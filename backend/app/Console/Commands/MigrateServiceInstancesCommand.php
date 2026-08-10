<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\ServiceMigrationService;

class MigrateServiceInstancesCommand extends ServiceMigrateBaseCommand
{
    protected $signature = 'migrate:service:instances
        {--dry-run : 只输出统计信息，不写入新库}
        {--plan : 输出更详细的迁移计划}
        {--force : 忽略幂等保护，强制重新迁移}
        {--batch-size=500 : 每批迁移行数}
        {--json : 以 JSON 输出结果}';

    protected $description = '从旧库订单、发票、工单线索重建新库 service_instances';

    protected function sourceTable(): string
    {
        return 'orders';
    }

    protected function targetTable(): string
    {
        return 'service_instances';
    }

    protected function migrationName(): string
    {
        return 'service_instances';
    }

    protected function preCheck(ServiceMigrationService $service): ?array
    {
        $ordersWithService = $service->sourceQuery(
            'SELECT COUNT(*) AS cnt FROM orders WHERE service_id IS NOT NULL AND service_id != 0'
        );
        $paidOrders = $service->sourceQuery(
            'SELECT COUNT(*) AS cnt FROM orders WHERE product_id IS NOT NULL AND product_id != 0 AND type = ? AND status IN (1, 3)',
            ['new']
        );
        $ticketRefs = $service->sourceQuery(
            'SELECT COUNT(*) AS cnt FROM tickets WHERE service_id IS NOT NULL AND service_id != 0'
        );

        return [
            '显式 service_id 订单数' => (int) ($ordersWithService[0]->cnt ?? 0),
            '可推导已支付新购订单数' => (int) ($paidOrders[0]->cnt ?? 0),
            '工单 service_id 引用数' => (int) ($ticketRefs[0]->cnt ?? 0),
        ];
    }

    protected function migrateRows(array $commonColumns, int $batchSize): int
    {
        $service = $this->laravel->make(ServiceMigrationService::class);
        $payloads = $service->deriveServiceInstancePayloads();

        if ($payloads === []) {
            return 0;
        }

        $columns = array_keys($payloads[0]);
        $chunks = array_chunk($payloads, $batchSize);
        $total = 0;

        foreach ($chunks as $index => $chunk) {
            $total += $service->batchUpsert(
                'service_instances',
                $columns,
                $chunk,
                ['service_no'],
                array_values(array_diff($columns, ['service_no']))
            );

            $processed = min(($index + 1) * $batchSize, count($payloads));
            $this->line("  已处理 {$processed} 行...");
        }

        return $total;
    }
}
