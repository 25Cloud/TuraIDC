<?php

declare(strict_types=1);

namespace App\Services\Integrations\Plugins;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;
use RuntimeException;

/**
 * 在不改写历史审计数据的前提下，将仍在使用某方 key 的实时上游路由切到 ZJMF。
 *
 * 这里刻意不扫描 information_schema，也不做全文替换。历史开通尝试、运行日志、
 * 支付、队列和任何 secret_json 都不是本次切换的输入或输出。
 */
final class MofangFinanceProviderKeyMigrationService
{
    public const LEGACY_PROVIDER_KEY = 'mofang_finance_api';

    public const TARGET_PROVIDER_KEY = 'zjmf_finance_api';

    private const UPSTREAM_DOMAIN = 'upstream';

    private const LOCK_KEY = 'integrations:mofang-finance-provider-key-cutover';

    private const LOCK_TTL_SECONDS = 300;

    /**
     * 仅列出运行时路由表及其允许改写的标量列。任何未在此处列出的表或列均不可触及。
     *
     * @var array<string, array{extra_columns: list<string>, has_binding_key: bool}>
     */
    private const ROUTING_TABLES = [
        'integration_plugin_bindings' => [
            'extra_columns' => ['domain', 'binding_type', 'bindable_type', 'bindable_id', 'binding_key'],
            'has_binding_key' => true,
        ],
        'supplier_plugin_bindings' => [
            'extra_columns' => ['supplier_id', 'environment'],
            'has_binding_key' => false,
        ],
        'product_upstream_bindings' => [
            'extra_columns' => ['product_id', 'supplier_plugin_binding_id'],
            'has_binding_key' => false,
        ],
        'service_upstream_bindings' => [
            'extra_columns' => ['service_id', 'product_upstream_binding_id', 'supplier_plugin_binding_id', 'upstream_service_id'],
            'has_binding_key' => false,
        ],
        'service_runtime_snapshots' => [
            'extra_columns' => ['service_id', 'service_upstream_binding_id'],
            'has_binding_key' => false,
        ],
        'service_connection_snapshots' => [
            'extra_columns' => ['service_id', 'service_upstream_binding_id'],
            'has_binding_key' => false,
        ],
    ];

    /**
     * 仅允许更新这三个顶层 JSON 属性中的 provider/provider_key 精确值。
     * original_provider_key、嵌套字段、连接 JSON 与所有 secret_json 都不在白名单内。
     *
     * @var array<string, array{table: string, column: string, extra_columns: list<string>}>
     */
    private const JSON_TARGETS = [
        'services.provision_data' => [
            'table' => 'services',
            'column' => 'provision_data',
            'extra_columns' => [],
        ],
        'service_upstream_bindings.runtime_snapshot_json' => [
            'table' => 'service_upstream_bindings',
            'column' => 'runtime_snapshot_json',
            'extra_columns' => ['service_id', 'plugin_id', 'provider_key'],
        ],
        'service_runtime_snapshots.snapshot_json' => [
            'table' => 'service_runtime_snapshots',
            'column' => 'snapshot_json',
            'extra_columns' => ['service_id', 'service_upstream_binding_id', 'plugin_id', 'provider_key'],
        ],
    ];

