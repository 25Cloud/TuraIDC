<?php

declare(strict_types=1);

namespace App\Services\System;

use Illuminate\Support\Facades\DB;

/**
 * 身份与权限域迁移辅助服务。
 *
 * 提供双库连接、结构读取、分页读取、幂等检查点等通用能力，
 * 供 migrate:identity:* 系列命令共享使用。
 */
class IdentityMigrationService
{
    private string $sourceConnection;

    private string $targetConnection;

    public function __construct()
    {
        $this->sourceConnection = (string) config('identity_migration.source_connection', 'mysql');
        $this->targetConnection = (string) config('identity_migration.target_connection', 'mysql');
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
            'SELECT 1 FROM identity_migration_checkpoints WHERE migration_name = ? LIMIT 1',
            [$migrationName]
        );

        return count($rows) > 0;
    }

    public function markMigrationCompleted(string $migrationName, int $rowCount): void
    {
        $this->ensureCheckpointTable();

        DB::connection($this->targetConnection)->statement(
            'INSERT INTO identity_migration_checkpoints (migration_name, completed_at, row_count, created_at)
             VALUES (?, NOW(), ?, NOW())
             ON DUPLICATE KEY UPDATE completed_at = NOW(), row_count = VALUES(row_count)',
            [$migrationName, $rowCount]
        );
    }

    public function clearMigrationCheckpoint(string $migrationName): void
    {
        $this->ensureCheckpointTable();

        DB::connection($this->targetConnection)->statement(
            'DELETE FROM identity_migration_checkpoints WHERE migration_name = ?',
            [$migrationName]
        );
    }

    public function targetTableHasRows(string $table): bool
    {
        return $this->targetCount($table) > 0;
    }

    public function targetTableExists(string $table): bool
    {
        $databaseName = (string) DB::connection($this->targetConnection)->getDatabaseName();
        $rows = DB::connection($this->targetConnection)->select(
            'SELECT COUNT(*) AS cnt
             FROM information_schema.tables
             WHERE table_schema = ? AND table_name = ?',
            [$databaseName, $table]
        );

        return (int) ($rows[0]->cnt ?? 0) > 0;
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

        return DB::connection($this->targetConnection)->affectingStatement(
            "INSERT IGNORE INTO `{$table}` ({$columnList}) VALUES {$placeholders}",
            $bindings
        );
    }

    /**
     * @param  list<string>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     */
    public function batchUpsert(string $table, array $columns, array $rows, array $uniqueBy, array $updateColumns): int
    {
        if ($rows === []) {
            return 0;
        }

        return DB::connection($this->targetConnection)
            ->table($table)
            ->upsert($rows, $uniqueBy, $updateColumns);
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
            'CREATE TABLE IF NOT EXISTS identity_migration_checkpoints (
                migration_name VARCHAR(128) NOT NULL PRIMARY KEY,
                completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                row_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $ensured = true;
    }
}
