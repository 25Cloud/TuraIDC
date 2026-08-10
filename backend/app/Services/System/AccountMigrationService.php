<?php

declare(strict_types=1);

namespace App\Services\System;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccountMigrationService
{
    private string $sourceConnection;

    private string $targetConnection;

    public function __construct()
    {
        $this->sourceConnection = (string) config('account_migration.source_connection', 'mysql');
        $this->targetConnection = (string) config('account_migration.target_connection', 'mysql');
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
            'SELECT 1 FROM account_migration_checkpoints WHERE migration_name = ? LIMIT 1',
            [$migrationName]
        );

        return count($rows) > 0;
    }

    public function markMigrationCompleted(string $migrationName, int $rowCount): void
    {
        $this->ensureCheckpointTable();

        DB::connection($this->targetConnection)->statement(
            'INSERT INTO account_migration_checkpoints (migration_name, completed_at, row_count, created_at)
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
     * @return array<int, array<string, mixed>>
     */
    public function sourceTableRows(string $table, string $orderBy = 'id'): array
    {
        return array_map(static fn (object $row) => (array) $row, $this->sourceQuery(
            "SELECT * FROM `{$table}` ORDER BY `{$orderBy}` ASC"
        ));
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
     * @param  array<string, mixed>  $legacyReward
     * @return array<string, mixed>
     */
    public function buildReferralRewardPayload(array $legacyReward, ?int $targetInvoiceId): array
    {
        return [
            'id' => (int) ($legacyReward['id'] ?? 0),
            'referrer_user_id' => (int) ($legacyReward['referrer_user_id'] ?? 0),
            'referred_user_id' => (int) ($legacyReward['referred_user_id'] ?? 0),
            'order_id' => $this->normalizeNullablePositiveInt($legacyReward['order_id'] ?? null),
            'invoice_id' => $targetInvoiceId,
            'source_invoice_id' => $targetInvoiceId,
            'product_id' => $this->normalizeNullablePositiveInt($legacyReward['product_id'] ?? null),
            'order_amount' => $this->normalizeMoney($legacyReward['order_amount'] ?? null),
            'reward_rate' => $this->normalizeMoney($legacyReward['reward_rate'] ?? null),
            'reward_amount' => $this->normalizeMoney($legacyReward['reward_amount'] ?? null),
            'available_at' => $this->normalizeDateTimeString($legacyReward['available_at'] ?? null),
            'released_at' => $this->normalizeDateTimeString($legacyReward['released_at'] ?? null),
            'status' => (int) ($legacyReward['status'] ?? 0),
            'trace_id' => $this->normalizeNullableString($legacyReward['trace_id'] ?? null),
            'remark' => $this->normalizeNullableString($legacyReward['remark'] ?? null),
            'created_at' => $this->normalizeDateTimeString($legacyReward['created_at'] ?? null),
            'updated_at' => $this->normalizeDateTimeString($legacyReward['updated_at'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $legacyUser
     * @return array<string, mixed>
     */
    public function buildReferralRelationPayload(array $legacyUser): array
    {
        $boundAt = $this->normalizeDateTimeString($legacyUser['referred_at'] ?? null)
            ?? $this->normalizeDateTimeString($legacyUser['created_at'] ?? null)
            ?? date('Y-m-d H:i:s');

        return [
            'referrer_user_id' => (int) ($legacyUser['referrer_user_id'] ?? 0),
            'referred_user_id' => (int) ($legacyUser['id'] ?? 0),
            'referral_code_snapshot' => $this->normalizeNullableString($legacyUser['referral_code'] ?? null),
            'bound_at' => $boundAt,
            'created_at' => $this->normalizeDateTimeString($legacyUser['created_at'] ?? null) ?? $boundAt,
            'updated_at' => $this->normalizeDateTimeString($legacyUser['updated_at'] ?? null) ?? $boundAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $legacyWithdrawal
     * @return array<string, mixed>
     */
    public function buildWithdrawalPayload(array $legacyWithdrawal): array
    {
        $withdrawalId = (int) ($legacyWithdrawal['id'] ?? 0);
        $method = $this->normalizeRequiredString($legacyWithdrawal['method'] ?? null, 'alipay');
        $accountName = $this->normalizeRequiredString($legacyWithdrawal['account_name'] ?? null, '');
        $accountNo = $this->normalizeRequiredString($legacyWithdrawal['account_no'] ?? null, '');

        return [
            'id' => $withdrawalId,
            'withdrawal_no' => 'WD'.str_pad((string) $withdrawalId, 8, '0', STR_PAD_LEFT),
            'user_id' => (int) ($legacyWithdrawal['user_id'] ?? 0),
            'account_type' => 'referral_withdrawing',
            'amount' => $this->normalizeMoney($legacyWithdrawal['amount'] ?? null),
            'status' => (int) ($legacyWithdrawal['status'] ?? 0),
            'method' => $method,
            'account_name' => $accountName,
            'account_no' => $accountNo,
            'account_snapshot_json' => json_encode([
                'method' => $method,
                'account_name' => $accountName,
                'account_no' => $accountNo,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'processed_at' => $this->normalizeDateTimeString($legacyWithdrawal['processed_at'] ?? null),
            'rejected_reason' => $this->normalizeNullableString($legacyWithdrawal['remark'] ?? null),
            'operator_id' => null,
            'trace_id' => $this->normalizeNullableString($legacyWithdrawal['trace_id'] ?? null),
            'created_at' => $this->normalizeDateTimeString($legacyWithdrawal['created_at'] ?? null),
            'updated_at' => $this->normalizeDateTimeString($legacyWithdrawal['updated_at'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $balanceLog
     * @return array<string, mixed>
     */
    public function buildCashLedgerPayload(array $balanceLog): array
    {
        $changeAmount = round((float) ($balanceLog['change_amount'] ?? 0), 2);
        $balanceAfter = round((float) ($balanceLog['balance_after'] ?? 0), 2);
        $balanceBefore = round($balanceAfter - $changeAmount, 2);

        return [
            'id' => (int) ($balanceLog['id'] ?? 0),
            'user_id' => (int) ($balanceLog['user_id'] ?? 0),
            'account_type' => 'cash',
            'business_type' => $this->normalizeRequiredString($balanceLog['event_type'] ?? null, 'legacy_balance'),
            'direction' => $changeAmount >= 0 ? 'credit' : 'debit',
            'amount' => $this->normalizeMoney(abs($changeAmount)),
            'balance_before' => $this->normalizeMoney($balanceBefore),
            'balance_after' => $this->normalizeMoney($balanceAfter),
            'source_type' => 'balance_log',
            'source_id' => $this->normalizeNullablePositiveInt($balanceLog['reference_id'] ?? null),
            'operator_type' => 'system',
            'operator_id' => null,
            'remark' => $this->normalizeNullableString($balanceLog['remark'] ?? null),
            'trace_id' => null,
            'happened_at' => $this->normalizeDateTimeString($balanceLog['created_at'] ?? null),
            'created_at' => $this->normalizeDateTimeString($balanceLog['created_at'] ?? null),
            'updated_at' => $this->normalizeDateTimeString($balanceLog['created_at'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $referralLog
     * @return array<string, mixed>
     */
    public function buildReferralLedgerPayload(array $referralLog): array
    {
        $eventType = $this->normalizeRequiredString($referralLog['event_type'] ?? null, 'legacy_referral');
        $changeAmount = round((float) ($referralLog['change_amount'] ?? 0), 2);
        $balanceAfter = $this->resolveReferralLedgerBalanceAfter($eventType, $referralLog);
        $balanceBefore = round($balanceAfter - $changeAmount, 2);

        return [
            'id' => 1000000 + (int) ($referralLog['id'] ?? 0),
            'user_id' => (int) ($referralLog['user_id'] ?? 0),
            'account_type' => $this->mapReferralEventToAccountType($eventType),
            'business_type' => $eventType,
            'direction' => $changeAmount >= 0 ? 'credit' : 'debit',
            'amount' => $this->normalizeMoney(abs($changeAmount)),
            'balance_before' => $this->normalizeMoney($balanceBefore),
            'balance_after' => $this->normalizeMoney($balanceAfter),
            'source_type' => 'referral_account_log',
            'source_id' => $this->normalizeNullablePositiveInt($referralLog['reference_id'] ?? null),
            'operator_type' => 'system',
            'operator_id' => is_numeric($referralLog['operator'] ?? null)
                ? (int) $referralLog['operator']
                : null,
            'remark' => $this->normalizeNullableString($referralLog['remark'] ?? null),
            'trace_id' => $this->normalizeNullableString($referralLog['trace_id'] ?? null),
            'happened_at' => $this->normalizeDateTimeString($referralLog['created_at'] ?? null),
            'created_at' => $this->normalizeDateTimeString($referralLog['created_at'] ?? null),
            'updated_at' => $this->normalizeDateTimeString($referralLog['created_at'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildOpeningBalanceLedgerPayload(
        int $userId,
        string $accountType,
        mixed $openingBalance,
        string $happenedAt
    ): array {
        $normalizedBalance = $this->normalizeMoney($openingBalance);

        return [
            'id' => $this->buildOpeningLedgerId($userId, $accountType),
            'user_id' => $userId,
            'account_type' => $accountType,
            'business_type' => 'opening_balance',
            'direction' => ((float) $normalizedBalance) >= 0 ? 'credit' : 'debit',
            'amount' => $this->normalizeMoney(abs((float) $normalizedBalance)),
            'balance_before' => '0.00',
            'balance_after' => $normalizedBalance,
            'source_type' => 'account_migration',
            'source_id' => null,
            'operator_type' => 'system',
            'operator_id' => null,
            'remark' => '账户域迁移期初余额',
            'trace_id' => null,
            'happened_at' => $happenedAt,
            'created_at' => $happenedAt,
            'updated_at' => $happenedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $userAccount
     * @return array<int, array<string, mixed>>
     */
    public function buildBalanceSnapshotPayloads(array $userAccount, string $snapshotDate): array
    {
        $userId = (int) ($userAccount['user_id'] ?? 0);
        $pendingWithdrawalBalance = $this->resolvePendingWithdrawalBalance($userAccount);

        return [
            [
                'user_id' => $userId,
                'account_type' => 'cash',
                'available_balance' => $this->normalizeMoney($userAccount['cash_balance'] ?? null),
                'frozen_balance' => '0.00',
                'snapshot_date' => $snapshotDate,
                'created_at' => $snapshotDate.' 00:00:00',
                'updated_at' => $snapshotDate.' 00:00:00',
            ],
            [
                'user_id' => $userId,
                'account_type' => 'referral_frozen',
                'available_balance' => '0.00',
                'frozen_balance' => $this->normalizeMoney($userAccount['referral_frozen_balance'] ?? null),
                'snapshot_date' => $snapshotDate,
                'created_at' => $snapshotDate.' 00:00:00',
                'updated_at' => $snapshotDate.' 00:00:00',
            ],
            [
                'user_id' => $userId,
                'account_type' => 'referral_available',
                'available_balance' => $this->normalizeMoney($userAccount['referral_available_balance'] ?? null),
                'frozen_balance' => '0.00',
                'snapshot_date' => $snapshotDate,
                'created_at' => $snapshotDate.' 00:00:00',
                'updated_at' => $snapshotDate.' 00:00:00',
            ],
            [
                'user_id' => $userId,
                'account_type' => 'referral_withdrawing',
                'available_balance' => '0.00',
                'frozen_balance' => $pendingWithdrawalBalance,
                'snapshot_date' => $snapshotDate,
                'created_at' => $snapshotDate.' 00:00:00',
                'updated_at' => $snapshotDate.' 00:00:00',
            ],
            [
                'user_id' => $userId,
                'account_type' => 'referral_withdrawn',
                'available_balance' => $this->normalizeMoney($userAccount['referral_withdrawn_balance'] ?? null),
                'frozen_balance' => '0.00',
                'snapshot_date' => $snapshotDate,
                'created_at' => $snapshotDate.' 00:00:00',
                'updated_at' => $snapshotDate.' 00:00:00',
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deriveReferralRewardPayloads(): array
    {
        $sourceInvoiceMap = $this->sourceInvoiceMapByOrderId();
        $targetInvoiceMapByLegacyId = $this->targetInvoiceMapByLegacyInvoiceId();
        $targetInvoiceMapByLegacyOrderId = $this->targetInvoiceMapByLegacyOrderId();
        $v2ProductIds = $this->collectTargetProductIds();
        $payloads = [];

        foreach ($this->sourceTableRows('referral_rewards') as $reward) {
            $targetInvoiceId = $this->resolveTargetInvoiceIdForReward(
                $reward,
                $sourceInvoiceMap,
                $targetInvoiceMapByLegacyId,
                $targetInvoiceMapByLegacyOrderId
            );

            $payload = $this->buildReferralRewardPayload($reward, $targetInvoiceId);

            if ($payload['product_id'] !== null && ! isset($v2ProductIds[$payload['product_id']])) {
                $payload['product_id'] = null;
            }

            $payloads[] = $payload;
        }

        return $payloads;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deriveReferralRelationPayloads(): array
    {
        $payloads = [];

        foreach ($this->sourceTableRows('users') as $user) {
            $referrerUserId = (int) ($user['referrer_user_id'] ?? 0);
            $referredUserId = (int) ($user['id'] ?? 0);

            if ($referrerUserId <= 0 || $referredUserId <= 0 || $referrerUserId === $referredUserId) {
                continue;
            }

            if (($user['deleted_at'] ?? null) !== null) {
                continue;
            }

            $payloads[] = $this->buildReferralRelationPayload($user);
        }

        return $payloads;
    }

    private function collectTargetProductIds(): array
    {
        $ids = [];
        foreach ($this->targetQuery('SELECT id FROM products ORDER BY id ASC') as $row) {
            $ids[(int) $row->id] = true;
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $reward
     * @param  array<int, int>  $sourceInvoiceMap
     * @param  array<int, int>  $targetInvoiceMapByLegacyId
     * @param  array<int, int>  $targetInvoiceMapByLegacyOrderId
     */
    private function resolveTargetInvoiceIdForReward(
        array $reward,
        array $sourceInvoiceMap,
        array $targetInvoiceMapByLegacyId,
        array $targetInvoiceMapByLegacyOrderId
    ): ?int {
        $legacyOrderId = (int) ($reward['order_id'] ?? 0);

        // 路径 1：旧 order_id -> 旧 invoice_id -> 新 invoice_id（通过 legacy_invoice_id）
        $legacyInvoiceId = $sourceInvoiceMap[$legacyOrderId] ?? null;
        if ($legacyInvoiceId !== null && isset($targetInvoiceMapByLegacyId[$legacyInvoiceId])) {
            return $targetInvoiceMapByLegacyId[$legacyInvoiceId];
        }

        // 路径 2：旧 order_id -> 新 invoice_id（通过 product_snapshot_json.legacy_order_id）
        if (isset($targetInvoiceMapByLegacyOrderId[$legacyOrderId])) {
            return $targetInvoiceMapByLegacyOrderId[$legacyOrderId];
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deriveWithdrawalPayloads(): array
    {
        return array_map(
            fn (array $row) => $this->buildWithdrawalPayload($row),
            $this->sourceTableRows('referral_withdrawals')
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deriveAccountLedgerPayloads(): array
    {
        $payloads = $this->deriveOpeningBalanceLedgerPayloads();

        foreach ($this->sourceTableRows('balance_logs') as $balanceLog) {
            $payloads[] = $this->buildCashLedgerPayload($balanceLog);
        }

        foreach ($this->sourceTableRows('referral_account_logs') as $referralLog) {
            $payloads[] = $this->buildReferralLedgerPayload($referralLog);
        }

        return $payloads;
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     * @param  array<int, true>  $existingUserIds
     * @return array{
     *     kept: array<int, array<string, mixed>>,
     *     skipped_row_ids: list<int>,
     *     skipped_user_ids: list<int>
     * }
     */
    public function partitionPayloadsByUserSet(array $payloads, array $existingUserIds): array
    {
        $kept = [];
        $skippedRowIds = [];
        $skippedUserIds = [];

        foreach ($payloads as $payload) {
            $userId = (int) ($payload['user_id'] ?? 0);

            if ($userId > 0 && isset($existingUserIds[$userId])) {
                $kept[] = $payload;

                continue;
            }

            $rowId = (int) ($payload['id'] ?? 0);
            if ($rowId > 0) {
                $skippedRowIds[] = $rowId;
            }

            if ($userId > 0 && ! in_array($userId, $skippedUserIds, true)) {
                $skippedUserIds[] = $userId;
            }
        }

        sort($skippedRowIds);
        sort($skippedUserIds);

        return [
            'kept' => $kept,
            'skipped_row_ids' => $skippedRowIds,
            'skipped_user_ids' => $skippedUserIds,
        ];
    }

    /**
     * @return array{
     *     kept: array<int, array<string, mixed>>,
     *     skipped_row_ids: list<int>,
     *     skipped_user_ids: list<int>
     * }
     */
    public function deriveWithdrawalPayloadPartition(): array
    {
        return $this->partitionPayloadsByUserSet(
            $this->deriveWithdrawalPayloads(),
            $this->targetIdSet('users')
        );
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     * @param  array<int, true>  $existingUserIds
     * @return array{
     *     kept: array<int, array<string, mixed>>,
     *     skipped_referred_user_ids: list<int>,
     *     skipped_referrer_user_ids: list<int>
     * }
     */
    public function partitionReferralRelationsByUserSet(array $payloads, array $existingUserIds): array
    {
        $kept = [];
        $skippedReferredUserIds = [];
        $skippedReferrerUserIds = [];

        foreach ($payloads as $payload) {
            $referrerUserId = (int) ($payload['referrer_user_id'] ?? 0);
            $referredUserId = (int) ($payload['referred_user_id'] ?? 0);

            $hasReferrer = $referrerUserId > 0 && isset($existingUserIds[$referrerUserId]);
            $hasReferred = $referredUserId > 0 && isset($existingUserIds[$referredUserId]);

            if ($hasReferrer && $hasReferred) {
                $kept[] = $payload;

                continue;
            }

            if (! $hasReferred && $referredUserId > 0 && ! in_array($referredUserId, $skippedReferredUserIds, true)) {
                $skippedReferredUserIds[] = $referredUserId;
            }

            if (! $hasReferrer && $referrerUserId > 0 && ! in_array($referrerUserId, $skippedReferrerUserIds, true)) {
                $skippedReferrerUserIds[] = $referrerUserId;
            }
        }

        sort($skippedReferredUserIds);
        sort($skippedReferrerUserIds);

        return [
            'kept' => $kept,
            'skipped_referred_user_ids' => $skippedReferredUserIds,
            'skipped_referrer_user_ids' => $skippedReferrerUserIds,
        ];
    }

    /**
     * @return array{
     *     kept: array<int, array<string, mixed>>,
     *     skipped_row_ids: list<int>,
     *     skipped_user_ids: list<int>
     * }
     */
    public function deriveAccountLedgerPayloadPartition(): array
    {
        return $this->partitionPayloadsByUserSet(
            $this->deriveAccountLedgerPayloads(),
            $this->targetIdSet('users')
        );
    }

    /**
     * @return array{
     *     kept: array<int, array<string, mixed>>,
     *     skipped_referred_user_ids: list<int>,
     *     skipped_referrer_user_ids: list<int>
     * }
     */
    public function deriveReferralRelationPayloadPartition(): array
    {
        return $this->partitionReferralRelationsByUserSet(
            $this->deriveReferralRelationPayloads(),
            $this->targetIdSet('users')
        );
    }

    /**
     * @return array{total: int, derived: int, skipped_missing_legacy_invoice: int, skipped_missing_target_invoice: int, resolved_by_legacy_order_id: int}
     */
    public function referralRewardMigrationStats(): array
    {
        $sourceInvoiceMap = $this->sourceInvoiceMapByOrderId();
        $targetInvoiceMapByLegacyId = $this->targetInvoiceMapByLegacyInvoiceId();
        $targetInvoiceMapByLegacyOrderId = $this->targetInvoiceMapByLegacyOrderId();
        $total = 0;
        $derived = 0;
        $skippedMissingLegacyInvoice = 0;
        $skippedMissingTargetInvoice = 0;
        $resolvedByLegacyOrderId = 0;

        foreach ($this->sourceTableRows('referral_rewards') as $reward) {
            $total++;
            $legacyOrderId = (int) ($reward['order_id'] ?? 0);

            // 路径 1：通过 legacy_invoice_id
            $legacyInvoiceId = $sourceInvoiceMap[$legacyOrderId] ?? null;
            if ($legacyInvoiceId !== null && isset($targetInvoiceMapByLegacyId[$legacyInvoiceId])) {
                $derived++;

                continue;
            }

            // 路径 2：通过 legacy_order_id
            if (isset($targetInvoiceMapByLegacyOrderId[$legacyOrderId])) {
                $derived++;
                $resolvedByLegacyOrderId++;

                continue;
            }

            if ($legacyInvoiceId === null) {
                $skippedMissingLegacyInvoice++;
            } else {
                $skippedMissingTargetInvoice++;
            }
        }

        return [
            'total' => $total,
            'derived' => $derived,
            'skipped_missing_legacy_invoice' => $skippedMissingLegacyInvoice,
            'skipped_missing_target_invoice' => $skippedMissingTargetInvoice,
            'resolved_by_legacy_order_id' => $resolvedByLegacyOrderId,
        ];
    }

    /**
     * @return array{total: int, derived: int, skipped_invalid_referrer: int, skipped_self_referral: int, skipped_deleted_users: int, skipped_missing_target_users: int}
     */
    public function referralRelationMigrationStats(): array
    {
        $total = 0;
        $skippedInvalidReferrer = 0;
        $skippedSelfReferral = 0;
        $skippedDeletedUsers = 0;

        foreach ($this->sourceTableRows('users') as $user) {
            $referrerUserId = (int) ($user['referrer_user_id'] ?? 0);
            $referredUserId = (int) ($user['id'] ?? 0);

            if ($referrerUserId <= 0) {
                $skippedInvalidReferrer++;

                continue;
            }

            if ($referrerUserId === $referredUserId) {
                $skippedSelfReferral++;

                continue;
            }

            if (($user['deleted_at'] ?? null) !== null) {
                $skippedDeletedUsers++;

                continue;
            }

            $total++;
        }

        $partition = $this->deriveReferralRelationPayloadPartition();

        return [
            'total' => $total,
            'derived' => count($partition['kept']),
            'skipped_invalid_referrer' => $skippedInvalidReferrer,
            'skipped_self_referral' => $skippedSelfReferral,
            'skipped_deleted_users' => $skippedDeletedUsers,
            'skipped_missing_target_users' => count($partition['skipped_referred_user_ids']) + count($partition['skipped_referrer_user_ids']),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deriveBalanceSnapshotPayloads(?string $snapshotDate = null): array
    {
        $snapshotDate = $snapshotDate ?: date('Y-m-d');
        $payloads = [];

        foreach ($this->targetTableRows('user_accounts', 'user_id') as $userAccount) {
            foreach ($this->buildBalanceSnapshotPayloads($userAccount, $snapshotDate) as $payload) {
                $payloads[] = $payload;
            }
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
     * @return array<int, array<string, mixed>>
     */
    public function targetTableRows(string $table, string $orderBy = 'id'): array
    {
        return array_map(static fn (object $row) => (array) $row, $this->targetQuery(
            "SELECT * FROM `{$table}` ORDER BY `{$orderBy}` ASC"
        ));
    }

    /**
     * @return array<int, int>
     */
    public function sourceInvoiceMapByOrderId(): array
    {
        $map = [];

        foreach ($this->sourceQuery('SELECT id, order_id FROM invoices WHERE order_id IS NOT NULL ORDER BY id ASC') as $row) {
            $orderId = (int) ($row->order_id ?? 0);
            $invoiceId = (int) ($row->id ?? 0);

            if ($orderId > 0 && $invoiceId > 0 && ! isset($map[$orderId])) {
                $map[$orderId] = $invoiceId;
            }
        }

        return $map;
    }

    /**
     * @return array<int, int>
     */
    public function targetInvoiceMapByLegacyInvoiceId(): array
    {
        $map = [];

        foreach ($this->targetInvoiceSnapshotRows() as $row) {
            $targetInvoiceId = (int) ($row->id ?? 0);
            $snapshot = json_decode((string) ($row->config_snapshot ?? ''), true);
            $legacyInvoiceId = is_array($snapshot) && isset($snapshot['legacy_invoice_id'])
                ? (int) $snapshot['legacy_invoice_id']
                : 0;

            if ($targetInvoiceId > 0 && $legacyInvoiceId > 0 && ! isset($map[$legacyInvoiceId])) {
                $map[$legacyInvoiceId] = $targetInvoiceId;
            }
        }

        return $map;
    }

    /**
     * @return array<int, int>
     */
    public function targetInvoiceMapByLegacyOrderId(): array
    {
        $map = [];

        // 优先通过 legacy_order_id 映射（这是最可靠的方式）
        foreach ($this->targetInvoiceSnapshotRows() as $row) {
            $targetInvoiceId = (int) ($row->id ?? 0);
            $snapshot = json_decode((string) ($row->config_snapshot ?? ''), true);
            $legacyOrderId = is_array($snapshot) && isset($snapshot['legacy_order_id'])
                ? (int) $snapshot['legacy_order_id']
                : 0;

            if ($targetInvoiceId > 0 && $legacyOrderId > 0 && ! isset($map[$legacyOrderId])) {
                $map[$legacyOrderId] = $targetInvoiceId;
            }
        }

        return $map;
    }

    /**
     * @return array<int, object>
     */
    private function targetInvoiceSnapshotRows(): array
    {
        $schema = Schema::connection($this->targetConnection);

        if ($schema->hasColumn('invoices', 'config_snapshot')) {
            return $this->targetQuery('SELECT id, config_snapshot FROM invoices ORDER BY id ASC');
        }

        if ($schema->hasColumn('invoices', 'product_snapshot_json')) {
            return $this->targetQuery('SELECT id, product_snapshot_json AS config_snapshot FROM invoices ORDER BY id ASC');
        }

        return [];
    }

    private function mapReferralEventToAccountType(string $eventType): string
    {
        return match ($eventType) {
            'reward_frozen' => 'referral_frozen',
            'reward_released', 'withdraw_rejected' => 'referral_available',
            'withdraw_apply', 'withdrawal_request' => 'referral_withdrawing',
            'withdraw_approved', 'withdrawal_processed' => 'referral_withdrawn',
            default => 'referral_available',
        };
    }

    /**
     * @param  array<string, mixed>  $referralLog
     */
    private function resolveReferralLedgerBalanceAfter(string $eventType, array $referralLog): float
    {
        return match ($eventType) {
            'reward_frozen' => round((float) ($referralLog['frozen_balance'] ?? 0), 2),
            'reward_released', 'withdraw_rejected' => round((float) ($referralLog['available_balance'] ?? 0), 2),
            'withdraw_apply', 'withdrawal_request' => round((float) ($referralLog['pending_withdrawal_balance'] ?? 0), 2),
            'withdraw_approved', 'withdrawal_processed' => round((float) ($referralLog['withdrawn_balance'] ?? 0), 2),
            default => round((float) ($referralLog['available_balance'] ?? 0), 2),
        };
    }

    private function ensureConnection(string $connection, string $label): void
    {
        $databaseName = DB::connection($connection)->getDatabaseName();

        if (! is_string($databaseName) || trim($databaseName) === '') {
            throw new \RuntimeException("{$label}连接 `{$connection}` 未配置有效数据库名");
        }

        DB::connection($connection)->select('SELECT 1');
    }

    private function ensureCheckpointTable(): void
    {
        DB::connection($this->targetConnection)->statement(
            'CREATE TABLE IF NOT EXISTS account_migration_checkpoints (
                migration_name VARCHAR(100) NOT NULL PRIMARY KEY,
                completed_at TIMESTAMP NOT NULL,
                row_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function deriveOpeningBalanceLedgerPayloads(): array
    {
        $happenedAt = date('Y-m-d 00:00:00');
        $payloads = [];

        foreach ($this->targetTableRows('user_accounts', 'user_id') as $userAccount) {
            $userId = (int) ($userAccount['user_id'] ?? 0);

            if ($userId <= 0) {
                continue;
            }

            $accountBalances = [
                'cash' => $userAccount['cash_balance'] ?? null,
                'referral_frozen' => $userAccount['referral_frozen_balance'] ?? null,
                'referral_available' => $userAccount['referral_available_balance'] ?? null,
                'referral_withdrawing' => $this->resolvePendingWithdrawalBalance($userAccount),
                'referral_withdrawn' => $userAccount['referral_withdrawn_balance'] ?? null,
            ];

            foreach ($accountBalances as $accountType => $balance) {
                if (abs((float) $balance) < 0.00001) {
                    continue;
                }

                $payloads[] = $this->buildOpeningBalanceLedgerPayload($userId, $accountType, $balance, $happenedAt);
            }
        }

        return $payloads;
    }

    private function buildOpeningLedgerId(int $userId, string $accountType): int
    {
        $suffix = match ($accountType) {
            'cash' => 1,
            'referral_frozen' => 2,
            'referral_available' => 3,
            'referral_withdrawing' => 4,
            'referral_withdrawn' => 5,
            default => 9,
        };

        return 2000000000 + ($userId * 10) + $suffix;
    }

    /**
     * @param  array<string, mixed>  $userAccount
     */
    private function resolvePendingWithdrawalBalance(array $userAccount): string
    {
        if (array_key_exists('referral_pending_withdrawal_balance', $userAccount)) {
            return $this->normalizeMoney($userAccount['referral_pending_withdrawal_balance'] ?? null);
        }

        return $this->normalizeMoney($userAccount['referral_withdrawing_balance'] ?? null);
    }

    /**
     * @return array<int, true>
     */
    private function targetIdSet(string $table): array
    {
        $rows = $this->targetQuery("SELECT id FROM `{$table}` ORDER BY id");
        $set = [];

        foreach ($rows as $row) {
            $id = (int) ($row->id ?? 0);
            if ($id > 0) {
                $set[$id] = true;
            }
        }

        return $set;
    }

    private function normalizeMoney(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '0.00';
        }

        return number_format((float) $value, 2, '.', '');
    }

    private function normalizeNullablePositiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $normalized = (int) $value;

        return $normalized > 0 ? $normalized : null;
    }

    private function normalizeRequiredString(mixed $value, string $fallback): string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : $fallback;
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeDateTimeString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        if ($normalized === '') {
            return null;
        }

        return date('Y-m-d H:i:s', strtotime($normalized));
    }
}
