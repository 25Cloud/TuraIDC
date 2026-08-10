<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\CatalogMigrationService;

class MigrateCatalogSupplierProductsCommand extends CatalogMigrateBaseCommand
{
    protected $signature = 'migrate:catalog:supplier-products
        {--dry-run : 只输出统计信息，不写入新库}
        {--plan : 输出更详细的迁移计划}
        {--force : 忽略幂等保护，强制重新迁移}
        {--batch-size=500 : 每批迁移行数}
        {--json : 以 JSON 输出结果}';

    protected $description = '从旧库 products 的供应商绑定字段重建新库 supplier_products';

    protected function sourceTable(): string
    {
        return 'products';
    }

    protected function targetTable(): string
    {
        return 'supplier_products';
    }

    protected function migrationName(): string
    {
        return 'catalog_supplier_products';
    }

    protected function migrateRows(array $commonColumns, int $batchSize): int
    {
        $service = $this->laravel->make(CatalogMigrationService::class);

        // 收集新库有效的供应商 ID
        $validSupplierIds = [];
        foreach ($service->targetQuery('SELECT id FROM suppliers') as $row) {
            $validSupplierIds[(int) $row->id] = true;
        }

        // 收集已迁移到新库的 product ID
        $validProductIds = [];
        foreach ($service->targetQuery('SELECT id FROM products') as $row) {
            $validProductIds[(int) $row->id] = true;
        }

        $total = 0;
        $offset = 0;

        do {
            $rows = $service->sourcePaginate('products', $offset, $batchSize);
            if ($rows === []) {
                break;
            }

            $payloads = [];
            foreach ($rows as $row) {
                $payload = $service->buildSupplierProductPayload((array) $row);
                // 只保留供应商和产品都已存在于新库的记录
                if ($payload !== null && isset($validSupplierIds[$payload['supplier_id']]) && isset($validProductIds[$payload['product_id']])) {
                    $payloads[] = $payload;
                }
            }

            if ($payloads !== []) {
                $columns = array_keys($payloads[0]);
                $total += $service->batchUpsert(
                    'supplier_products',
                    $columns,
                    $payloads,
                    ['supplier_id', 'product_id', 'upstream_product_code'],
                    array_values(array_diff($columns, ['supplier_id', 'product_id', 'upstream_product_code']))
                );
            }

            $offset += count($rows);
            $this->line("  已处理 {$offset} 行...");
        } while (count($rows) === $batchSize);

        return $total;
    }
}
