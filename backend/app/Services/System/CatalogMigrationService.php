<?php

declare(strict_types=1);

namespace App\Services\System;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CatalogMigrationService
{
    private const BILLING_CYCLE_SORT_ORDER = [
        'monthly' => 10,
        'quarterly' => 20,
        'semiannually' => 30,
        'annually' => 40,
        'biennially' => 50,
        'triennially' => 60,
        'one_time' => 70,
    ];

    private string $sourceConnection;

    private string $targetConnection;

    public function __construct()
    {
        $this->sourceConnection = (string) config('catalog_migration.source_connection', 'mysql');
        $this->targetConnection = (string) config('catalog_migration.target_connection', 'mysql');
    }

    public function ensureConnections(): void
    {
        $this->ensureConnection($this->sourceConnection, '旧库');
        $this->ensureConnection($this->targetConnection, '新库');
    }

    public function sourceConnection(): string
    {
        return $this->sourceConnection;
    }

    public function targetConnection(): string
    {
        return $this->targetConnection;
    }

    /**
     * @return array<int, object>
     */
    public function sourceQuery(string $sql, array $bindings = []): array
    {
        return DB::connection($this->sourceConnection)->select($sql, $bindings);
    }

    /**
     * @return array<int, object>
     */
    public function targetQuery(string $sql, array $bindings = []): array
    {
        return DB::connection($this->targetConnection)->select($sql, $bindings);
    }

    public function targetStatement(string $sql, array $bindings = []): int
    {
        return DB::connection($this->targetConnection)->affectingStatement($sql, $bindings);
    }

    public function sourceCount(string $table): int
    {
        $rows = $this->sourceQuery('SELECT COUNT(*) AS cnt FROM `'.$table.'`');

        return (int) ($rows[0]->cnt ?? 0);
    }

    public function targetCount(string $table): int
    {
        $rows = $this->targetQuery('SELECT COUNT(*) AS cnt FROM `'.$table.'`');

        return (int) ($rows[0]->cnt ?? 0);
    }

    /**
     * @return array<int, array{column_name: string, column_type: string, is_nullable: string, column_key: string, column_default: string|null}>
     */
    public function getTableColumns(string $connection, string $table): array
    {
        $databaseName = (string) DB::connection($connection)->getDatabaseName();

        $rows = DB::connection($connection)->select(
            'SELECT
                COLUMN_NAME AS column_name,
                COLUMN_TYPE AS column_type,
                IS_NULLABLE AS is_nullable,
                COLUMN_KEY AS column_key,
                COLUMN_DEFAULT AS column_default
             FROM information_schema.columns
             WHERE table_schema = ? AND table_name = ?
             ORDER BY ordinal_position',
            [$databaseName, $table]
        );

        return array_map(static fn (object $row) => [
            'column_name' => (string) $row->column_name,
            'column_type' => (string) $row->column_type,
            'is_nullable' => (string) $row->is_nullable,
            'column_key' => (string) $row->column_key,
            'column_default' => $row->column_default !== null ? (string) $row->column_default : null,
        ], $rows);
    }

    /**
     * @return list<string>
     */
    public function getColumnNames(string $connection, string $table): array
    {
        return array_map(
            static fn (array $column) => $column['column_name'],
            $this->getTableColumns($connection, $table)
        );
    }

    /**
     * @return list<string>
     */
    public function commonColumns(string $sourceTable, string $targetTable): array
    {
        $sourceColumns = $this->getColumnNames($this->sourceConnection, $sourceTable);
        $targetColumns = $this->getColumnNames($this->targetConnection, $targetTable);

        return array_values(array_intersect($sourceColumns, $targetColumns));
    }

    /**
     * @return list<string>
     */
    public function missingInTarget(string $sourceTable, string $targetTable): array
    {
        $sourceColumns = $this->getColumnNames($this->sourceConnection, $sourceTable);
        $targetColumns = $this->getColumnNames($this->targetConnection, $targetTable);

        return array_values(array_diff($sourceColumns, $targetColumns));
    }

    /**
     * @return list<string>
     */
    public function extraInTarget(string $sourceTable, string $targetTable): array
    {
        $sourceColumns = $this->getColumnNames($this->sourceConnection, $sourceTable);
        $targetColumns = $this->getColumnNames($this->targetConnection, $targetTable);

        return array_values(array_diff($targetColumns, $sourceColumns));
    }

