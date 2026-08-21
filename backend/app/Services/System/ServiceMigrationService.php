<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Constants\BillingCycle;
use App\Constants\ServiceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class ServiceMigrationService
{
    private const BILLING_CYCLE_MONTHS = BillingCycle::RENEWABLE_MONTHS;

    private string $sourceConnection;

    private string $targetConnection;

    private string $legacyServiceDatabase;

    private string $legacyServiceTablePrefix;

    public function __construct()
    {
        $this->sourceConnection = (string) config('service_migration.source_connection', 'mysql');
        $this->targetConnection = (string) config('service_migration.target_connection', 'mysql');
        $this->legacyServiceDatabase = trim((string) config('service_migration.legacy_db_database', ''));
        $this->legacyServiceTablePrefix = trim((string) config('service_migration.legacy_table_prefix', ''));
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
            'SELECT 1 FROM service_migration_checkpoints WHERE migration_name = ? LIMIT 1',
            [$migrationName]
        );

        return count($rows) > 0;
    }

    public function markMigrationCompleted(string $migrationName, int $rowCount): void
    {
        $this->ensureCheckpointTable();

        DB::connection($this->targetConnection)->statement(
            'INSERT INTO service_migration_checkpoints (migration_name, completed_at, row_count, created_at)
             VALUES (?, NOW(), ?, NOW())
             ON DUPLICATE KEY UPDATE completed_at = NOW(), row_count = VALUES(row_count)',
            [$migrationName, $rowCount]
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
     * @return array<int, array<string, mixed>>
     */
    public function sourceOrdersWithProductOrService(): array
    {
        return $this->sourceTableRows('orders');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sourceInvoicesByOrderOrService(): array
    {
        return $this->sourceTableRows('invoices');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sourceTicketsWithServiceReference(): array
    {
        return array_values(array_filter(
            $this->sourceTableRows('tickets'),
            static fn (array $ticket): bool => (int) ($ticket['service_id'] ?? 0) > 0
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function targetProducts(): array
    {
        return array_map(static fn (object $row) => (array) $row, $this->targetQuery(
            'SELECT id, name, product_type FROM products ORDER BY id'
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function targetSupplierProducts(): array
    {
        return array_map(static fn (object $row) => (array) $row, $this->targetQuery(
            'SELECT id, supplier_id, product_id, upstream_product_code FROM supplier_products ORDER BY id'
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function targetUsers(): array
    {
        return array_map(static fn (object $row) => (array) $row, $this->targetQuery(
            'SELECT id FROM users ORDER BY id'
        ));
    }

    /**
     * @param  array<string, mixed>  $legacyOrder
     * @param  array<string, mixed>|null  $product
     * @param  array<string, mixed>|null  $supplierProduct
     * @param  array<string, mixed>|null  $sourceInvoiceHint
     * @return array<string, mixed>
     */
    public function buildServiceInstanceFromOrder(
        array $legacyOrder,
        ?array $product,
        ?array $supplierProduct,
        ?array $sourceInvoiceHint,
        ?array $legacyService,
        string $confidence
    ): array {
        $legacyServiceId = isset($legacyOrder['service_id']) ? (int) ($legacyOrder['service_id'] ?? 0) : 0;
        $orderId = (int) ($legacyOrder['id'] ?? 0);
        $name = $this->normalizeServiceName(
            $product['name'] ?? null,
            $legacyOrder['product_spec_snapshot'] ?? null,
            $legacyServiceId > 0 ? '服务 #'.$legacyServiceId : '重建服务 #'.$orderId
        );

        $billingCycle = $this->normalizeBillingCycle($legacyOrder['billing_cycle'] ?? null);
        $paidAt = $this->normalizeDateTimeString($legacyOrder['paid_at'] ?? null);
        $createdAt = $this->normalizeDateTimeString($legacyOrder['created_at'] ?? null);

        $payload = [
            'user_id' => (int) ($legacyOrder['user_id'] ?? 0),
            'product_id' => (int) (($product['id'] ?? $legacyOrder['product_id'] ?? 0) ?: 0),
            'source_invoice_id' => null,
            'supplier_id' => isset($supplierProduct['supplier_id']) ? (int) $supplierProduct['supplier_id'] : null,
            'supplier_product_id' => isset($supplierProduct['id']) ? (int) $supplierProduct['id'] : null,
            'server_id' => null,
            'service_no' => $legacyServiceId > 0 ? 'S-'.$legacyServiceId : 'R-'.$orderId,
            'name' => $name,
            'instance_identifier' => null,
            'billing_cycle' => $billingCycle,
            'renewal_price' => $this->normalizeMoney($legacyOrder['amount'] ?? null),
            'status' => $this->mapOrderStatusToServiceStatus((int) ($legacyOrder['status'] ?? 0)),
            'auto_renew' => $this->normalizeAutoRenew($legacyOrder['type'] ?? null, $legacyOrder['status'] ?? null),
            'product_snapshot_json' => $this->encodeJson([
                'legacy_order_id' => $orderId,
                'legacy_order_no' => $legacyOrder['order_no'] ?? null,
                'legacy_product_id' => $legacyOrder['product_id'] ?? null,
                'product_name' => $product['name'] ?? null,
                'product_type' => $legacyOrder['product_type_snapshot'] ?? ($product['product_type'] ?? null),
                'product_spec_snapshot' => $legacyOrder['product_spec_snapshot'] ?? null,
            ]),
            'pricing_snapshot_json' => $this->encodeSnapshotValue($legacyOrder['config_pricing_snapshot'] ?? null),
            'config_snapshot_json' => $this->encodeSnapshotValue($legacyOrder['config_snapshot'] ?? null),
            'provision_snapshot_json' => $this->encodeJson([
                'confidence' => $confidence,
                'legacy_service_id' => $legacyServiceId > 0 ? $legacyServiceId : null,
                'legacy_invoice_hint' => $sourceInvoiceHint,
                'legacy_order_type' => $legacyOrder['type'] ?? null,
                'legacy_status' => isset($legacyOrder['status']) ? (int) $legacyOrder['status'] : null,
            ]),
            'remote_meta_json' => null,
            'opened_at' => $paidAt,
            'expires_at' => $this->calculateExpiresAt($billingCycle, $paidAt),
            'suspended_at' => null,
            'terminated_at' => $this->resolveTerminatedAt((int) ($legacyOrder['status'] ?? 0), $paidAt, $createdAt),
            'trace_id' => $this->normalizeNullableString($legacyOrder['trace_id'] ?? null),
            'remark' => $this->buildConfidenceRemark($confidence, $legacyOrder, 'orders'),
            'created_at' => $createdAt,
            'updated_at' => $this->normalizeDateTimeString($legacyOrder['updated_at'] ?? null) ?? $createdAt,
        ];

        if ($legacyService !== null) {
            $payload = $this->mergeLegacyServiceIntoPayload($payload, $legacyService);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $legacyTicket
     * @return array<string, mixed>
     */
    public function buildPlaceholderServiceInstanceFromTicket(array $legacyTicket, int $fallbackProductId): array
    {
        $serviceId = (int) ($legacyTicket['service_id'] ?? 0);
        $createdAt = $this->normalizeDateTimeString($legacyTicket['created_at'] ?? null);

        return [
            'user_id' => (int) ($legacyTicket['user_id'] ?? 0),
            'product_id' => $fallbackProductId,
            'source_invoice_id' => null,
            'supplier_id' => null,
            'supplier_product_id' => null,
            'server_id' => null,
            'service_no' => 'P-'.$serviceId,
            'name' => '占位服务 #'.$serviceId,
            'instance_identifier' => null,
            'billing_cycle' => 'monthly',
            'renewal_price' => '0.00',
            'status' => ServiceStatus::PENDING,
            'auto_renew' => 0,
            'product_snapshot_json' => $this->encodeJson([
                'fallback_product_id' => $fallbackProductId,
                'legacy_ticket_id' => (int) ($legacyTicket['id'] ?? 0),
                'legacy_service_id' => $serviceId,
            ]),
            'pricing_snapshot_json' => null,
            'config_snapshot_json' => null,
            'provision_snapshot_json' => $this->encodeJson([
                'confidence' => 'D',
                'source' => 'tickets',
                'legacy_service_id' => $serviceId,
                'ticket_subject' => $legacyTicket['subject'] ?? null,
            ]),
            'remote_meta_json' => null,
            'opened_at' => null,
            'expires_at' => null,
            'suspended_at' => null,
            'terminated_at' => null,
            'trace_id' => null,
            'remark' => '[D级占位] 从 tickets.service_id='.$serviceId.' 创建，需人工补数',
            'created_at' => $createdAt,
            'updated_at' => $this->normalizeDateTimeString($legacyTicket['updated_at'] ?? null) ?? $createdAt,
        ];
    }

    public function mapOrderStatusToServiceStatus(int $orderStatus): int
    {
        return match ($orderStatus) {
            1, 3 => ServiceStatus::ACTIVE,
            4, 5 => ServiceStatus::CANCELLED,
            default => ServiceStatus::PENDING,
        };
    }

    public function calculateExpiresAt(?string $billingCycle, ?string $paidAt): ?string
    {
        if ($paidAt === null) {
            return null;
        }

        $rawCycle = trim(strtolower((string) ($billingCycle ?? '')));
        $months = self::BILLING_CYCLE_MONTHS[$rawCycle] ?? null;

        if ($months === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($paidAt)->addMonths($months)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deriveServiceInstancePayloads(): array
    {
        $products = [];
        foreach ($this->targetProducts() as $product) {
            $products[(int) $product['id']] = $product;
        }

        $supplierProductsByProductId = [];
        foreach ($this->targetSupplierProducts() as $supplierProduct) {
            $supplierProductsByProductId[(int) $supplierProduct['product_id']] = $supplierProduct;
        }

        // 获取新库有效用户 ID
        $validUserIds = [];
        foreach ($this->targetUsers() as $user) {
            $validUserIds[(int) $user['id']] = true;
        }

        $invoicesByOrderId = [];
        $invoicesByServiceId = [];
        foreach ($this->sourceInvoicesByOrderOrService() as $invoice) {
            $orderId = isset($invoice['order_id']) ? (int) ($invoice['order_id'] ?? 0) : 0;
            if ($orderId > 0) {
                $invoicesByOrderId[$orderId] = $invoice;
            }
            $serviceId = isset($invoice['service_id']) ? (int) ($invoice['service_id'] ?? 0) : 0;
            if ($serviceId > 0) {
                $invoicesByServiceId[$serviceId] = $invoice;
            }
        }

        $payloads = [];
        $seenServiceNos = [];
        $legacyServicesById = [];
        $legacyServicesByOrderId = [];

        foreach ($this->sourceLegacyServices() as $legacyService) {
            $legacyServiceId = isset($legacyService['id']) ? (int) ($legacyService['id'] ?? 0) : 0;
            if ($legacyServiceId > 0 && ! isset($legacyServicesById[$legacyServiceId])) {
                $legacyServicesById[$legacyServiceId] = $legacyService;
            }

            $legacyOrderId = isset($legacyService['order_id']) ? (int) ($legacyService['order_id'] ?? 0) : 0;
            if ($legacyOrderId > 0 && ! isset($legacyServicesByOrderId[$legacyOrderId])) {
                $legacyServicesByOrderId[$legacyOrderId] = $legacyService;
            }
        }

        foreach ($this->sourceOrdersWithProductOrService() as $order) {
            $userId = (int) ($order['user_id'] ?? 0);
            $productId = isset($order['product_id']) ? (int) ($order['product_id'] ?? 0) : 0;
            $serviceId = isset($order['service_id']) ? (int) ($order['service_id'] ?? 0) : 0;
            $status = (int) ($order['status'] ?? 0);
            $type = trim((string) ($order['type'] ?? ''));

            if ($userId <= 0) {
                continue;
            }

            // 验证 user_id 是否存在于新库 users 表
            if (! isset($validUserIds[$userId])) {
                continue;
            }

            if ($productId <= 0 && $serviceId <= 0) {
                continue;
            }

            $product = $products[$productId] ?? null;
            $supplierProduct = $productId > 0 ? ($supplierProductsByProductId[$productId] ?? null) : null;

            if ($serviceId > 0) {
                $confidence = 'A';
            } elseif ($type === 'new' && in_array($status, [1, 3], true) && $product !== null) {
                $confidence = 'C';
            } else {
                continue;
            }

            $legacyService = null;
            if ($serviceId > 0) {
                $legacyService = $legacyServicesById[$serviceId] ?? null;
            }
            if ($legacyService === null) {
                $legacyService = $legacyServicesByOrderId[(int) ($order['id'] ?? 0)] ?? null;
            }

            $payload = $this->buildServiceInstanceFromOrder(
                legacyOrder: $order,
                product: $product,
                supplierProduct: $supplierProduct,
                sourceInvoiceHint: $invoicesByOrderId[(int) ($order['id'] ?? 0)] ?? $invoicesByServiceId[$serviceId] ?? null,
                legacyService: $legacyService,
                confidence: $confidence
            );

            if ($payload['product_id'] <= 0) {
                continue;
            }

            if (isset($seenServiceNos[$payload['service_no']])) {
                continue;
            }

            $seenServiceNos[$payload['service_no']] = true;
            $payloads[] = $payload;
        }

        $fallbackProductId = $this->resolveFallbackProductId($products);

        foreach ($this->sourceTicketsWithServiceReference() as $ticket) {
            $serviceNo = 'P-'.(int) ($ticket['service_id'] ?? 0);
            if (isset($seenServiceNos[$serviceNo]) || $fallbackProductId <= 0) {
                continue;
            }

            $payload = $this->buildPlaceholderServiceInstanceFromTicket($ticket, $fallbackProductId);
            $seenServiceNos[$payload['service_no']] = true;
            $payloads[] = $payload;
        }

        usort($payloads, static fn (array $left, array $right) => strcmp((string) $left['service_no'], (string) $right['service_no']));

        return $payloads;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function sourceLegacyServices(): array
    {
        $databaseName = $this->legacyServiceDatabase !== ''
            ? $this->legacyServiceDatabase
            : (string) DB::connection($this->sourceConnection)->getDatabaseName();
        $tableName = $this->legacyServiceTablePrefix.'services';

        $exists = DB::connection($this->sourceConnection)->select(
            'SELECT 1
             FROM information_schema.tables
             WHERE table_schema = ? AND table_name = ?
             LIMIT 1',
            [$databaseName, $tableName]
        );

        if ($exists === []) {
            return [];
        }

        return array_map(static fn (object $row) => (array) $row, DB::connection($this->sourceConnection)->select(
            'SELECT * FROM `'.$databaseName.'`.`'.$tableName.'` ORDER BY `id` ASC'
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deriveServiceLifecycleLogPayloads(): array
    {
        $serviceMap = $this->targetServiceInstanceMapByLegacyServiceId();
        $payloads = [];

        foreach ($this->sourceTableRows('operation_logs') as $log) {
            $serviceInstanceId = $this->resolveTargetServiceInstanceIdForOperationLog($log, $serviceMap);
            if ($serviceInstanceId === null) {
                continue;
            }

            $action = $this->normalizeLifecycleAction($log['action'] ?? null);
            if ($action === null) {
                continue;
            }

            $context = $this->decodeJsonArray($log['context'] ?? null);
            $payloads[] = [
                'service_instance_id' => $serviceInstanceId,
                'action' => $action,
                'from_status' => $this->resolveLifecycleStatusValue($context['from_status'] ?? null),
                'to_status' => $this->resolveLifecycleStatusValue($context['to_status'] ?? null),
                'reason' => $this->normalizeNullableString($context['reason'] ?? $context['summary'] ?? null),
                'payload_json' => $this->encodeJson($context),
                'operator_type' => $this->normalizeOperatorType($log['user_type'] ?? null),
                'operator_id' => isset($log['user_id']) && (int) $log['user_id'] > 0 ? (int) $log['user_id'] : null,
                'trace_id' => $this->extractTraceId($context),
                'happened_at' => $this->normalizeDateTimeString($log['created_at'] ?? null),
                'created_at' => $this->normalizeDateTimeString($log['created_at'] ?? null),
                'updated_at' => $this->normalizeDateTimeString($log['created_at'] ?? null),
            ];
        }

        return $payloads;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deriveServiceOperationLogPayloads(): array
    {
        $serviceMap = $this->targetServiceInstanceMapByLegacyServiceId();
        $payloads = [];

        foreach ($this->sourceTableRows('operation_logs') as $log) {
            $serviceInstanceId = $this->resolveTargetServiceInstanceIdForOperationLog($log, $serviceMap);
            if ($serviceInstanceId === null) {
                continue;
            }

            $operationType = $this->normalizeOperationType($log['action'] ?? null);
            if ($operationType === null) {
                continue;
            }

            $context = $this->decodeJsonArray($log['context'] ?? null);
            $executedAt = $this->normalizeDateTimeString($log['created_at'] ?? null);
            $payloads[] = [
                'service_instance_id' => $serviceInstanceId,
                'operation_type' => $operationType,
                'request_payload_json' => $this->encodeJson($context),
                'response_payload_json' => null,
                'result_status' => $this->resolveOperationResultStatus($context),
                'provider_request_id' => $this->normalizeNullableString(
                    $context['request_id'] ?? $context['provider_request_id'] ?? null
                ),
                'error_message' => $this->normalizeNullableString($context['error'] ?? $context['error_message'] ?? null),
                'operator_type' => $this->normalizeOperatorType($log['user_type'] ?? null),
                'operator_id' => isset($log['user_id']) && (int) $log['user_id'] > 0 ? (int) $log['user_id'] : null,
                'trace_id' => $this->extractTraceId($context),
                'executed_at' => $executedAt,
                'created_at' => $executedAt,
                'updated_at' => $executedAt,
            ];
        }

        return $payloads;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deriveServiceRemoteSnapshotPayloads(): array
    {
        $serviceMap = $this->targetServiceInstanceMapByLegacyServiceId();
        $payloads = [];

        foreach ($this->sourceTableRows('automation_logs') as $log) {
            $serviceInstanceId = $this->resolveTargetServiceInstanceIdForAutomationLog($log, $serviceMap);
            if ($serviceInstanceId === null) {
                continue;
            }

            $snapshotType = $this->normalizeSnapshotType($log['action'] ?? null, $log['task_key'] ?? null);
            $capturedAt = $this->normalizeDateTimeString($log['executed_at'] ?? $log['created_at'] ?? null);
            $payloads[] = [
                'service_instance_id' => $serviceInstanceId,
                'snapshot_type' => $snapshotType,
                'snapshot_key' => $snapshotType.':'.((int) ($log['id'] ?? 0)),
                'snapshot_payload_json' => $this->encodeJson($this->decodeJsonArray($log['meta'] ?? null) ?: new \stdClass),
                'captured_at' => $capturedAt,
                'created_at' => $capturedAt,
                'updated_at' => $capturedAt,
            ];
        }

        return $payloads;
    }

    private function resolveFallbackProductId(array $products): int
    {
        foreach ($products as $product) {
            if ((int) ($product['id'] ?? 0) > 0) {
                return (int) $product['id'];
            }
        }

        return 0;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $legacyService
     * @return array<string, mixed>
     */
    private function mergeLegacyServiceIntoPayload(array $payload, array $legacyService): array
    {
        $legacyProvision = $this->decodeJsonArray($legacyService['provision_data'] ?? null);
        $legacyLockedPricing = $this->decodeJsonArray($legacyService['locked_pricing'] ?? null);
        $provisionSnapshot = $this->decodeJsonArray($payload['provision_snapshot_json'] ?? null);
        $legacyDomain = $this->normalizeNullableString(
            $legacyService['domain'] ?? $legacyProvision['domain'] ?? $legacyProvision['requested_host'] ?? null
        );

        if ($legacyDomain !== null) {
            $payload['instance_identifier'] = $legacyDomain;
        }

        $legacyName = $this->normalizeNullableString($legacyService['name'] ?? null);
        if ($legacyName !== null) {
            $payload['name'] = $legacyName;
        }

        $legacyBillingCycle = $this->normalizeNullableString($legacyService['billing_cycle'] ?? null);
        if ($legacyBillingCycle !== null) {
            $payload['billing_cycle'] = $this->normalizeBillingCycle($legacyBillingCycle);
        }

        if (isset($legacyService['amount']) && is_numeric($legacyService['amount'])) {
            $payload['renewal_price'] = $this->normalizeMoney($legacyService['amount']);
        }

        if (isset($legacyService['status']) && is_numeric($legacyService['status'])) {
            $payload['status'] = $this->mapLegacyServiceStatusToServiceStatus((int) $legacyService['status']);
        }

        if (array_key_exists('auto_renew', $legacyService)) {
            $payload['auto_renew'] = (int) (((int) ($legacyService['auto_renew'] ?? 0)) === 1);
        }

        if (($payload['source_invoice_id'] ?? null) === null && isset($legacyService['invoice_id']) && (int) $legacyService['invoice_id'] > 0) {
            $payload['source_invoice_id'] = (int) $legacyService['invoice_id'];
        }

        if ($legacyLockedPricing !== []) {
            $payload['pricing_snapshot_json'] = $this->encodeJson($legacyLockedPricing);
        }

        if ($legacyDomain !== null) {
            $legacyProvision['domain'] = $legacyProvision['domain'] ?? $legacyDomain;
            $legacyProvision['requested_host'] = $legacyProvision['requested_host'] ?? $legacyDomain;
        }

        if (
            ! isset($legacyProvision['dedicated_ip'])
            && isset($legacyProvision['dedicatedip'])
            && trim((string) $legacyProvision['dedicatedip']) !== ''
        ) {
            $legacyProvision['dedicated_ip'] = trim((string) $legacyProvision['dedicatedip']);
        }

        if (! isset($legacyProvision['assigned_ips']) || ! is_array($legacyProvision['assigned_ips'])) {
            $legacyProvision['assigned_ips'] = [];
        }

        $payload['provision_snapshot_json'] = $this->encodeJson(array_merge($legacyProvision, $provisionSnapshot));

        $openedAt = $this->normalizeDateTimeString($legacyService['created_at'] ?? null);
        if (($payload['opened_at'] ?? null) === null && $openedAt !== null) {
            $payload['opened_at'] = $openedAt;
        }

        $expiresAt = $this->normalizeDateTimeString($legacyService['expires_at'] ?? null);
        if ($expiresAt !== null) {
            $payload['expires_at'] = $expiresAt;
        }

        if (($payload['terminated_at'] ?? null) === null && (($payload['status'] ?? null) === ServiceStatus::CANCELLED)) {
            $payload['terminated_at'] = $this->normalizeDateTimeString(
                $legacyService['updated_at'] ?? $legacyService['expires_at'] ?? null
            );
        }

        $updatedAt = $this->normalizeDateTimeString($legacyService['updated_at'] ?? null);
        if ($updatedAt !== null) {
            $payload['updated_at'] = $updatedAt;
        }

        return $payload;
    }

    private function mapLegacyServiceStatusToServiceStatus(int $legacyStatus): int
    {
        return match ($legacyStatus) {
            1 => ServiceStatus::ACTIVE,
            2 => ServiceStatus::SUSPENDED,
            3 => ServiceStatus::EXPIRED,
            4 => ServiceStatus::CANCELLED,
            default => ServiceStatus::PENDING,
        };
    }

    /**
     * @return array<int, int>
     */
    private function targetServiceInstanceMapByLegacyServiceId(): array
    {
        $rows = $this->targetQuery(
            'SELECT id, service_no FROM service_instances WHERE service_no LIKE ? OR service_no LIKE ?',
            ['S-%', 'P-%']
        );

        $map = [];
        foreach ($rows as $row) {
            $serviceNo = (string) ($row->service_no ?? '');
            if (! preg_match('/^[SP]-(\d+)$/', $serviceNo, $matches)) {
                continue;
            }

            $map[(int) $matches[1]] = (int) ($row->id ?? 0);
        }

        return $map;
    }

    /**
     * @param  array<string, mixed>  $log
     * @param  array<int, int>  $serviceMap
     */
    private function resolveTargetServiceInstanceIdForOperationLog(array $log, array $serviceMap): ?int
    {
        $context = $this->decodeJsonArray($log['context'] ?? null);
        $serviceId = $this->extractLegacyServiceIdFromContext($context);

        if ($serviceId === null) {
            $serviceId = isset($log['subject_id']) && (int) ($log['subject_id'] ?? 0) > 0
                ? (int) $log['subject_id']
                : null;
        }

        if ($serviceId === null) {
            return null;
        }

        return $serviceMap[$serviceId] ?? null;
    }

    /**
     * @param  array<string, mixed>  $log
     * @param  array<int, int>  $serviceMap
     */
    private function resolveTargetServiceInstanceIdForAutomationLog(array $log, array $serviceMap): ?int
    {
        $objectType = trim(strtolower((string) ($log['object_type'] ?? '')));
        if ($objectType !== 'service' && $objectType !== 'services') {
            return null;
        }

        $serviceId = isset($log['object_id']) && (int) ($log['object_id'] ?? 0) > 0
            ? (int) $log['object_id']
            : null;

        if ($serviceId === null) {
            return null;
        }

        return $serviceMap[$serviceId] ?? null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function extractLegacyServiceIdFromContext(array $context): ?int
    {
        foreach (['service_id', 'renew_service_id'] as $key) {
            if (isset($context[$key]) && (int) ($context[$key] ?? 0) > 0) {
                return (int) $context[$key];
            }
        }

        return null;
    }

    private function normalizeLifecycleAction(mixed $value): ?string
    {
        $normalized = trim(strtolower((string) ($value ?? '')));
        if ($normalized === '') {
            return null;
        }

        return match ($normalized) {
            'create', 'created', 'provision', 'provisioned', 'activate', 'activated' => 'activate',
            'suspend', 'suspended' => 'suspend',
            'unsuspend', 'unsuspended' => 'unsuspend',
            'terminate', 'terminated', 'cancel', 'cancelled' => 'terminate',
            'renew', 'renewed' => 'renew',
            'upgrade', 'upgraded' => 'upgrade',
            'downgrade', 'downgraded' => 'downgrade',
            'open', 'opened' => 'open',
            'close', 'closed' => 'close',
            default => null,
        };
    }

    private function normalizeOperationType(mixed $value): ?string
    {
        $normalized = trim(strtolower((string) ($value ?? '')));
        if ($normalized === '') {
            return null;
        }

        return match (true) {
            str_contains($normalized, 'provision') => 'provision',
            str_contains($normalized, 'suspend') => 'suspend',
            str_contains($normalized, 'terminate') => 'terminate',
            str_contains($normalized, 'renew') => 'renew',
            str_contains($normalized, 'vnc') => 'vnc',
            str_contains($normalized, 'reboot') => 'reboot',
            str_contains($normalized, 'reinstall') => 'reinstall',
            str_contains($normalized, 'bandwidth') => 'bandwidth',
            str_contains($normalized, 'service') => 'service_action',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function resolveOperationResultStatus(array $context): string
    {
        $status = $context['status'] ?? null;
        if (is_numeric($status)) {
            return (int) $status >= 400 ? 'failed' : 'success';
        }

        $normalized = trim(strtolower((string) $status));
        if ($normalized === '') {
            return 'success';
        }

        return in_array($normalized, ['fail', 'failed', 'error'], true) ? 'failed' : 'success';
    }

    private function normalizeOperatorType(mixed $value): ?string
    {
        $normalized = trim(strtolower((string) ($value ?? '')));

        return match ($normalized) {
            'admin' => 'admin',
            'client', 'user' => 'user',
            'system' => 'system',
            default => $normalized !== '' ? 'system' : null,
        };
    }

    private function extractTraceId(array $context): ?string
    {
        return $this->normalizeNullableString($context['trace_id'] ?? $context['request_id'] ?? null);
    }

    private function resolveLifecycleStatusValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $normalized = trim(strtolower((string) $value));

        return match ($normalized) {
            'pending' => ServiceStatus::PENDING,
            'active' => ServiceStatus::ACTIVE,
            'suspended' => ServiceStatus::SUSPENDED,
            'expired' => ServiceStatus::EXPIRED,
            'cancelled', 'terminated' => ServiceStatus::CANCELLED,
            default => null,
        };
    }

    private function normalizeSnapshotType(mixed $action, mixed $taskKey): string
    {
        $combined = trim(strtolower((string) ($action ?? ''))).' '.trim(strtolower((string) ($taskKey ?? '')));

        return match (true) {
            str_contains($combined, 'vnc') => 'vnc_info',
            str_contains($combined, 'bandwidth') => 'bandwidth',
            str_contains($combined, 'panel') => 'panel_status',
            default => 'automation_meta',
        };
    }

    /**
     * @return array<string, mixed>
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

    private function normalizeServiceName(mixed $preferredName, mixed $snapshotName, string $fallback): string
    {
        $preferred = $this->normalizeNullableString($preferredName);
        if ($preferred !== null) {
            return $preferred;
        }

        $snapshot = $this->normalizeNullableString($snapshotName);
        if ($snapshot !== null) {
            return $snapshot;
        }

        return $fallback;
    }

    private function normalizeBillingCycle(mixed $value): string
    {
        $normalized = trim(strtolower((string) ($value ?? '')));

        return match ($normalized) {
            'monthly', 'quarterly', 'semiannually', 'annually' => $normalized,
            default => 'monthly',
        };
    }

    private function normalizeAutoRenew(mixed $orderType, mixed $orderStatus): int
    {
        $type = trim(strtolower((string) ($orderType ?? '')));
        $status = (int) ($orderStatus ?? 0);

        if ($type === 'renew' && in_array($status, [1, 3], true)) {
            return 1;
        }

        return 0;
    }

    private function resolveTerminatedAt(int $orderStatus, ?string $paidAt, ?string $createdAt): ?string
    {
        if (! in_array($orderStatus, [4, 5], true)) {
            return null;
        }

        return $paidAt ?? $createdAt;
    }

    /**
     * @param  array<string, mixed>  $legacyRow
     */
    private function buildConfidenceRemark(string $confidence, array $legacyRow, string $source): string
    {
        $serviceId = isset($legacyRow['service_id']) ? (int) ($legacyRow['service_id'] ?? 0) : 0;
        $id = (int) ($legacyRow['id'] ?? 0);

        return match ($confidence) {
            'A' => '[A级重建] 从 '.$source.'.service_id='.$serviceId.' 重建',
            'B' => '[B级重建] 从 '.$source.'.service_id='.$serviceId.' 重建',
            'C' => '[C级推导] 从 '.$source.'.id='.$id.' 推导重建，无显式 service_id',
            default => '[D级占位] 从 '.$source.'.id='.$id.' 创建',
        };
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

    private function normalizeDateTimeString(mixed $value): ?string
    {
        $normalized = $this->normalizeNullableString($value);
        if ($normalized === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($normalized)->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function encodeSnapshotValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }

            $decoded = json_decode($trimmed, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $this->encodeJson($decoded);
            }

            return $this->encodeJson(['raw' => $trimmed]);
        }

        return $this->encodeJson($value);
    }

    private function encodeJson(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) && $value === []) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null;
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
            'CREATE TABLE IF NOT EXISTS service_migration_checkpoints (
                migration_name VARCHAR(128) NOT NULL PRIMARY KEY,
                completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                row_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $ensured = true;
    }
}
