<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\CatalogMigrationService;

class MigrateCatalogPricingPlansCommand extends CatalogMigrateBaseCommand
{
    protected $signature = 'migrate:catalog:pricing-plans
        {--dry-run : 只输出统计信息，不写入新库}
        {--plan : 输出更详细的迁移计划}
        {--force : 忽略幂等保护，强制重新迁移}
        {--batch-size=500 : 每批迁移行数}
        {--json : 以 JSON 输出结果}';

    protected $description = '从旧库 products.pricing 拆分迁移到新库 product_pricing_plans';

    protected function sourceTable(): string
    {
        return 'products';
    }

    protected function targetTable(): string
    {
        return 'product_pricing_plans';
    }

    protected function migrationName(): string
    {
        return 'catalog_pricing_plans';
    }

    protected function migrateRows(array $commonColumns, int $batchSize): int
    {
        $service = $this->laravel->make(CatalogMigrationService::class);
        $total = 0;
        $offset = 0;

        do {
            $rows = $service->sourcePaginate('products', $offset, $batchSize);
            if ($rows === []) {
                break;
            }

            $payloads = [];
            foreach ($rows as $row) {
                foreach ($service->buildPricingPlans((array) $row) as $plan) {
                    $payloads[] = $plan;
                }
            }

            if ($payloads !== []) {
                $columns = array_keys($payloads[0]);
                $total += $service->batchUpsert(
                    'product_pricing_plans',
                    $columns,
                    $payloads,
                    ['product_id', 'billing_cycle'],
                    array_values(array_diff($columns, ['product_id', 'billing_cycle']))
                );
            }

            $offset += count($rows);
            $this->line("  已处理 {$offset} 行...");
        } while (count($rows) === $batchSize);

        return $total;
    }
}
