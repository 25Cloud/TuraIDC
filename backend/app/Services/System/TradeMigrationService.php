<?php

declare(strict_types=1);

namespace App\Services\System;

use Illuminate\Support\Facades\DB;

class TradeMigrationService
{
    private string $sourceConnection;

    private string $targetConnection;

    public function __construct()
    {
        $this->sourceConnection = (string) config('trade_migration.source_connection', 'mysql');
        $this->targetConnection = (string) config('trade_migration.target_connection', 'mysql');
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
            'SELECT 1 FROM trade_migration_checkpoints WHERE migration_name = ? LIMIT 1',
            [$migrationName]
        );

        return count($rows) > 0;
    }

    public function markMigrationCompleted(string $migrationName, int $rowCount): void
    {
        $this->ensureCheckpointTable();

        DB::connection($this->targetConnection)->statement(
            'INSERT INTO trade_migration_checkpoints (migration_name, completed_at, row_count, created_at)
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
     * @param  array<string, mixed>  $legacyInvoice
     * @param  array<string, mixed>|null  $legacyOrder
     * @return array<string, mixed>
     */
    public function buildInvoicePayload(array $legacyInvoice, ?array $legacyOrder, ?int $targetServiceInstanceId): array
    {
        $status = (int) ($legacyInvoice['status'] ?? 0);
        $orderProductId = isset($legacyOrder['product_id']) && (int) ($legacyOrder['product_id'] ?? 0) > 0
            ? (int) $legacyOrder['product_id']
            : null;
        $invoiceProductId = isset($legacyInvoice['product_id']) && (int) ($legacyInvoice['product_id'] ?? 0) > 0
            ? (int) $legacyInvoice['product_id']
            : null;
        $productId = $invoiceProductId ?? $orderProductId;

        $snapshot = [
            'legacy_invoice_id' => (int) ($legacyInvoice['id'] ?? 0),
            'legacy_order_id' => isset($legacyInvoice['order_id']) ? (int) ($legacyInvoice['order_id'] ?? 0) : null,
            'product_spec_snapshot' => $legacyInvoice['product_spec_snapshot'] ?? ($legacyOrder['product_spec_snapshot'] ?? null),
            'product_type_snapshot' => $legacyInvoice['product_type_snapshot'] ?? ($legacyOrder['product_type_snapshot'] ?? null),
        ];

        return [
            'id' => (int) ($legacyInvoice['id'] ?? 0),
            'invoice_no' => $this->normalizeRequiredString($legacyInvoice['invoice_no'] ?? null, 'INV-'.(int) ($legacyInvoice['id'] ?? 0)),
            'user_id' => (int) ($legacyInvoice['user_id'] ?? 0),
            'scene' => $this->deriveInvoiceScene($legacyInvoice, $legacyOrder),
            'product_id' => $productId,
            'service_instance_id' => $targetServiceInstanceId,
            'coupon_id' => $this->normalizeNullablePositiveInt($legacyInvoice['coupon_id'] ?? null),
            'user_coupon_id' => $this->normalizeNullablePositiveInt($legacyInvoice['user_coupon_id'] ?? null),
            'status' => $status,
            'currency' => 'CNY',
            'subtotal_amount' => $this->normalizeMoney($legacyInvoice['amount'] ?? null),
            'discount_amount' => $this->normalizeMoney($legacyInvoice['discount'] ?? null),
            'total_amount' => $this->normalizeMoney($legacyInvoice['amount'] ?? null),
            'paid_amount' => $this->normalizeMoney($legacyInvoice['paid_amount'] ?? null),
            'billing_cycle' => $this->normalizeNullableString($legacyInvoice['billing_cycle'] ?? ($legacyOrder['billing_cycle'] ?? null)),
            'quantity' => max(1, (int) ($legacyInvoice['quantity'] ?? 1)),
            'due_at' => $this->normalizeDateStringToTimestamp($legacyInvoice['due_date'] ?? null),
            'paid_at' => $this->normalizeDateTimeString($legacyInvoice['paid_at'] ?? null),
            'cancelled_at' => $status === 2 ? ($this->normalizeDateTimeString($legacyInvoice['updated_at'] ?? null) ?? $this->normalizeDateTimeString($legacyInvoice['created_at'] ?? null)) : null,
            'product_snapshot_json' => $this->encodeJson($snapshot),
            'pricing_snapshot_json' => $this->encodeSnapshotValue($legacyInvoice['config_pricing_snapshot'] ?? ($legacyOrder['config_pricing_snapshot'] ?? null)),
            'config_snapshot_json' => $this->encodeSnapshotValue($legacyInvoice['config_snapshot'] ?? ($legacyOrder['config_snapshot'] ?? null)),
            'coupon_snapshot_json' => $this->encodeSnapshotValue($legacyInvoice['coupon_snapshot'] ?? ($legacyOrder['coupon_snapshot'] ?? null), [
                'coupon_code' => $legacyInvoice['coupon_code'] ?? ($legacyOrder['coupon_code'] ?? null),
            ]),
            'trace_id' => $this->normalizeNullableString($legacyInvoice['trace_id'] ?? null),
            'remark' => $this->normalizeNullableString($legacyInvoice['remark'] ?? null),
            'operator' => $this->normalizeNullableString($legacyInvoice['operator'] ?? null),
            'created_at' => $this->normalizeDateTimeString($legacyInvoice['created_at'] ?? null),
            'updated_at' => $this->normalizeDateTimeString($legacyInvoice['updated_at'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $legacyPayment
     * @return array<string, mixed>
     */
    public function buildPaymentPayload(array $legacyPayment): array
    {
        $callbackSummary = $this->decodeJsonArray($legacyPayment['callback_raw'] ?? null);

        return [
            'id' => (int) ($legacyPayment['id'] ?? 0),
            'payment_no' => $this->normalizeRequiredString($legacyPayment['payment_no'] ?? null, 'PAY-'.(int) ($legacyPayment['id'] ?? 0)),
            'invoice_id' => (int) ($legacyPayment['invoice_id'] ?? 0),
            'user_id' => (int) ($legacyPayment['user_id'] ?? 0),
            'gateway' => $this->normalizeRequiredString($legacyPayment['gateway'] ?? null, 'unknown'),
            'gateway_trade_no' => $this->normalizeNullableString($legacyPayment['trade_no'] ?? null),
            'amount' => $this->normalizeMoney($legacyPayment['amount'] ?? null),
            'currency' => 'CNY',
            'status' => (int) ($legacyPayment['status'] ?? 0),
            'paid_at' => $this->normalizeDateTimeString($legacyPayment['paid_at'] ?? null),
            'refunded_at' => (int) ($legacyPayment['status'] ?? 0) === 3
                ? $this->normalizeDateTimeString($callbackSummary['refunded_at'] ?? ($legacyPayment['updated_at'] ?? null))
                : null,
            'callback_summary_json' => $this->encodeJson($callbackSummary),
            'trace_id' => $this->normalizeNullableString($legacyPayment['trace_id'] ?? null),
            'remark' => $this->normalizeNullableString($legacyPayment['remark'] ?? null),
            'operator' => $this->normalizeNullableString($legacyPayment['operator'] ?? null),
            'created_at' => $this->normalizeDateTimeString($legacyPayment['created_at'] ?? null),
            'updated_at' => $this->normalizeDateTimeString($legacyPayment['updated_at'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $legacyCallback
     * @return array<string, mixed>
     */
    public function buildPaymentCallbackPayload(array $legacyCallback): array
    {
        return [
            'id' => (int) ($legacyCallback['id'] ?? 0),
            'payment_id' => (int) ($legacyCallback['payment_id'] ?? 0),
            'callback_type' => $this->normalizeRequiredString($legacyCallback['callback_type'] ?? null, 'payment'),
            'gateway_trade_no' => $this->normalizeNullableString($legacyCallback['gateway_trade_no'] ?? null),
            'payload_json' => $this->encodeSnapshotValue($legacyCallback['payload_json'] ?? null),
            'is_verified' => (int) (($legacyCallback['is_verified'] ?? 0) ? 1 : 0),
            'verify_message' => $this->normalizeNullableString($legacyCallback['remark'] ?? null),
            'received_at' => $this->normalizeDateTimeString($legacyCallback['received_at'] ?? null),
            'trace_id' => $this->normalizeNullableString($legacyCallback['trace_id'] ?? null),
            'created_at' => $this->normalizeDateTimeString($legacyCallback['created_at'] ?? null),
            'updated_at' => $this->normalizeDateTimeString($legacyCallback['updated_at'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $legacyPayment
     * @return array<string, mixed>
     */
    public function buildRefundPayload(array $legacyPayment, int $invoiceUserId): array
    {
        $callbackSummary = $this->decodeJsonArray($legacyPayment['callback_raw'] ?? null);
        $refundNo = $this->normalizeRequiredString(
            $callbackSummary['refund_no'] ?? null,
            'RF-'.(int) ($legacyPayment['id'] ?? 0)
        );
        $refundAmount = $callbackSummary['refund_amount'] ?? $legacyPayment['amount'] ?? 0;
        $refundReason = $callbackSummary['reason'] ?? $legacyPayment['remark'] ?? null;

        return [
            'refund_no' => $refundNo,
            'payment_id' => (int) ($legacyPayment['id'] ?? 0),
            'invoice_id' => (int) ($legacyPayment['invoice_id'] ?? 0),
            'user_id' => $invoiceUserId,
            'amount' => $this->normalizeMoney($refundAmount),
            'status' => 1,
            'reason' => $this->normalizeNullableString($refundReason),
            'gateway_refund_no' => $this->normalizeNullableString($legacyPayment['trade_no'] ?? null),
            'refunded_at' => $this->normalizeDateTimeString($callbackSummary['refunded_at'] ?? ($legacyPayment['updated_at'] ?? null)),
            'trace_id' => $this->normalizeNullableString($legacyPayment['trace_id'] ?? null),
            'remark' => $this->normalizeNullableString($legacyPayment['remark'] ?? null),
            'operator' => $this->normalizeNullableString($legacyPayment['operator'] ?? null),
            'created_at' => $this->normalizeDateTimeString($legacyPayment['created_at'] ?? null),
            'updated_at' => $this->normalizeDateTimeString($legacyPayment['updated_at'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $legacyInvoice
     * @param  array<string, mixed>|null  $legacyOrder
     */
    public function deriveInvoiceScene(array $legacyInvoice, ?array $legacyOrder): string
    {
        $type = trim(strtolower((string) ($legacyOrder['type'] ?? '')));
        $billingCycle = trim(strtolower((string) ($legacyOrder['billing_cycle'] ?? '')));

        if ($type === 'new') {
            return 'purchase';
        }

        if ($type === 'renew') {
            return 'renewal';
        }

        if (str_contains($billingCycle, 'traffic')) {
            return 'traffic';
        }

        if (isset($legacyInvoice['order_id']) && (int) ($legacyInvoice['order_id'] ?? 0) <= 0) {
            return 'manual';
        }

        return 'legacy';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deriveInvoicePayloads(): array
    {
        $ordersById = [];
        foreach ($this->sourceTableRows('orders') as $order) {
            $ordersById[(int) ($order['id'] ?? 0)] = $order;
        }

        [$serviceMapByLegacyServiceId, $serviceMapByLegacyOrderId] = $this->targetServiceInstanceMaps();
        $targetCouponIds = $this->targetIdSet('coupons');
        $targetUserCouponIds = $this->targetIdSet('user_coupons');

        $payloads = [];
        foreach ($this->sourceTableRows('invoices') as $invoice) {
            $orderId = (int) ($invoice['order_id'] ?? 0);
            $legacyOrder = $orderId > 0 ? ($ordersById[$orderId] ?? null) : null;
            $targetServiceInstanceId = $this->resolveTargetServiceInstanceId($invoice, $legacyOrder, $serviceMapByLegacyServiceId, $serviceMapByLegacyOrderId);
            $payload = $this->buildInvoicePayload($invoice, $legacyOrder, $targetServiceInstanceId);

            $legacyCouponId = $this->normalizeNullablePositiveInt($invoice['coupon_id'] ?? null);
            $legacyUserCouponId = $this->normalizeNullablePositiveInt($invoice['user_coupon_id'] ?? null);
            $couponSnapshot = $this->decodeJsonArray($payload['coupon_snapshot_json'] ?? null);

            if ($legacyCouponId !== null && ! isset($targetCouponIds[$legacyCouponId])) {
                $payload['coupon_id'] = null;
                $couponSnapshot['legacy_coupon_id'] = $legacyCouponId;
            }

            if ($legacyUserCouponId !== null && ! isset($targetUserCouponIds[$legacyUserCouponId])) {
                $payload['user_coupon_id'] = null;
                $couponSnapshot['legacy_user_coupon_id'] = $legacyUserCouponId;
            }

            if ($couponSnapshot !== []) {
                $payload['coupon_snapshot_json'] = $this->encodeJson($couponSnapshot);
            }

            $payloads[] = $payload;
        }

        return $payloads;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deriveInvoiceItemPayloads(): array
    {
        $payloads = [];

        foreach ($this->sourceTableRows('invoice_items') as $item) {
            $payloads[] = [
                'id' => (int) ($item['id'] ?? 0),
                'invoice_id' => (int) ($item['invoice_id'] ?? 0),
                'item_type' => $this->normalizeRequiredString($item['item_type'] ?? null, 'normal'),
                'item_code' => null,
                'item_name' => $this->normalizeRequiredString($item['item_name'] ?? null, '历史账单项 #'.(int) ($item['id'] ?? 0)),
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'unit_price' => $this->normalizeMoney($item['unit_price'] ?? null),
                'discount_amount' => $this->normalizeMoney($item['discount_amount'] ?? null),
                'line_amount' => $this->normalizeMoney($item['line_amount'] ?? null),
                'meta_json' => $this->encodeSnapshotValue($item['meta_json'] ?? null),
                'created_at' => $this->normalizeDateTimeString($item['created_at'] ?? null),
                'updated_at' => $this->normalizeDateTimeString($item['updated_at'] ?? null),
            ];
        }

        return $payloads;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function derivePaymentPayloads(): array
    {
        $payloads = [];

        foreach ($this->sourceTableRows('payments') as $payment) {
            $payloads[] = $this->buildPaymentPayload($payment);
        }

        return $payloads;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function derivePaymentCallbackPayloads(): array
    {
        $payloads = [];

        foreach ($this->sourceTableRows('payment_callbacks') as $callback) {
            $payloads[] = $this->buildPaymentCallbackPayload($callback);
        }

        return $payloads;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deriveRefundPayloads(): array
    {
        $invoiceUserMap = [];
        foreach ($this->sourceTableRows('invoices') as $invoice) {
            $invoiceUserMap[(int) ($invoice['id'] ?? 0)] = (int) ($invoice['user_id'] ?? 0);
        }

        $payloads = [];
        foreach ($this->sourceTableRows('payments') as $payment) {
            $status = (int) ($payment['status'] ?? 0);
            $callback = $this->decodeJsonArray($payment['callback_raw'] ?? null);
            $hasRefundFlag = isset($callback['refund_status']) || isset($callback['refund_amount']) || isset($callback['refund_no']);

            if ($status !== 3 && ! $hasRefundFlag) {
                continue;
            }

            $invoiceId = (int) ($payment['invoice_id'] ?? 0);
            $payloads[] = $this->buildRefundPayload(
                legacyPayment: $payment,
                invoiceUserId: $invoiceUserMap[$invoiceId] ?? (int) ($payment['user_id'] ?? 0)
            );
        }

        return $payloads;
    }

    public function sourceSum(string $table, string $column, ?string $where = null): string
    {
        $sql = "SELECT COALESCE(SUM(`{$column}`), 0) AS total FROM `{$table}`";
        if ($where !== null && trim($where) !== '') {
            $sql .= ' WHERE '.$where;
        }

        $rows = $this->sourceQuery($sql);

        return $this->normalizeMoney($rows[0]->total ?? 0);
    }

    public function targetSum(string $table, string $column, ?string $where = null): string
    {
        $sql = "SELECT COALESCE(SUM(`{$column}`), 0) AS total FROM `{$table}`";
        if ($where !== null && trim($where) !== '') {
            $sql .= ' WHERE '.$where;
        }

        $rows = $this->targetQuery($sql);

        return $this->normalizeMoney($rows[0]->total ?? 0);
    }

    /**
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function targetServiceInstanceMaps(): array
    {
        $rows = $this->targetQuery('SELECT id, service_no FROM service_instances ORDER BY id');
        $serviceMapByLegacyServiceId = [];
        $serviceMapByLegacyOrderId = [];

        foreach ($rows as $row) {
            $serviceNo = (string) ($row->service_no ?? '');
            $targetId = (int) ($row->id ?? 0);

            if (preg_match('/^S-(\d+)$/', $serviceNo, $matches) === 1) {
                $serviceMapByLegacyServiceId[(int) $matches[1]] = $targetId;
            }

            if (preg_match('/^R-(\d+)$/', $serviceNo, $matches) === 1) {
                $serviceMapByLegacyOrderId[(int) $matches[1]] = $targetId;
            }
        }

        return [$serviceMapByLegacyServiceId, $serviceMapByLegacyOrderId];
    }

    /**
     * @return array<int, true>
     */
    private function targetIdSet(string $table): array
    {
        $rows = $this->targetQuery("SELECT id FROM `{$table}` ORDER BY id");
        $set = [];

        foreach ($rows as $row) {
            $set[(int) ($row->id ?? 0)] = true;
        }

        return $set;
    }

    /**
     * @param  array<string, mixed>  $legacyInvoice
     * @param  array<string, mixed>|null  $legacyOrder
     * @param  array<int, int>  $serviceMapByLegacyServiceId
     * @param  array<int, int>  $serviceMapByLegacyOrderId
     */
    private function resolveTargetServiceInstanceId(
        array $legacyInvoice,
        ?array $legacyOrder,
        array $serviceMapByLegacyServiceId,
        array $serviceMapByLegacyOrderId
    ): ?int {
        $invoiceServiceId = (int) ($legacyInvoice['service_id'] ?? 0);
        if ($invoiceServiceId > 0 && isset($serviceMapByLegacyServiceId[$invoiceServiceId])) {
            return $serviceMapByLegacyServiceId[$invoiceServiceId];
        }

        $orderServiceId = (int) ($legacyOrder['service_id'] ?? 0);
        if ($orderServiceId > 0 && isset($serviceMapByLegacyServiceId[$orderServiceId])) {
            return $serviceMapByLegacyServiceId[$orderServiceId];
        }

        $orderId = (int) ($legacyInvoice['order_id'] ?? 0);
        if ($orderId > 0 && isset($serviceMapByLegacyOrderId[$orderId])) {
            return $serviceMapByLegacyOrderId[$orderId];
        }

        return null;
    }

    private function normalizeMoney(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function normalizeDateTimeString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeDateStringToTimestamp(mixed $value): ?string
    {
        $normalized = $this->normalizeDateTimeString($value);
        if ($normalized === null) {
            return null;
        }

        return strlen($normalized) === 10 ? $normalized.' 00:00:00' : $normalized;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeRequiredString(mixed $value, string $fallback): string
    {
        return $this->normalizeNullableString($value) ?? $fallback;
    }

    private function normalizeNullablePositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $fallback
     */
    private function encodeSnapshotValue(mixed $value, array $fallback = []): ?string
    {
        $decoded = $this->decodeJsonArray($value);
        if ($decoded !== []) {
            return $this->encodeJson($decoded);
        }

        $filteredFallback = array_filter($fallback, static fn (mixed $item): bool => $item !== null && $item !== '');

        return $filteredFallback === [] ? null : $this->encodeJson($filteredFallback);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value)) {
            return [];
        }

        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === 'null') {
            return [];
        }

        try {
            $decoded = json_decode($trimmed, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodeJson(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function ensureConnection(string $connection, string $label): void
    {
        try {
            DB::connection($connection)->getPdo();
        } catch (\Throwable $exception) {
            throw new \RuntimeException($label.'连接不可用：'.$connection.'，'.$exception->getMessage(), previous: $exception);
        }
    }

    private function ensureCheckpointTable(): void
    {
        DB::connection($this->targetConnection)->statement('
            CREATE TABLE IF NOT EXISTS trade_migration_checkpoints (
                migration_name varchar(100) NOT NULL PRIMARY KEY,
                completed_at timestamp NULL DEFAULT NULL,
                row_count int unsigned NOT NULL DEFAULT 0,
                created_at timestamp NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ');
    }
}
