<?php

declare(strict_types=1);

namespace App\Services\System;

use Illuminate\Support\Facades\DB;

class ContentSystemMigrationService
{
    private string $sourceConnection;

    private string $targetConnection;

    public function __construct()
    {
        $this->sourceConnection = (string) config('content_system_migration.source_connection', 'mysql');
        $this->targetConnection = (string) config('content_system_migration.target_connection', 'mysql');
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
            'SELECT 1 FROM content_system_migration_checkpoints WHERE migration_name = ? LIMIT 1',
            [$migrationName]
        );

        return count($rows) > 0;
    }

    public function markMigrationCompleted(string $migrationName, int $rowCount): void
    {
        $this->ensureCheckpointTable();

        DB::connection($this->targetConnection)->statement(
            'INSERT INTO content_system_migration_checkpoints (migration_name, completed_at, row_count, created_at)
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
     * @return array<int, array<string, mixed>>
     */
    public function sourceTableRows(string $table, string $orderBy = 'id'): array
    {
        return array_map(static fn (object $row) => (array) $row, $this->sourceQuery(
            "SELECT * FROM `{$table}` ORDER BY `{$orderBy}` ASC"
        ));
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
     * @param  array<string, mixed>  $setting
     * @return array<string, mixed>
     */
    public function buildSettingPayload(array $setting): array
    {
        $itemValue = $setting['item_value'] ?? null;

        return [
            'id' => (int) ($setting['id'] ?? 0),
            'group_key' => $this->normalizeRequiredString($setting['group_key'] ?? null, 'system'),
            'item_key' => $this->normalizeRequiredString($setting['item_key'] ?? null, 'unknown'),
            'item_value' => $itemValue !== null ? (string) $itemValue : null,
            'value_type' => $this->detectSettingValueType($itemValue),
            'is_public' => $this->isPublicSetting((string) ($setting['group_key'] ?? ''), (string) ($setting['item_key'] ?? '')) ? 1 : 0,
            'remark' => null,
            'updated_by' => null,
            'created_at' => null,
            'updated_at' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $log
     * @return array<string, mixed>
     */
    public function buildNotificationLogPayload(array $log): array
    {
        return [
            'id' => (int) ($log['id'] ?? 0),
            'channel' => $this->normalizeRequiredString($log['channel'] ?? null, 'system'),
            'recipient' => $this->normalizeRequiredString($log['recipient'] ?? null, 'unknown'),
            'template_code' => $this->normalizeNullableString($log['template_code'] ?? null),
            'subject' => $this->normalizeNullableString($log['subject'] ?? null),
            'content' => $this->normalizeRequiredString($log['content'] ?? null, ''),
            'params_json' => $this->normalizeJsonString($log['params_json'] ?? null),
            'provider' => $this->normalizeNullableString($log['provider'] ?? null),
            'request_id' => $this->normalizeNullableString($log['request_id'] ?? null),
            'status' => $this->normalizeRequiredString($log['status'] ?? null, 'pending'),
            'error_msg' => $this->normalizeNullableString($log['error_msg'] ?? null),
            'sent_at' => $this->normalizeDateTimeString($log['sent_at'] ?? null),
            'origin_type' => $this->normalizeNullableString($log['origin_type'] ?? null),
            'origin_id' => $this->normalizeNullablePositiveInt($log['origin_id'] ?? null),
            'trace_id' => $this->normalizeNullableString($log['trace_id'] ?? $log['request_id'] ?? null),
            'created_at' => $this->normalizeDateTimeString($log['created_at'] ?? null),
            'updated_at' => $this->normalizeDateTimeString($log['updated_at'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $log
     * @return array<string, mixed>
     */
    public function buildAutomationLogPayload(array $log): array
    {
        $metaJson = $this->normalizeJsonString($log['meta'] ?? null);

        return [
            'id' => (int) ($log['id'] ?? 0),
            'task_key' => $this->normalizeRequiredString($log['task_key'] ?? null, 'legacy-task'),
            'action' => $this->normalizeRequiredString($log['action'] ?? null, 'legacy-action'),
            'object_type' => $this->normalizeRequiredString($log['object_type'] ?? null, 'legacy'),
            'object_id' => max(0, (int) ($log['object_id'] ?? 0)),
            'rule_key' => $this->normalizeRequiredString($log['rule_key'] ?? null, ''),
            'meta_json' => $metaJson,
            'result_status' => $this->detectAutomationResultStatus($metaJson),
            'executed_at' => $this->normalizeDateTimeString($log['executed_at'] ?? null),
            'trace_id' => $this->extractTraceIdFromJson($metaJson),
            'created_at' => $this->normalizeDateTimeString($log['created_at'] ?? null),
            'updated_at' => $this->normalizeDateTimeString($log['updated_at'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $log
     * @return array<string, mixed>
     */
    public function buildOperationLogPayload(array $log): array
    {
        $contextJson = $this->normalizeJsonString($log['context'] ?? null);
        $contextArray = $this->decodeJsonArray($contextJson);

        return [
            'id' => (int) ($log['id'] ?? 0),
            'user_id' => $this->normalizeNullablePositiveInt($log['user_id'] ?? null),
            'user_type' => $this->normalizeNullableString($log['user_type'] ?? null),
            'action' => $this->normalizeRequiredString($log['action'] ?? null, 'legacy.action'),
            'module' => $this->normalizeNullableString($log['module'] ?? null),
            'subject_type' => $this->deriveOperationSubjectType((string) ($log['module'] ?? ''), $contextArray),
            'subject_id' => $this->normalizeNullablePositiveInt($log['subject_id'] ?? null),
            'context_json' => $contextJson,
            'ip_address' => $this->normalizeNullableString($log['ip_address'] ?? null),
            'trace_id' => $this->extractTraceIdFromJson($contextJson),
            'created_at' => $this->normalizeDateTimeString($log['created_at'] ?? null),
            'updated_at' => $this->normalizeDateTimeString($log['created_at'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $legacyTicket
     * @return array<string, mixed>
     */
    public function buildTicketPayload(array $legacyTicket, ?int $targetServiceInstanceId, ?string $lastReplyAt): array
    {
        $status = (int) ($legacyTicket['status'] ?? 0);
        $updatedAt = $this->normalizeDateTimeString($legacyTicket['updated_at'] ?? null);

        return [
            'id' => (int) ($legacyTicket['id'] ?? 0),
            'ticket_no' => $this->buildTicketNo((int) ($legacyTicket['id'] ?? 0)),
            'user_id' => (int) ($legacyTicket['user_id'] ?? 0),
            'department' => $this->normalizeRequiredString($legacyTicket['department'] ?? null, 'support'),
            'subject' => $this->normalizeRequiredString($legacyTicket['subject'] ?? null, '未命名工单'),
            'priority' => (int) ($legacyTicket['priority'] ?? 1),
            'status' => $status,
            'service_instance_id' => $targetServiceInstanceId,
            'assignee_admin_id' => $this->normalizeNullablePositiveInt($legacyTicket['assignee_id'] ?? null),
            'last_reply_at' => $lastReplyAt,
            'closed_at' => $status === 3 ? $updatedAt : null,
            'close_reason' => $this->normalizeNullableString($legacyTicket['close_reason'] ?? null),
            'created_at' => $this->normalizeDateTimeString($legacyTicket['created_at'] ?? null),
            'updated_at' => $updatedAt,
        ];
    }

    /**
     * @param  array<string, mixed>  $legacyReply
     * @return array<string, mixed>
     */
    public function buildTicketReplyPayload(array $legacyReply): array
    {
        $isStaff = (int) ($legacyReply['is_staff'] ?? 0) === 1;

        return [
            'id' => (int) ($legacyReply['id'] ?? 0),
            'ticket_id' => (int) ($legacyReply['ticket_id'] ?? 0),
            'user_id' => $isStaff ? null : $this->normalizeNullablePositiveInt($legacyReply['user_id'] ?? null),
            'admin_user_id' => $isStaff ? $this->normalizeNullablePositiveInt($legacyReply['user_id'] ?? null) : null,
            'sender_type' => $isStaff ? 'admin' : 'user',
            'content' => $this->normalizeRequiredString($legacyReply['content'] ?? null, ''),
            'attachments_json' => $this->normalizeJsonArrayString($legacyReply['attachments'] ?? null),
            'is_internal' => 0,
            'created_at' => $this->normalizeDateTimeString($legacyReply['created_at'] ?? null),
            'updated_at' => $this->normalizeDateTimeString($legacyReply['created_at'] ?? null),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deriveSettingPayloads(): array
    {
        $payloads = [];

        foreach ($this->sourceTableRows('settings') as $setting) {
            if ($this->shouldSkipSetting($setting)) {
                continue;
            }

            $payloads[] = $this->buildSettingPayload($setting);
        }

        return $payloads;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deriveNotificationLogPayloads(): array
    {
        return array_map(
            fn (array $row) => $this->buildNotificationLogPayload($row),
            $this->sourceTableRows('notification_logs')
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deriveAutomationLogPayloads(): array
    {
        return array_map(
            fn (array $row) => $this->buildAutomationLogPayload($row),
            $this->sourceTableRows('automation_logs')
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deriveOperationLogPayloads(): array
    {
        return array_map(
            fn (array $row) => $this->buildOperationLogPayload($row),
            $this->sourceTableRows('operation_logs')
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deriveTicketPayloads(): array
    {
        $serviceInstanceMap = $this->serviceInstanceMapByLegacyServiceId();
        $lastReplyAtMap = $this->ticketLastReplyAtMap();

        return array_map(function (array $ticket) use ($serviceInstanceMap, $lastReplyAtMap): array {
            $legacyServiceId = $this->normalizeNullablePositiveInt($ticket['service_id'] ?? null);
            $targetServiceInstanceId = $legacyServiceId !== null
                ? ($serviceInstanceMap[$legacyServiceId] ?? null)
                : null;

            return $this->buildTicketPayload(
                $ticket,
                $targetServiceInstanceId,
                $lastReplyAtMap[(int) ($ticket['id'] ?? 0)] ?? null
            );
        }, $this->sourceTableRows('tickets'));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function deriveTicketReplyPayloads(): array
    {
        return array_map(
            fn (array $row) => $this->buildTicketReplyPayload($row),
            $this->sourceTableRows('ticket_replies')
        );
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
            'CREATE TABLE IF NOT EXISTS content_system_migration_checkpoints (
                migration_name VARCHAR(100) NOT NULL PRIMARY KEY,
                completed_at TIMESTAMP NOT NULL,
                row_count INT UNSIGNED NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    /**
     * @param  array<string, mixed>  $setting
     */
    private function shouldSkipSetting(array $setting): bool
    {
        $itemKey = (string) ($setting['item_key'] ?? '');

        return str_contains($itemKey, '_cache')
            || str_contains($itemKey, '_temp')
            || str_starts_with($itemKey, 'session_');
    }

    private function detectSettingValueType(mixed $value): string
    {
        if ($value === null) {
            return 'string';
        }

        $stringValue = trim((string) $value);

        if ($stringValue === '') {
            return 'string';
        }

        if ($stringValue === '0' || $stringValue === '1') {
            return 'bool';
        }

        if (is_numeric($stringValue)) {
            return str_contains($stringValue, '.') ? 'number' : 'integer';
        }

        if ($this->looksLikeJson($stringValue)) {
            return 'json';
        }

        return 'string';
    }

    private function isPublicSetting(string $groupKey, string $itemKey): bool
    {
        $publicGroups = ['basic', 'product', 'traffic_package_catalog'];
        $sensitiveKeys = ['email_password', 'sms_secret', 'api_key', 'secret', 'token'];

        if (! in_array($groupKey, $publicGroups, true)) {
            return false;
        }

        foreach ($sensitiveKeys as $fragment) {
            if (str_contains($itemKey, $fragment)) {
                return false;
            }
        }

        return true;
    }

    private function detectAutomationResultStatus(?string $metaJson): string
    {
        $meta = $this->decodeJsonArray($metaJson);
        $status = $meta['result_status'] ?? $meta['status'] ?? null;

        if (! is_string($status) || trim($status) === '') {
            return 'success';
        }

        return trim($status);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function deriveOperationSubjectType(string $module, array $context): ?string
    {
        $normalizedModule = trim($module);

        return match ($normalizedModule) {
            'auth' => 'user',
            'referral' => 'referral_reward',
            'services' => 'service_instance',
            'invoices', 'finance', 'recharge' => 'invoice',
            default => isset($context['subject_type']) && is_string($context['subject_type'])
                ? trim($context['subject_type']) ?: null
                : null,
        };
    }

    private function extractTraceIdFromJson(?string $json): ?string
    {
        $decoded = $this->decodeJsonArray($json);
        $traceId = $decoded['trace_id'] ?? $decoded['request_id'] ?? null;

        return is_string($traceId) && trim($traceId) !== '' ? trim($traceId) : null;
    }

    /**
     * @return array<int, int>
     */
    private function serviceInstanceMapByLegacyServiceId(): array
    {
        $map = [];

        foreach ($this->targetQuery('SELECT id, provision_snapshot_json FROM service_instances ORDER BY id ASC') as $row) {
            $serviceInstanceId = (int) ($row->id ?? 0);
            $snapshot = $this->decodeJsonArray(is_string($row->provision_snapshot_json ?? null) ? $row->provision_snapshot_json : null);
            $legacyServiceId = isset($snapshot['legacy_service_id']) ? (int) $snapshot['legacy_service_id'] : 0;

            if ($serviceInstanceId > 0 && $legacyServiceId > 0 && ! isset($map[$legacyServiceId])) {
                $map[$legacyServiceId] = $serviceInstanceId;
            }
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    private function ticketLastReplyAtMap(): array
    {
        $map = [];

        foreach ($this->sourceQuery(
            'SELECT ticket_id, MAX(created_at) AS last_reply_at FROM ticket_replies GROUP BY ticket_id ORDER BY ticket_id ASC'
        ) as $row) {
            $ticketId = (int) ($row->ticket_id ?? 0);
            $lastReplyAt = $this->normalizeDateTimeString($row->last_reply_at ?? null);

            if ($ticketId > 0 && $lastReplyAt !== null) {
                $map[$ticketId] = $lastReplyAt;
            }
        }

        return $map;
    }

    private function buildTicketNo(int $ticketId): string
    {
        return 'TK'.str_pad((string) $ticketId, 8, '0', STR_PAD_LEFT);
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

    private function normalizeJsonString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $stringValue = trim((string) $value);
        if ($stringValue === '') {
            return null;
        }

        json_decode($stringValue, true);

        return json_last_error() === JSON_ERROR_NONE ? $stringValue : json_encode($stringValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function normalizeJsonArrayString(mixed $value): string
    {
        $normalized = $this->normalizeJsonString($value);
        if ($normalized === null) {
            return '[]';
        }

        $decoded = json_decode($normalized, true);

        return is_array($decoded) ? $normalized : '[]';
    }

    private function looksLikeJson(string $value): bool
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return false;
        }

        $startsWithJsonToken = str_starts_with($trimmed, '{')
            || str_starts_with($trimmed, '[')
            || $trimmed === 'null'
            || $trimmed === 'true'
            || $trimmed === 'false';

        if (! $startsWithJsonToken) {
            return false;
        }

        json_decode($trimmed, true);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonArray(?string $json): array
    {
        if ($json === null || trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