    /**
     * 只读取可变更白名单和关联校验所需的列，防止后续维护时扩散范围。
     */
    public function inspect(): array
    {
        $plan = $this->buildPlan(false);

        return $this->report('dry_run', $plan, $this->emptyApplied());
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(): array
    {
        $lock = Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);
        if (! $lock->get()) {
            throw new RuntimeException('已有 provider key 切换任务正在运行，拒绝并发执行。');
        }

        try {
            return DB::transaction(function (): array {
                $plan = $this->buildPlan(true);
                $applied = $this->applyPlan($plan);

                $this->assertPostconditions($plan, $applied);

                return $this->report('execute', $plan, $applied);
            }, 1);
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{
     *     plugins: array{legacy_id: int, target_id: int, legacy_status: int, target_status: int},
     *     routing: array<string, array{rows: list<array<string, mixed>>}>,
     *     json: array<string, array{table: string, column: string, rows: list<array<string, mixed>>, skipped_rows: int}>
     * }
     */
    private function buildPlan(bool $lockForUpdate): array
    {
        $this->assertExpectedSchema();
        $plugins = $this->resolvePlugins($lockForUpdate);
        $routing = [];

        foreach (self::ROUTING_TABLES as $table => $definition) {
            $rows = $this->loadRoutingRows(
                $table,
                $definition,
                $plugins['legacy_id'],
                $plugins['target_id'],
                $lockForUpdate,
            );

            foreach ($rows as &$row) {
                $this->assertRoutingRowIsExpected($table, $row, $plugins);
                $row['changes'] = $this->routingChanges($row, $definition, $plugins['legacy_id'], $plugins['target_id']);
            }
            unset($row);

            $routing[$table] = ['rows' => $rows];
        }

        $json = [];
        foreach (self::JSON_TARGETS as $name => $definition) {
            $rows = $this->loadJsonRows($definition, $lockForUpdate);
            $skippedRows = 0;

            if ($name === 'services.provision_data') {
                [$rows, $skippedRows] = $this->partitionActiveServiceJsonRows($rows, $plugins, $lockForUpdate);
            }

            foreach ($rows as &$row) {
                [$payload, $changedKeys] = $this->replaceWhitelistedJsonKeys(
                    $row[$definition['column']] ?? null,
                    $name,
                );

                if ($changedKeys === []) {
                    throw new RuntimeException("{$name} 的候选记录不符合精确 JSON 白名单，拒绝继续。");
                }

                $row['json_value'] = $this->encodeJson($payload, $name);
                $row['changed_keys'] = $changedKeys;
            }
            unset($row);

            $json[$name] = [
                'table' => $definition['table'],
                'column' => $definition['column'],
                'rows' => $rows,
                'skipped_rows' => $skippedRows,
            ];
        }

        $this->assertNoUniqueConflicts($routing, $plugins['target_id'], $lockForUpdate);
        $this->assertPreflightReferences($routing, $json, $plugins, $lockForUpdate);

        return compact('plugins', 'routing', 'json');
    }

    private function assertExpectedSchema(): void
    {
        $requiredColumns = [
            'integration_plugins' => ['id', 'domain', 'plugin_key', 'status'],
            'integration_plugin_bindings' => ['id', 'plugin_id', 'provider_key', 'binding_key', 'updated_at'],
            'supplier_plugin_bindings' => ['id', 'supplier_id', 'plugin_id', 'provider_key', 'environment', 'updated_at'],
            'product_upstream_bindings' => ['id', 'product_id', 'supplier_plugin_binding_id', 'plugin_id', 'provider_key', 'updated_at'],
            'service_upstream_bindings' => ['id', 'service_id', 'product_upstream_binding_id', 'supplier_plugin_binding_id', 'plugin_id', 'provider_key', 'runtime_snapshot_json', 'updated_at'],
            'service_runtime_snapshots' => ['id', 'service_id', 'service_upstream_binding_id', 'plugin_id', 'provider_key', 'snapshot_json', 'updated_at'],
            'service_connection_snapshots' => ['id', 'service_id', 'service_upstream_binding_id', 'plugin_id', 'provider_key', 'updated_at'],
            'services' => ['id', 'provision_data', 'updated_at'],
            'suppliers' => ['id'],
            'products' => ['id'],
        ];

        foreach ($requiredColumns as $table => $columns) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("缺少受控迁移所需的表 {$table}，拒绝执行。");
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    throw new RuntimeException("缺少受控迁移所需的字段 {$table}.{$column}，拒绝执行。");
                }
            }
        }
    }

    /**
     * @return array{legacy_id: int, target_id: int, legacy_status: int, target_status: int}
     */
    private function resolvePlugins(bool $lockForUpdate): array
    {
        $query = DB::table('integration_plugins')
            ->select(['id', 'plugin_key', 'status'])
            ->where('domain', self::UPSTREAM_DOMAIN)
            ->whereIn('plugin_key', [self::LEGACY_PROVIDER_KEY, self::TARGET_PROVIDER_KEY])
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $plugins = $query->get();
        $legacy = $plugins->where('plugin_key', self::LEGACY_PROVIDER_KEY)->values();
        $target = $plugins->where('plugin_key', self::TARGET_PROVIDER_KEY)->values();

        if ($legacy->count() !== 1 || $target->count() !== 1) {
            throw new RuntimeException('必须恰好存在一条某方上游插件记录和一条 ZJMF 上游插件记录，拒绝切换。');
        }

        $legacyPlugin = $legacy->first();
        $targetPlugin = $target->first();

        if ((int) $legacyPlugin->id === (int) $targetPlugin->id) {
            throw new RuntimeException('某方和 ZJMF 插件记录不能指向同一主键。');
        }

        return [
            'legacy_id' => (int) $legacyPlugin->id,
            'target_id' => (int) $targetPlugin->id,
            'legacy_status' => (int) $legacyPlugin->status,
            'target_status' => (int) $targetPlugin->status,
        ];
    }

    /**
     * @param  array{extra_columns: list<string>, has_binding_key: bool}  $definition
     * @return list<array<string, mixed>>
     */
    private function loadRoutingRows(
        string $table,
        array $definition,
        int $legacyPluginId,
        int $targetPluginId,
        bool $lockForUpdate,
    ): array {
        $columns = array_merge(['id', 'plugin_id', 'provider_key'], $definition['extra_columns']);
        $query = DB::table($table)
            ->select(array_values(array_unique($columns)))
            ->whereIn('plugin_id', [$legacyPluginId, $targetPluginId])
            ->whereIn('provider_key', [self::LEGACY_PROVIDER_KEY, self::TARGET_PROVIDER_KEY])
            ->where(function (Builder $query) use ($definition, $legacyPluginId): void {
                $query
                    ->where('plugin_id', $legacyPluginId)
                    ->orWhere('provider_key', self::LEGACY_PROVIDER_KEY);

                if ($definition['has_binding_key']) {
                    $query->orWhere('binding_key', self::LEGACY_PROVIDER_KEY);
                }
            })
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    /**
     * @param  array{table: string, column: string, extra_columns: list<string>}  $definition
     * @return list<array<string, mixed>>
     */
    private function loadJsonRows(array $definition, bool $lockForUpdate): array
    {
        $column = $this->quotedIdentifier($definition['column']);
        $query = DB::table($definition['table'])
            ->select(array_merge(['id', $definition['column']], $definition['extra_columns']))
            ->where(function (Builder $query) use ($column): void {
                $query
                    ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.provider')) = ?", [self::LEGACY_PROVIDER_KEY])
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.provider_key')) = ?", [self::LEGACY_PROVIDER_KEY]);
            })
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->get()->map(static fn (object $row): array => (array) $row)->all();
    }

    /**
     * provision_data 只能跟随已确认的实时上游绑定切换。没有绑定或已改到其他上游的
     * 旧快照属于待人工核对的历史残留，绝不能据此把服务误切到 ZJMF。
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array{legacy_id: int, target_id: int, legacy_status: int, target_status: int}  $plugins
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    private function partitionActiveServiceJsonRows(array $rows, array $plugins, bool $lockForUpdate): array
    {
        $serviceIds = $this->rowIds($rows);
        if ($serviceIds === []) {
            return [$rows, 0];
        }

        $query = DB::table('service_upstream_bindings')
            ->whereIn('service_id', $serviceIds)
            ->whereIn('plugin_id', [$plugins['legacy_id'], $plugins['target_id']])
            ->whereIn('provider_key', [self::LEGACY_PROVIDER_KEY, self::TARGET_PROVIDER_KEY]);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $activeServiceIds = array_fill_keys(
            $query->pluck('service_id')->map(static fn (mixed $id): int => (int) $id)->unique()->all(),
            true,
        );
        $eligible = array_values(array_filter(
            $rows,
            static fn (array $row): bool => isset($activeServiceIds[(int) $row['id']]),
        ));

        return [$eligible, count($rows) - count($eligible)];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{legacy_id: int, target_id: int, legacy_status: int, target_status: int}  $plugins
     */
    private function assertRoutingRowIsExpected(string $table, array $row, array $plugins): void
    {
        $pluginId = $this->nullablePositiveInt($row['plugin_id'] ?? null);
        $providerKey = $this->nullableString($row['provider_key'] ?? null);

        if ($pluginId === null || ! in_array($pluginId, [$plugins['legacy_id'], $plugins['target_id']], true)) {
            throw new RuntimeException("{$table} 出现无法确认归属的插件绑定，拒绝切换。");
        }

        if (! in_array($providerKey, [self::LEGACY_PROVIDER_KEY, self::TARGET_PROVIDER_KEY], true)) {
            throw new RuntimeException("{$table} 出现无法确认归属的 provider_key，拒绝切换。");
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{extra_columns: list<string>, has_binding_key: bool}  $definition
     * @return array<string, int|string>
     */
    private function routingChanges(array $row, array $definition, int $legacyPluginId, int $targetPluginId): array
    {
        $changes = [];

        if ($this->nullablePositiveInt($row['plugin_id'] ?? null) === $legacyPluginId) {
            $changes['plugin_id'] = $targetPluginId;
        }

        if ($this->nullableString($row['provider_key'] ?? null) === self::LEGACY_PROVIDER_KEY) {
            $changes['provider_key'] = self::TARGET_PROVIDER_KEY;
        }

        if (
            $definition['has_binding_key']
            && $this->nullableString($row['binding_key'] ?? null) === self::LEGACY_PROVIDER_KEY
        ) {
            $changes['binding_key'] = self::TARGET_PROVIDER_KEY;
        }

        if ($changes === []) {
            throw new RuntimeException('受控路由候选记录没有可迁移的精确旧值，拒绝继续。');
        }

        return $changes;
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    private function replaceWhitelistedJsonKeys(mixed $value, string $label): array
    {
        $payload = $this->decodeJsonObject($value, $label);
        $changedKeys = [];

        foreach (['provider', 'provider_key'] as $key) {
            if (($payload[$key] ?? null) !== self::LEGACY_PROVIDER_KEY) {
                continue;
            }

            $payload[$key] = self::TARGET_PROVIDER_KEY;
            $changedKeys[] = $key;
        }

        return [$payload, $changedKeys];
    }

    /**
     * @param  array<string, array{rows: list<array<string, mixed>>}>  $routing
     */
    private function assertNoUniqueConflicts(array $routing, int $targetPluginId, bool $lockForUpdate): void
    {
        foreach ($routing['integration_plugin_bindings']['rows'] as $row) {
            if (! array_key_exists('binding_key', $row['changes'])) {
                continue;
            }

            $query = DB::table('integration_plugin_bindings')
                ->where('domain', $row['domain'])
                ->where('binding_type', $row['binding_type'])
                ->where('bindable_type', $row['bindable_type'])
                ->where('bindable_id', $row['bindable_id'])
                ->where('binding_key', self::TARGET_PROVIDER_KEY)
                ->where('id', '<>', $row['id']);

            if ($lockForUpdate) {
                $query->lockForUpdate();
            }

            if ($query->exists()) {
                throw new RuntimeException('ZJMF 全局插件绑定已存在同一唯一键，拒绝覆盖。');
            }
        }

        foreach ($routing['supplier_plugin_bindings']['rows'] as $row) {
            if (! array_key_exists('plugin_id', $row['changes'])) {
                continue;
            }

            $query = DB::table('supplier_plugin_bindings')
                ->where('supplier_id', $row['supplier_id'])
                ->where('environment', $row['environment'])
                ->where('plugin_id', $targetPluginId)
                ->where('id', '<>', $row['id']);

            if ($lockForUpdate) {
                $query->lockForUpdate();
            }

            if ($query->exists()) {
                throw new RuntimeException('供应商已有同环境的 ZJMF 插件绑定，拒绝覆盖。');
            }
        }

        foreach ($routing['service_upstream_bindings']['rows'] as $row) {
            if (! array_key_exists('plugin_id', $row['changes'])) {
                continue;
            }

            $query = DB::table('service_upstream_bindings')
                ->where('service_id', $row['service_id'])
                ->where('upstream_service_id', $row['upstream_service_id'])
                ->where('plugin_id', $targetPluginId)
                ->where('id', '<>', $row['id']);

            if ($lockForUpdate) {
                $query->lockForUpdate();
            }

            if ($query->exists()) {
                throw new RuntimeException('服务已有相同上游实例的 ZJMF 绑定，拒绝覆盖。');
            }
        }
    }

    /**
     * @param  array<string, array{rows: list<array<string, mixed>>}>  $routing
     * @param  array<string, array{table: string, column: string, rows: list<array<string, mixed>>}>  $json
     * @param  array{legacy_id: int, target_id: int, legacy_status: int, target_status: int}  $plugins
     */
    private function assertPreflightReferences(array $routing, array $json, array $plugins, bool $lockForUpdate): void
    {
        $this->assertReferencedRowsExist($routing['product_upstream_bindings']['rows'], 'product_id', 'products', $lockForUpdate);
        $this->assertReferencedRowsExist($routing['product_upstream_bindings']['rows'], 'supplier_plugin_binding_id', 'supplier_plugin_bindings', $lockForUpdate);
        $this->assertReferencedRowsExist($routing['service_upstream_bindings']['rows'], 'service_id', 'services', $lockForUpdate);
        $this->assertReferencedRowsExist($routing['service_upstream_bindings']['rows'], 'product_upstream_binding_id', 'product_upstream_bindings', $lockForUpdate);
        $this->assertReferencedRowsExist($routing['service_upstream_bindings']['rows'], 'supplier_plugin_binding_id', 'supplier_plugin_bindings', $lockForUpdate);
        $this->assertReferencedRowsExist($routing['service_runtime_snapshots']['rows'], 'service_id', 'services', $lockForUpdate);
        $this->assertReferencedRowsExist($routing['service_runtime_snapshots']['rows'], 'service_upstream_binding_id', 'service_upstream_bindings', $lockForUpdate);
        $this->assertReferencedRowsExist($routing['service_connection_snapshots']['rows'], 'service_id', 'services', $lockForUpdate);
        $this->assertReferencedRowsExist($routing['service_connection_snapshots']['rows'], 'service_upstream_binding_id', 'service_upstream_bindings', $lockForUpdate);

        $this->assertServiceJsonRowsHaveAllowedRoute($json['services.provision_data']['rows'], $plugins, $lockForUpdate);
        $this->assertJsonRouteIsExpected($json['service_upstream_bindings.runtime_snapshot_json']['rows'], $plugins);
        $this->assertReferencedRowsExist($json['service_runtime_snapshots.snapshot_json']['rows'], 'service_id', 'services', $lockForUpdate);
        $this->assertReferencedRowsExist($json['service_runtime_snapshots.snapshot_json']['rows'], 'service_upstream_binding_id', 'service_upstream_bindings', $lockForUpdate);
        $this->assertJsonRouteIsExpected($json['service_runtime_snapshots.snapshot_json']['rows'], $plugins);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertReferencedRowsExist(array $rows, string $column, string $referenceTable, bool $lockForUpdate): void
    {
        $ids = array_values(array_unique(array_filter(array_map(
            fn (array $row): ?int => $this->nullablePositiveInt($row[$column] ?? null),
            $rows,
        ))));

        if ($ids === []) {
            return;
        }

        $query = DB::table($referenceTable)->whereIn('id', $ids);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $found = $query->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        $missing = array_values(array_diff($ids, $found));

        if ($missing !== []) {
            throw new RuntimeException("发现 {$referenceTable} 关联缺失，拒绝切换。");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array{legacy_id: int, target_id: int, legacy_status: int, target_status: int}  $plugins
     */
    private function assertServiceJsonRowsHaveAllowedRoute(array $rows, array $plugins, bool $lockForUpdate): void
    {
        $serviceIds = array_values(array_unique(array_map(static fn (array $row): int => (int) $row['id'], $rows)));
        if ($serviceIds === []) {
            return;
        }

        $query = DB::table('service_upstream_bindings')
            ->whereIn('service_id', $serviceIds)
            ->whereIn('plugin_id', [$plugins['legacy_id'], $plugins['target_id']])
            ->whereIn('provider_key', [self::LEGACY_PROVIDER_KEY, self::TARGET_PROVIDER_KEY]);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $routedServiceIds = $query->pluck('service_id')->map(static fn (mixed $id): int => (int) $id)->unique()->all();
        if (array_diff($serviceIds, $routedServiceIds) !== []) {
            throw new RuntimeException('服务 provision_data 找不到可确认的上游绑定，拒绝切换。');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array{legacy_id: int, target_id: int, legacy_status: int, target_status: int}  $plugins
     */
    private function assertJsonRouteIsExpected(array $rows, array $plugins): void
    {
        foreach ($rows as $row) {
            $this->assertRoutingRowIsExpected((string) ($row['_table'] ?? '运行时快照'), $row, $plugins);
        }
    }

    /**
     * @param  array{
     *     plugins: array{legacy_id: int, target_id: int, legacy_status: int, target_status: int},
     *     routing: array<string, array{rows: list<array<string, mixed>>}>,
     *     json: array<string, array{table: string, column: string, rows: list<array<string, mixed>>, skipped_rows: int}>
     * }  $plan
     * @return array{routing: array<string, int>, json: array<string, int>}
     */
    private function applyPlan(array $plan): array
    {
        $applied = $this->emptyApplied();

        foreach ($plan['routing'] as $table => $definition) {
            foreach ($definition['rows'] as $row) {
                $payload = $row['changes'];
                $payload['updated_at'] = now();

                $affected = DB::table($table)->where('id', $row['id'])->update($payload);
                if ($affected !== 1) {
                    throw new RuntimeException("{$table} 的受控路由更新行数异常，已回滚。");
                }

                $applied['routing'][$table]++;
            }
        }

        foreach ($plan['json'] as $name => $definition) {
            foreach ($definition['rows'] as $row) {
                $affected = DB::table($definition['table'])
                    ->where('id', $row['id'])
                    ->update([
                        $definition['column'] => $row['json_value'],
                        'updated_at' => now(),
                    ]);

                if ($affected !== 1) {
                    throw new RuntimeException("{$name} 的受控 JSON 更新行数异常，已回滚。");
                }

                $applied['json'][$name]++;
            }
        }

        return $applied;
    }

    /**
     * @param  array{
     *     plugins: array{legacy_id: int, target_id: int, legacy_status: int, target_status: int},
     *     routing: array<string, array{rows: list<array<string, mixed>>}>,
     *     json: array<string, array{table: string, column: string, rows: list<array<string, mixed>>, skipped_rows: int}>
     * }  $plan
     * @param  array{routing: array<string, int>, json: array<string, int>}  $applied
     */
    private function assertPostconditions(array $plan, array $applied): void
    {
        foreach ($plan['routing'] as $table => $definition) {
            $expected = count($definition['rows']);
            if ($applied['routing'][$table] !== $expected) {
                throw new RuntimeException("{$table} 更新行数与预检不一致，已回滚。");
            }

            foreach ($definition['rows'] as $row) {
                $actual = (array) DB::table($table)->where('id', $row['id'])->first(array_keys($row['changes']));
                foreach ($row['changes'] as $column => $expectedValue) {
                    if (($actual[$column] ?? null) != $expectedValue) {
                        throw new RuntimeException("{$table} 迁移后校验失败，已回滚。");
                    }
                }
            }
        }

        foreach ($plan['json'] as $name => $definition) {
            $expected = count($definition['rows']);
            if ($applied['json'][$name] !== $expected) {
                throw new RuntimeException("{$name} 更新行数与预检不一致，已回滚。");
            }

            foreach ($definition['rows'] as $row) {
                $payload = $this->decodeJsonObject(
                    DB::table($definition['table'])->where('id', $row['id'])->value($definition['column']),
                    $name,
                );

                foreach ($row['changed_keys'] as $key) {
                    if (($payload[$key] ?? null) !== self::TARGET_PROVIDER_KEY) {
                        throw new RuntimeException("{$name} 迁移后 JSON 校验失败，已回滚。");
                    }
                }
            }
        }

        $remaining = $this->remainingReferences($plan['plugins']['legacy_id'], $plan['plugins']['target_id']);
        if ($remaining['total'] !== 0) {
            throw new RuntimeException('受控范围内仍存在旧 provider key，已回滚。');
        }

        $this->assertPostCutoverReferences($plan['routing'], $plan['json'], $plan['plugins']['target_id']);
    }

    /**
     * @param  array<string, array{rows: list<array<string, mixed>>}>  $routing
     * @param  array<string, array{table: string, column: string, rows: list<array<string, mixed>>}>  $json
     */
    private function assertPostCutoverReferences(array $routing, array $json, int $targetPluginId): void
    {
        $this->assertProductParentsAreTarget($routing['product_upstream_bindings']['rows'], $targetPluginId);
        $this->assertServiceParentsAreTarget($routing['service_upstream_bindings']['rows'], $targetPluginId);
        $this->assertSnapshotParentsAreTarget($routing['service_runtime_snapshots']['rows'], 'service_runtime_snapshots', $targetPluginId);
        $this->assertSnapshotParentsAreTarget($routing['service_connection_snapshots']['rows'], 'service_connection_snapshots', $targetPluginId);

        $this->assertServicesHaveTargetRoute($json['services.provision_data']['rows'], $targetPluginId);
        $this->assertRowsHaveTargetRoute($json['service_upstream_bindings.runtime_snapshot_json']['rows'], 'service_upstream_bindings', $targetPluginId);
        $this->assertSnapshotParentsAreTarget($json['service_runtime_snapshots.snapshot_json']['rows'], 'service_runtime_snapshots', $targetPluginId);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertProductParentsAreTarget(array $rows, int $targetPluginId): void
    {
        $ids = $this->rowIds($rows);
        if ($ids === []) {
            return;
        }

        $invalid = DB::table('product_upstream_bindings as product_binding')
            ->leftJoin('supplier_plugin_bindings as supplier_binding', 'supplier_binding.id', '=', 'product_binding.supplier_plugin_binding_id')
            ->whereIn('product_binding.id', $ids)
            ->where(function (Builder $query) use ($targetPluginId): void {
                $query
                    ->whereNull('supplier_binding.id')
                    ->orWhere('supplier_binding.plugin_id', '<>', $targetPluginId)
                    ->orWhere('supplier_binding.provider_key', '<>', self::TARGET_PROVIDER_KEY);
            })
            ->exists();

        if ($invalid) {
            throw new RuntimeException('商品上游绑定未能解析到同一条 ZJMF 供应商绑定，已回滚。');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertServiceParentsAreTarget(array $rows, int $targetPluginId): void
    {
        $ids = $this->rowIds($rows);
        if ($ids === []) {
            return;
        }

        $invalid = DB::table('service_upstream_bindings as service_binding')
            ->leftJoin('product_upstream_bindings as product_binding', 'product_binding.id', '=', 'service_binding.product_upstream_binding_id')
            ->leftJoin('supplier_plugin_bindings as supplier_binding', 'supplier_binding.id', '=', 'service_binding.supplier_plugin_binding_id')
            ->whereIn('service_binding.id', $ids)
            ->where(function (Builder $query) use ($targetPluginId): void {
                $query
                    ->where(function (Builder $query) use ($targetPluginId): void {
                        $query
                            ->whereNotNull('service_binding.product_upstream_binding_id')
                            ->where(function (Builder $query) use ($targetPluginId): void {
                                $query
                                    ->whereNull('product_binding.id')
                                    ->orWhere('product_binding.plugin_id', '<>', $targetPluginId)
                                    ->orWhere('product_binding.provider_key', '<>', self::TARGET_PROVIDER_KEY);
                            });
                    })
                    ->orWhere(function (Builder $query) use ($targetPluginId): void {
                        $query
                            ->whereNotNull('service_binding.supplier_plugin_binding_id')
                            ->where(function (Builder $query) use ($targetPluginId): void {
                                $query
                                    ->whereNull('supplier_binding.id')
                                    ->orWhere('supplier_binding.plugin_id', '<>', $targetPluginId)
                                    ->orWhere('supplier_binding.provider_key', '<>', self::TARGET_PROVIDER_KEY);
                            });
                    });
            })
            ->exists();

        if ($invalid) {
            throw new RuntimeException('服务上游绑定的商品或供应商关联未切换到 ZJMF，已回滚。');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertSnapshotParentsAreTarget(array $rows, string $table, int $targetPluginId): void
    {
        $ids = $this->rowIds($rows);
        if ($ids === []) {
            return;
        }

        $invalid = DB::table($table.' as snapshot')
            ->leftJoin('service_upstream_bindings as service_binding', 'service_binding.id', '=', 'snapshot.service_upstream_binding_id')
            ->whereIn('snapshot.id', $ids)
            ->whereNotNull('snapshot.service_upstream_binding_id')
            ->where(function (Builder $query) use ($targetPluginId): void {
                $query
                    ->whereNull('service_binding.id')
                    ->orWhere('service_binding.plugin_id', '<>', $targetPluginId)
                    ->orWhere('service_binding.provider_key', '<>', self::TARGET_PROVIDER_KEY);
            })
            ->exists();

        if ($invalid) {
            throw new RuntimeException('服务运行时快照仍指向非 ZJMF 的上游绑定，已回滚。');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertServicesHaveTargetRoute(array $rows, int $targetPluginId): void
    {
        $serviceIds = $this->rowIds($rows);
        if ($serviceIds === []) {
            return;
        }

        $invalid = DB::table('services')
            ->whereIn('id', $serviceIds)
            ->whereNotExists(function (Builder $query) use ($targetPluginId): void {
                $query
                    ->selectRaw('1')
                    ->from('service_upstream_bindings as service_binding')
                    ->whereColumn('service_binding.service_id', 'services.id')
                    ->where('service_binding.plugin_id', $targetPluginId)
                    ->where('service_binding.provider_key', self::TARGET_PROVIDER_KEY);
            })
            ->exists();

        if ($invalid) {
            throw new RuntimeException('服务 provision_data 更新后没有可解析的 ZJMF 上游绑定，已回滚。');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function assertRowsHaveTargetRoute(array $rows, string $table, int $targetPluginId): void
    {
        $ids = $this->rowIds($rows);
        if ($ids === []) {
            return;
        }

        $invalid = DB::table($table)
            ->whereIn('id', $ids)
            ->where(function (Builder $query) use ($targetPluginId): void {
                $query
                    ->where('plugin_id', '<>', $targetPluginId)
                    ->orWhere('provider_key', '<>', self::TARGET_PROVIDER_KEY);
            })
            ->exists();

        if ($invalid) {
            throw new RuntimeException("{$table} 的运行时 JSON 未绑定到 ZJMF，已回滚。");
        }
    }

    /**
     * @return array{scope: string, routing: array<string, int>, json: array<string, int>, total: int}
     */
    private function remainingReferences(int $legacyPluginId, int $targetPluginId): array
    {
        $routing = [];
        foreach (self::ROUTING_TABLES as $table => $definition) {
            $routing[$table] = DB::table($table)
                ->whereIn('plugin_id', [$legacyPluginId, $targetPluginId])
                ->whereIn('provider_key', [self::LEGACY_PROVIDER_KEY, self::TARGET_PROVIDER_KEY])
                ->where(function (Builder $query) use ($definition, $legacyPluginId): void {
                    $query
                        ->where('plugin_id', $legacyPluginId)
                        ->orWhere('provider_key', self::LEGACY_PROVIDER_KEY);

                    if ($definition['has_binding_key']) {
                        $query->orWhere('binding_key', self::LEGACY_PROVIDER_KEY);
                    }
                })
                ->count();
        }

        $json = [];
        foreach (self::JSON_TARGETS as $name => $definition) {
            $column = $this->quotedIdentifier($definition['column']);
            $query = DB::table($definition['table'])
                ->where(function (Builder $query) use ($column): void {
                    $query
                        ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.provider')) = ?", [self::LEGACY_PROVIDER_KEY])
                        ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.provider_key')) = ?", [self::LEGACY_PROVIDER_KEY]);
                });

            if ($name === 'services.provision_data') {
                $query->whereExists(function (Builder $query) use ($legacyPluginId, $targetPluginId): void {
                    $query
                        ->selectRaw('1')
                        ->from('service_upstream_bindings as service_binding')
                        ->whereColumn('service_binding.service_id', 'services.id')
                        ->whereIn('service_binding.plugin_id', [$legacyPluginId, $targetPluginId])
                        ->whereIn('service_binding.provider_key', [self::LEGACY_PROVIDER_KEY, self::TARGET_PROVIDER_KEY]);
                });
            }

            $json[$name] = $query->count();
        }

        return [
            'scope' => 'controlled_active_routes_only',
            'routing' => $routing,
            'json' => $json,
            'total' => array_sum($routing) + array_sum($json),
        ];
    }

    /**
     * @return array{routing: array<string, int>, json: array<string, int>}
     */
    private function emptyApplied(): array
    {
        return [
            'routing' => array_fill_keys(array_keys(self::ROUTING_TABLES), 0),
            'json' => array_fill_keys(array_keys(self::JSON_TARGETS), 0),
        ];
    }

    /**
     * @param  array{
     *     plugins: array{legacy_id: int, target_id: int, legacy_status: int, target_status: int},
     *     routing: array<string, array{rows: list<array<string, mixed>>}>,
     *     json: array<string, array{table: string, column: string, rows: list<array<string, mixed>>, skipped_rows: int}>
     * }  $plan
     * @param  array{routing: array<string, int>, json: array<string, int>}  $applied
     * @return array<string, mixed>
     */
    private function report(string $mode, array $plan, array $applied): array
    {
        $routing = [];
        foreach ($plan['routing'] as $table => $definition) {
            $columnChanges = [];
            foreach ($definition['rows'] as $row) {
                foreach (array_keys($row['changes']) as $column) {
                    $columnChanges[$column] = ($columnChanges[$column] ?? 0) + 1;
                }
            }

            $routing[$table] = [
                'candidate_rows' => count($definition['rows']),
                'updated_rows' => $applied['routing'][$table],
                'column_changes' => $columnChanges,
            ];
        }

        $json = [];
        foreach ($plan['json'] as $name => $definition) {
            $fieldChanges = [];
            foreach ($definition['rows'] as $row) {
                foreach ($row['changed_keys'] as $key) {
                    $fieldChanges[$key] = ($fieldChanges[$key] ?? 0) + 1;
                }
            }

            $json[$name] = [
                'candidate_rows' => count($definition['rows']),
                'updated_rows' => $applied['json'][$name],
                'field_changes' => $fieldChanges,
                'skipped_unrouted_rows' => $definition['skipped_rows'],
            ];
        }

        $remaining = $this->remainingReferences($plan['plugins']['legacy_id'], $plan['plugins']['target_id']);

        return [
            'mode' => $mode,
            'database' => (string) DB::getDatabaseName(),
            'from' => self::LEGACY_PROVIDER_KEY,
            'to' => self::TARGET_PROVIDER_KEY,
            'plugins' => [
                'legacy_id' => $plan['plugins']['legacy_id'],
                'target_id' => $plan['plugins']['target_id'],
                'legacy_status_unchanged' => $plan['plugins']['legacy_status'],
                'target_status_unchanged' => $plan['plugins']['target_status'],
                'legacy_record_retained' => true,
            ],
            'routing' => $routing,
            'json' => $json,
            'total_candidate_operations' => array_sum(array_column($routing, 'candidate_rows'))
                + array_sum(array_column($json, 'candidate_rows')),
            'total_updated_operations' => array_sum(array_column($routing, 'updated_rows'))
                + array_sum(array_column($json, 'updated_rows')),
            'skipped_unrouted_service_json_rows' => $json['services.provision_data']['skipped_unrouted_rows'],
            'out_of_scope_legacy_snapshots' => [
                'services.provision_data' => $json['services.provision_data']['skipped_unrouted_rows'],
            ],
            'remaining_legacy_references' => $remaining,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(mixed $value, string $label): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("{$label} 不是可处理的 JSON 对象，拒绝切换。");
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException("{$label} JSON 无法解析，拒绝切换。");
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            throw new RuntimeException("{$label} 必须是 JSON 对象，拒绝切换。");
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function encodeJson(array $payload, string $label): string
    {
        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException("{$label} JSON 无法编码，拒绝切换。");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<int>
     */
    private function rowIds(array $rows): array
    {
        return array_values(array_unique(array_map(static fn (array $row): int => (int) $row['id'], $rows)));
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value <= 0) {
            return null;
        }

        return (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function quotedIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new RuntimeException('发现不安全的数据库标识符，拒绝执行 provider key 切换。');
        }

        return '`'.$identifier.'`';
    }
}