    public function isTargetPopulated(string $table): bool
    {
        return $this->targetCount($table) > 0;
    }

    public function isMigrationCompleted(string $migrationName): bool
    {
        $this->ensureCheckpointTable();

        $rows = DB::connection($this->targetConnection)->select(
            'SELECT 1 FROM catalog_migration_checkpoints WHERE migration_name = ? LIMIT 1',
            [$migrationName]
        );

        return count($rows) > 0;
    }

    public function markMigrationCompleted(string $migrationName, int $rowCount): void
    {
        $this->ensureCheckpointTable();

        DB::connection($this->targetConnection)->statement(
            'INSERT INTO catalog_migration_checkpoints (migration_name, completed_at, row_count, created_at)
             VALUES (?, NOW(), ?, NOW())
             ON DUPLICATE KEY UPDATE completed_at = NOW(), row_count = VALUES(row_count)',
            [$migrationName, $rowCount]
        );
    }

    public function clearMigrationCheckpoint(string $migrationName): void
    {
        $this->ensureCheckpointTable();

        DB::connection($this->targetConnection)->statement(
            'DELETE FROM catalog_migration_checkpoints WHERE migration_name = ?',
            [$migrationName]
        );
    }

    /**
     * @param  list<string>|null  $columns
     * @return array<int, object>
     */
    public function sourcePaginate(
        string $table,
        int $offset,
        int $limit,
        ?array $columns = null,
        string $orderBy = 'id'
    ): array {
        $columnList = $columns !== null
            ? implode(', ', array_map(static fn (string $column) => "`{$column}`", $columns))
            : '*';

        return $this->sourceQuery(
            "SELECT {$columnList} FROM `{$table}` ORDER BY `{$orderBy}` ASC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
    }

    /**
     * @param  list<string>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function batchInsertIgnore(string $table, array $columns, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $columnList = implode(', ', array_map(static fn (string $column) => "`{$column}`", $columns));
        $rowPlaceholder = '('.implode(', ', array_fill(0, count($columns), '?')).')';
        $placeholders = implode(', ', array_fill(0, count($rows), $rowPlaceholder));

        $bindings = [];
        foreach ($rows as $row) {
            foreach ($columns as $column) {
                $bindings[] = $row[$column] ?? null;
            }
        }

        DB::connection($this->targetConnection)->affectingStatement(
            "INSERT IGNORE INTO `{$table}` ({$columnList}) VALUES {$placeholders}",
            $bindings
        );

        return count($rows);
    }

    /**
     * @param  list<string>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     * @param  list<string>  $uniqueBy
     * @param  list<string>  $updateColumns
     */
    public function batchUpsert(string $table, array $columns, array $rows, array $uniqueBy, array $updateColumns): int
    {
        if ($rows === []) {
            return 0;
        }

        DB::connection($this->targetConnection)
            ->table($table)
            ->upsert($rows, $uniqueBy, $updateColumns);

        return count($rows);
    }

    /**
     * @return array{
     *     source_table: string,
     *     target_table: string,
     *     source_row_count: int,
     *     target_row_count: int,
     *     common_columns: list<string>,
     *     missing_in_target: list<string>,
     *     extra_in_target: list<string>,
     *     target_populated: bool,
     *     migration_completed: bool
     * }
     */
    public function dryRunStats(string $sourceTable, string $targetTable, string $migrationName): array
    {
        return [
            'source_table' => $sourceTable,
            'target_table' => $targetTable,
            'source_row_count' => $this->sourceCount($sourceTable),
            'target_row_count' => $this->targetCount($targetTable),
            'common_columns' => $this->commonColumns($sourceTable, $targetTable),
            'missing_in_target' => $this->missingInTarget($sourceTable, $targetTable),
            'extra_in_target' => $this->extraInTarget($sourceTable, $targetTable),
            'target_populated' => $this->isTargetPopulated($targetTable),
            'migration_completed' => $this->isMigrationCompleted($migrationName),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sourceTableRows(string $table, string $orderBy = 'id'): array
    {
        return array_map(static fn (object $row) => (array) $row, $this->sourceQuery(
            "SELECT * FROM `{$table}` ORDER BY `{$orderBy}` ASC"
        ));
    }

    /**
     * @param  array<string, mixed>  $legacyGroup
     * @return array<string, mixed>
     */
    public function buildProductGroupPayload(array $legacyGroup): array
    {
        return [
            'id' => (int) $legacyGroup['id'],
            'parent_group_id' => isset($legacyGroup['parent_group_id']) ? (int) $legacyGroup['parent_group_id'] : null,
            'product_type' => $this->normalizeProductType($legacyGroup['product_type'] ?? null),
            'name' => $this->normalizeRequiredString($legacyGroup['name'] ?? null, '未命名分组 #'.(int) $legacyGroup['id']),
            'slug' => $this->normalizeRequiredString($legacyGroup['slug'] ?? null, 'group-'.(int) $legacyGroup['id']),
            'slogan' => $this->normalizeNullableString($legacyGroup['slogan'] ?? null),
            'sort_order' => (int) ($legacyGroup['sort_order'] ?? 0),
            'is_visible' => (int) (($legacyGroup['is_visible'] ?? 1) ? 1 : 0),
            'created_at' => $legacyGroup['created_at'] ?? null,
            'updated_at' => $legacyGroup['updated_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $legacyProduct
     * @param  array<string, mixed>|null  $group
     * @param  array<string, bool>  $usedSlugs
     * @return array<string, mixed>
     */
    public function buildProductPayload(array $legacyProduct, ?array $group, array $usedSlugs, ?int $resolvedGroupId = null): array
    {
        $productId = (int) ($legacyProduct['id'] ?? 0);
        $productType = $this->normalizeProductType($legacyProduct['product_type'] ?? null);
        $name = $this->resolveProductName($legacyProduct, $group);
        $slug = $this->generateUniqueSlug($name, $productId, $usedSlugs);

        return [
            'id' => $productId,
            'product_group_id' => $resolvedGroupId,
            'product_type' => $productType,
            'name' => $name,
            'slug' => $slug,
            'summary' => $this->resolveProductSummary($legacyProduct, $name),
            'remark' => $this->normalizeNullableString($legacyProduct['remark'] ?? null),
            'purchase_requires_json' => $this->normalizeJsonString($legacyProduct['purchase_requires'] ?? null),
            'status' => (int) (($legacyProduct['status'] ?? 1) ? 1 : 0),
            'sort_order' => (int) ($legacyProduct['sort_order'] ?? 0),
            'deleted_at' => $legacyProduct['deleted_at'] ?? null,
            'created_at' => $legacyProduct['created_at'] ?? null,
            'updated_at' => $legacyProduct['updated_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $legacyProduct
     * @return array<int, array<string, mixed>>
     */
    public function buildPricingPlans(array $legacyProduct): array
    {
        $pricing = $this->decodeJsonArray($legacyProduct['pricing'] ?? null);
        $normalizedPricing = [];

        foreach ($pricing as $cycle => $amount) {
            $billingCycle = $this->normalizeBillingCycle((string) $cycle);
            if ($billingCycle === null) {
                continue;
            }

            $normalizedPricing[$billingCycle] = $this->normalizeMoney($amount);
        }

        if ($normalizedPricing === []) {
            $normalizedPricing = ['monthly' => '0.00'];
        }

        $productId = (int) ($legacyProduct['id'] ?? 0);
        $status = (int) (($legacyProduct['status'] ?? 1) ? 1 : 0);
        $setupFee = $this->normalizeMoney($legacyProduct['setup_fee'] ?? null);
        $stockValue = (int) ($legacyProduct['stock'] ?? -1);
        $stockMode = $stockValue < 0 ? 'unlimited' : 'limited';
        $defaultCycle = array_key_exists('monthly', $normalizedPricing)
            ? 'monthly'
            : array_key_first($normalizedPricing);

        $rows = [];
        foreach ($normalizedPricing as $cycle => $amount) {
            $rows[] = [
                'product_id' => $productId,
                'billing_cycle' => $cycle,
                'currency' => 'CNY',
                'sale_price' => $amount,
                'renewal_price' => $amount,
                'setup_fee' => $setupFee,
                'stock_mode' => $stockMode,
                'stock_value' => $stockValue,
                'is_default' => $cycle === $defaultCycle ? 1 : 0,
                'sort_order' => self::BILLING_CYCLE_SORT_ORDER[$cycle] ?? 999,
                'status' => $status,
                'created_at' => $legacyProduct['created_at'] ?? null,
                'updated_at' => $legacyProduct['updated_at'] ?? null,
            ];
        }

        usort($rows, static fn (array $left, array $right) => $left['sort_order'] <=> $right['sort_order']);

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $legacyProduct
     * @return array<int, array<string, mixed>>
     */
    public function buildConfigOptions(array $legacyProduct): array
    {
        $configOptions = $this->decodeJsonArray($legacyProduct['config_options'] ?? null);
        if (! array_is_list($configOptions) || $configOptions === []) {
            return [];
        }

        $rows = [];
        $usedKeys = [];
        $productId = (int) ($legacyProduct['id'] ?? 0);
        $status = (int) (($legacyProduct['status'] ?? 1) ? 1 : 0);

        foreach ($configOptions as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $key = $this->buildConfigOptionKey($item, $index, $usedKeys);
            $usedKeys[$key] = true;
            $label = $this->normalizeRequiredString(
                $item['name'] ?? $item['field'] ?? null,
                '配置项 '.($index + 1)
            );

            $rows[] = [
                'product_id' => $productId,
                'option_key' => $key,
                'option_label' => $label,
                'option_type' => $this->normalizeOptionType($item['option_type'] ?? null, $item),
                'is_required' => (int) (($item['required'] ?? 0) ? 1 : 0),
                'default_value' => $this->normalizeNullableScalar($item['default_value'] ?? null),
                'option_schema_json' => $this->encodeJson($item),
                'sort_order' => ($index + 1) * 10,
                'status' => $status,
                'created_at' => $legacyProduct['created_at'] ?? null,
                'updated_at' => $legacyProduct['updated_at'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $legacySupplier
     * @return array<string, mixed>
     */
    public function buildSupplierPayload(array $legacySupplier): array
    {
        $apiKey = $this->normalizeNullableString($legacySupplier['api_key'] ?? null);

        return [
            'id' => (int) $legacySupplier['id'],
            'name' => $this->normalizeRequiredString($legacySupplier['name'] ?? null, '未命名供应商 #'.(int) $legacySupplier['id']),
            'code' => $this->normalizeRequiredString($legacySupplier['code'] ?? null, 'supplier-'.(int) $legacySupplier['id']),
            'interface_type' => $this->normalizeRequiredString($legacySupplier['interface_type'] ?? null, 'hosting_panel_api'),
            'api_url' => $this->normalizeNullableString($legacySupplier['api_url'] ?? null),
            'api_username' => $this->normalizeNullableString($legacySupplier['api_username'] ?? null),
            'api_key_encrypted' => $apiKey !== null ? encrypt($apiKey) : null,
            'contact_name' => $this->normalizeNullableString($legacySupplier['contact_name'] ?? null),
            'contact_phone' => $this->normalizeNullableString($legacySupplier['contact_phone'] ?? null),
            'contact_email' => $this->normalizeNullableString($legacySupplier['contact_email'] ?? null),
            'website' => $this->normalizeNullableString($legacySupplier['website'] ?? null),
            'status' => (int) (($legacySupplier['status'] ?? 1) ? 1 : 0),
            'sort_order' => (int) ($legacySupplier['sort_order'] ?? 0),
            'notes' => $this->normalizeNullableString($legacySupplier['notes'] ?? null),
            'created_at' => $legacySupplier['created_at'] ?? null,
            'updated_at' => $legacySupplier['updated_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $legacyProduct
     * @return array<string, mixed>|null
     */
    public function buildSupplierProductPayload(array $legacyProduct): ?array
    {
        $supplierId = isset($legacyProduct['supplier_id']) && $legacyProduct['supplier_id'] !== ''
            ? (int) $legacyProduct['supplier_id']
            : null;
        $supplierProductId = isset($legacyProduct['supplier_product_id']) && $legacyProduct['supplier_product_id'] !== ''
            ? (string) $legacyProduct['supplier_product_id']
            : null;

        if ($supplierId === null || $supplierProductId === null || $supplierProductId === '') {
            return null;
        }

        $purchaseRequires = $this->decodeJsonArray($legacyProduct['purchase_requires'] ?? null);
        $mappingConfig = [
            'legacy_supplier_product_id' => isset($legacyProduct['supplier_product_id']) ? (int) $legacyProduct['supplier_product_id'] : null,
            'legacy_product_id' => (int) ($legacyProduct['id'] ?? 0),
            'auto_setup' => (int) (($legacyProduct['auto_setup'] ?? 0) ? 1 : 0),
            'upstream_split' => is_array($purchaseRequires['upstream_split'] ?? null) ? $purchaseRequires['upstream_split'] : null,
            'upstream_default_config' => is_array($purchaseRequires['upstream_default_config'] ?? null) ? $purchaseRequires['upstream_default_config'] : null,
        ];

        return [
            'supplier_id' => $supplierId,
            'product_id' => (int) ($legacyProduct['id'] ?? 0),
            'upstream_product_code' => $supplierProductId,
            'upstream_plan_code' => isset($purchaseRequires['upstream_split']['variant_key'])
                ? (string) $purchaseRequires['upstream_split']['variant_key']
                : null,
            'provision_module' => $this->normalizeNullableString($legacyProduct['provision_module'] ?? null),
            'mapping_config_json' => $this->encodeJson($mappingConfig),
            'is_default' => 1,
            'status' => (int) (($legacyProduct['status'] ?? 1) ? 1 : 0),
            'created_at' => $legacyProduct['created_at'] ?? null,
            'updated_at' => $legacyProduct['updated_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $legacyServer
     * @return array<string, mixed>
     */
    public function buildServerPayload(array $legacyServer): array
    {
        return [
            'id' => (int) $legacyServer['id'],
            'supplier_id' => null,
            'name' => $this->normalizeRequiredString($legacyServer['name'] ?? null, '未命名节点 #'.(int) $legacyServer['id']),
            'hostname' => $this->normalizeNullableString($legacyServer['hostname'] ?? null),
            'ip_address' => $this->normalizeNullableString($legacyServer['ip_address'] ?? null),
            'region_code' => null,
            'server_role' => $this->normalizeNullableString($legacyServer['type'] ?? null),
            'module' => $this->normalizeNullableString($legacyServer['module'] ?? null),
            'module_config_json' => $this->normalizeJsonString($legacyServer['module_config'] ?? null),
            'max_accounts' => (int) ($legacyServer['max_accounts'] ?? 0),
            'current_accounts' => (int) ($legacyServer['current_accounts'] ?? 0),
            'status' => (int) (($legacyServer['status'] ?? 1) ? 1 : 0),
            'created_at' => $legacyServer['created_at'] ?? null,
            'updated_at' => $legacyServer['updated_at'] ?? null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $legacyProducts
     * @return array{pricing_plan_count: int, config_option_count: int, supplier_product_count: int}
     */
    public function deriveCatalogMetrics(array $legacyProducts): array
    {
        $pricingPlanCount = 0;
        $configOptionCount = 0;
        $supplierProductCount = 0;

        foreach ($legacyProducts as $legacyProduct) {
            $pricingPlanCount += count($this->buildPricingPlans($legacyProduct));
            $configOptionCount += count($this->buildConfigOptions($legacyProduct));

            if ($this->buildSupplierProductPayload($legacyProduct) !== null) {
                $supplierProductCount++;
            }
        }

        return [
            'pricing_plan_count' => $pricingPlanCount,
            'config_option_count' => $configOptionCount,
            'supplier_product_count' => $supplierProductCount,
        ];
    }

    private function normalizeProductType(mixed $value): string
    {
        $normalized = trim(Str::lower((string) ($value ?? '')));

        return $normalized !== '' ? Str::limit($normalized, 30, '') : 'other';
    }

    /**
     * @param  array<string, mixed>  $legacyProduct
     * @param  array<string, mixed>|null  $group
     */
    private function resolveProductName(array $legacyProduct, ?array $group): string
    {
        $productId = (int) ($legacyProduct['id'] ?? 0);

        if ($group !== null) {
            $groupName = $this->normalizeNullableString($group['name'] ?? null);
            if ($groupName !== null) {
                return $groupName.' '.Str::upper($this->normalizeProductType($legacyProduct['product_type'] ?? null));
            }
        }

        $remark = $this->normalizeNullableString($legacyProduct['remark'] ?? null);
        if ($remark !== null) {
            return $remark;
        }

        return '商品 #'.$productId;
    }

    /**
     * @param  array<string, mixed>  $legacyProduct
     */
    private function resolveProductSummary(array $legacyProduct, string $name): ?string
    {
        $summary = $this->normalizeNullableString($legacyProduct['remark'] ?? null);

        if ($summary === null || $summary === $name) {
            return null;
        }

        return Str::limit($summary, 255, '');
    }

    /**
     * @param  array<string, bool>  $usedSlugs
     */
    private function generateUniqueSlug(string $name, int $id, array $usedSlugs): string
    {
        $base = $this->makeSlugBase($name);

        if ($base === '') {
            $base = 'product-'.$id;
        }

        if (! isset($usedSlugs[$base])) {
            return $base;
        }

        return $base.'-'.$id;
    }

    private function makeSlugBase(string $value): string
    {
        $transliterated = $value;

        if (class_exists(\Transliterator::class)) {
            $converted = \Transliterator::create('Any-Latin; Latin-ASCII; Lower()');
            if ($converted instanceof \Transliterator) {
                $transliterated = (string) $converted->transliterate($value);
            }
        }

        $slug = Str::slug($transliterated, '-');

        if ($slug === '') {
            $slug = Str::slug($value, '-');
        }

        return Str::limit($slug, 120, '');
    }

    private function normalizeBillingCycle(string $cycle): ?string
    {
        $normalized = trim(Str::lower($cycle));
        $aliases = [
            'monthly' => 'monthly',
            'month' => 'monthly',
            'quarterly' => 'quarterly',
            'quarter' => 'quarterly',
            'semiannually' => 'semiannually',
            'semi-annual' => 'semiannually',
            'half_year' => 'semiannually',
            'annually' => 'annually',
            'annual' => 'annually',
            'yearly' => 'annually',
            'one_time' => 'one_time',
            'onetime' => 'one_time',
            'once' => 'one_time',
        ];

        return $aliases[$normalized] ?? null;
    }

    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, bool>  $usedKeys
     */
    private function buildConfigOptionKey(array $item, int $index, array $usedKeys): string
    {
        $candidate = $this->normalizeNullableString($item['field'] ?? null)
            ?? $this->normalizeNullableString($item['name'] ?? null)
            ?? (isset($item['id']) ? 'option-'.(string) $item['id'] : null)
            ?? 'option-'.($index + 1);

        $base = Str::snake(Str::replace(['-', ' '], '_', $candidate));
        $base = preg_replace('/[^a-z0-9_]+/i', '_', $base ?? '') ?? '';
        $base = trim((string) $base, '_');

        if ($base === '') {
            $base = 'option_'.($index + 1);
        }

        if (! isset($usedKeys[$base])) {
            return Str::limit($base, 64, '');
        }

        return Str::limit($base.'_'.($index + 1), 64, '');
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function normalizeOptionType(mixed $legacyType, array $item): string
    {
        $typeString = trim(Str::lower((string) $legacyType));
        if ($typeString !== '' && ! ctype_digit($typeString)) {
            return match ($typeString) {
                'text', 'input' => 'text',
                'textarea' => 'textarea',
                'number', 'numeric' => 'number',
                'checkbox', 'check' => 'checkbox',
                'radio' => 'radio',
                'select', 'dropdown' => 'select',
                default => 'text',
            };
        }

        $typeCode = (int) $legacyType;

        return match ($typeCode) {
            2 => 'textarea',
            3 => 'number',
            4 => 'checkbox',
            5 => 'radio',
            6, 7, 8, 9, 10 => 'select',
            default => isset($item['sub']) ? 'select' : 'text',
        };
    }

    private function normalizeNullableScalar(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeRequiredString(mixed $value, string $fallback): string
    {
        $normalized = trim((string) ($value ?? ''));

        return $normalized !== '' ? $normalized : $fallback;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeMoney(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        if (is_string($value)) {
            $value = trim($value);
        }

        if (! is_numeric($value)) {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }

    /**
     * @return array<mixed>
     */
    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if ($value === null) {
            return [];
        }

        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function normalizeJsonString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && trim($value) === '') {
            return null;
        }

        $decoded = $this->decodeJsonArray($value);

        return $this->encodeJson($decoded);
    }

    private function encodeJson(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function ensureConnection(string $connection, string $label): void
    {
        try {
            DB::connection($connection)->getPdo();
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                "无法连接到{$label} `{$connection}`：{$exception->getMessage()}",
                0,
                $exception
            );
        }
    }

    private function ensureCheckpointTable(): void
    {
        static $ensured = false;

        if ($ensured) {
            return;
        }

        DB::connection($this->targetConnection)->statement(
            'CREATE TABLE IF NOT EXISTS catalog_migration_checkpoints (
                migration_name VARCHAR(128) NOT NULL PRIMARY KEY,
                completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                row_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $ensured = true;
    }
}
