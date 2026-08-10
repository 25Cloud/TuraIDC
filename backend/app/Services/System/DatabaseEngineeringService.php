<?php

declare(strict_types=1);

namespace App\Services\System;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DatabaseEngineeringService
{
    /**
     * @return list<string>
     */
    public function baseTables(): array
    {
        return collect(DB::select("
            SELECT table_name AS table_name
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_type = 'BASE TABLE'
            ORDER BY table_name
        "))
            ->map(fn (object $row) => (string) $row->table_name)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public function optimizableTables(int $minimumFreeBytes, float $minimumFreeRatio): array
    {
        return collect($this->optimizationCandidates($minimumFreeBytes, $minimumFreeRatio))
            ->pluck('table_name')
            ->all();
    }

    /**
     * @return list<array{table_name: string, reclaimable_bytes: int, reclaimable_mb: float, fragmentation_ratio: float}>
     */
    public function optimizationCandidates(int $minimumFreeBytes, float $minimumFreeRatio): array
    {
        return collect(DB::select("
            SELECT table_name AS table_name
                 , data_free AS reclaimable_bytes
                 , data_length + index_length AS total_bytes
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_type = 'BASE TABLE'
              AND data_free >= ?
              AND (
                  data_length + index_length = 0
                  OR data_free / (data_length + index_length) >= ?
              )
            ORDER BY data_free DESC, table_name
        ", [$minimumFreeBytes, $minimumFreeRatio]))
            ->map(static function (object $row): array {
                $reclaimableBytes = max(0, (int) ($row->reclaimable_bytes ?? 0));
                $totalBytes = max(0, (int) ($row->total_bytes ?? 0));

                return [
                    'table_name' => (string) $row->table_name,
                    'reclaimable_bytes' => $reclaimableBytes,
                    'reclaimable_mb' => round($reclaimableBytes / 1024 / 1024, 2),
                    'fragmentation_ratio' => $totalBytes > 0 ? round($reclaimableBytes / $totalBytes, 4) : 1.0,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function auditCore(): array
    {
        $tables = $this->baseTables();

        return [
            'database' => (string) DB::getDatabaseName(),
            'table_count' => count($tables),
            'tables' => $tables,
            'foreign_keys' => $this->foreignKeys(),
            'zero_reference_metrics' => $this->zeroReferenceMetrics(),
            'orphan_metrics' => $this->orphanMetrics(),
            'trace_id_metrics' => $this->traceIdMetrics(),
            'table_size_metrics' => $this->tableSizeMetrics(),
            'index_metrics' => $this->indexMetrics(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function auditForeignKeyCoverage(): array
    {
        $existingForeignKeys = collect($this->foreignKeys())
            ->mapWithKeys(fn (array $fk): array => [
                $fk['table_name'].'.'.$fk['column_name'] => $fk,
            ]);
        $logicalReferences = $this->logicalForeignKeyTargets();

        $columns = collect(DB::select("
            SELECT
                table_name AS table_name,
                column_name AS column_name,
                is_nullable AS is_nullable,
                column_type AS column_type,
                column_comment AS column_comment
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND column_name LIKE '%\\_id' ESCAPE '\\\\'
            ORDER BY table_name, ordinal_position
        "));

        $groups = [
            'existing_fk' => [],
            'candidate_fk' => [],
            'polymorphic_or_snapshot' => [],
            'unclassified' => [],
        ];

        foreach ($columns as $column) {
            $tableName = (string) $column->table_name;
            $columnName = (string) $column->column_name;
            $key = $tableName.'.'.$columnName;

            if ($existingForeignKeys->has($key)) {
                $groups['existing_fk'][] = array_merge([
                    'table_name' => $tableName,
                    'column_name' => $columnName,
                    'is_nullable' => (string) $column->is_nullable,
                    'column_type' => (string) $column->column_type,
                    'column_comment' => (string) ($column->column_comment ?? ''),
                ], (array) $existingForeignKeys->get($key));

                continue;
            }

            $reference = $logicalReferences[$key] ?? null;
            if ($reference === null) {
                $groups['unclassified'][] = [
                    'table_name' => $tableName,
                    'column_name' => $columnName,
                    'is_nullable' => (string) $column->is_nullable,
                    'column_type' => (string) $column->column_type,
                    'column_comment' => (string) ($column->column_comment ?? ''),
                    'reason' => '未在逻辑外键映射中声明',
                ];

                continue;
            }

            if (($reference['category'] ?? '') !== 'candidate_fk') {
                $groups['polymorphic_or_snapshot'][] = [
                    'table_name' => $tableName,
                    'column_name' => $columnName,
                    'is_nullable' => (string) $column->is_nullable,
                    'column_type' => (string) $column->column_type,
                    'column_comment' => (string) ($column->column_comment ?? ''),
                    'category' => (string) ($reference['category'] ?? 'polymorphic_or_snapshot'),
                    'reason' => (string) ($reference['reason'] ?? ''),
                ];

                continue;
            }

            $referencedTable = (string) $reference['referenced_table'];
            $referencedColumn = (string) ($reference['referenced_column'] ?? 'id');
            $groups['candidate_fk'][] = [
                'table_name' => $tableName,
                'column_name' => $columnName,
                'is_nullable' => (string) $column->is_nullable,
                'column_type' => (string) $column->column_type,
                'column_comment' => (string) ($column->column_comment ?? ''),
                'referenced_table_name' => $referencedTable,
                'referenced_column_name' => $referencedColumn,
                'delete_rule' => (string) ($reference['delete_rule'] ?? 'RESTRICT'),
                'orphan_count' => $this->countOrphans($tableName, $columnName, $referencedTable, $referencedColumn),
                'reason' => (string) ($reference['reason'] ?? ''),
            ];
        }

        return [
            'database' => (string) DB::getDatabaseName(),
            'counts' => [
                'existing_fk' => count($groups['existing_fk']),
                'candidate_fk' => count($groups['candidate_fk']),
                'polymorphic_or_snapshot' => count($groups['polymorphic_or_snapshot']),
                'unclassified' => count($groups['unclassified']),
            ],
            'groups' => $groups,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function normalizeCoreRelations(): array
    {
        $summary = [
            'services_order_id_zero_to_null' => 0,
            'services_invoice_id_zero_to_null' => 0,
            'payments_order_id_zero_to_null' => 0,
            'payments_invoice_id_zero_to_null' => 0,
            'invoices_order_id_zero_to_null' => 0,
            'invoice_items_deleted_orphans' => 0,
            'payment_callbacks_deleted_orphans' => 0,
            'user_accounts_deleted_orphans' => 0,
            'ticket_replies_deleted_orphans' => 0,
            'services_deleted_orphan_user_or_product' => 0,
            'services_cleared_orphan_invoice_id' => 0,
            'invoices_cleared_orphan_order_id' => 0,
            'invoices_deleted_orphan_user_or_product' => 0,
            'payments_orphan_user_or_invoice_reported' => 0,
            'trace_ids_backfilled' => 0,
        ];

        DB::transaction(function () use (&$summary): void {
            $this->ensureNullableUnsignedBigInt('services', 'order_id');
            $summary['services_order_id_zero_to_null'] = $this->normalizeZeroReference('services', 'order_id');
            $summary['services_invoice_id_zero_to_null'] = $this->normalizeZeroReference('services', 'invoice_id');
            $summary['payments_order_id_zero_to_null'] = $this->normalizeZeroReference('payments', 'order_id');
            $summary['payments_invoice_id_zero_to_null'] = $this->normalizeZeroReference('payments', 'invoice_id');
            $summary['invoices_order_id_zero_to_null'] = $this->normalizeZeroReference('invoices', 'order_id');

            $summary['invoice_items_deleted_orphans'] = $this->deleteOrphans(
                'invoice_items',
                'invoice_id',
                'invoices',
                'id'
            );
            $summary['payment_callbacks_deleted_orphans'] = $this->deleteOrphans(
                'payment_callbacks',
                'payment_id',
                'payments',
                'id'
            );
            $summary['user_accounts_deleted_orphans'] = $this->deleteOrphans(
                'user_accounts',
                'user_id',
                'users',
                'id',
                'user_id'
            );
            $summary['ticket_replies_deleted_orphans'] = $this->deleteOrphans(
                'ticket_replies',
                'ticket_id',
                'tickets',
                'id'
            );

            $summary['services_deleted_orphan_user_or_product'] =
                $this->deleteOrphans('services', 'user_id', 'users', 'id')
                + $this->deleteOrphans('services', 'product_id', 'products', 'id');
            $summary['services_cleared_orphan_invoice_id'] = $this->clearOrphansToNull(
                'services',
                'invoice_id',
                'invoices',
                'id'
            );
            $summary['invoices_cleared_orphan_order_id'] = $this->clearOrphansToNull(
                'invoices',
                'order_id',
                'orders',
                'id'
            );
            $summary['invoices_deleted_orphan_user_or_product'] =
                $this->deleteOrphans('invoices', 'user_id', 'users', 'id')
                + $this->deleteOrphans('invoices', 'product_id', 'products', 'id');
            $summary['payments_orphan_user_or_invoice_reported'] =
                $this->countOrphans('payments', 'user_id', 'users', 'id')
                + $this->countOrphans('payments', 'invoice_id', 'invoices', 'id');

            $summary['trace_ids_backfilled'] =
                $this->backfillTraceIds('invoices') +
                $this->backfillTraceIds('payments') +
                $this->backfillTraceIds('services') +
                $this->backfillTraceIds('account_transactions');
        });

        return $summary;
    }

    /**
     * @return array<string, int>
     */
    public function archiveLogs(int $retainDays, int $chunkSize, bool $dryRun = false): array
    {
        $retainDays = max($retainDays, 1);
        $chunkSize = max($chunkSize, 1);
        $cutoff = now()->subDays($retainDays);

        $targets = [
            'operation_logs' => 'created_at',
            'message_logs' => 'created_at',
            'automation_logs' => 'created_at',
        ];

        $summary = [];

        foreach ($targets as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                $summary[$table] = 0;

                continue;
            }

            if ($dryRun) {
                $summary[$table] = DB::table($table)->where($column, '<', $cutoff)->count();

                continue;
            }

            $deleted = 0;

            do {
                $ids = DB::table($table)
                    ->where($column, '<', $cutoff)
                    ->orderBy('id')
                    ->limit($chunkSize)
                    ->pluck('id');

                $count = $ids->count();
                if ($count === 0) {
                    break;
                }

                $deleted += DB::table($table)->whereIn('id', $ids->all())->delete();
            } while ($count === $chunkSize);

            $summary[$table] = $deleted;
        }

        return $summary;
    }

    /**
     * @return list<array<string, string>>
     */
    public function foreignKeys(): array
    {
        return collect(DB::select('
            SELECT
                table_name AS table_name,
                constraint_name AS constraint_name,
                column_name AS column_name,
                referenced_table_name AS referenced_table_name,
                referenced_column_name AS referenced_column_name
            FROM information_schema.key_column_usage
            WHERE table_schema = DATABASE()
              AND referenced_table_name IS NOT NULL
            ORDER BY table_name, constraint_name, ordinal_position
        '))
            ->map(fn (object $row) => [
                'table_name' => (string) $row->table_name,
                'constraint_name' => (string) $row->constraint_name,
                'column_name' => (string) $row->column_name,
                'referenced_table_name' => (string) $row->referenced_table_name,
                'referenced_column_name' => (string) $row->referenced_column_name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function logicalForeignKeyTargets(): array
    {
        $candidate = static fn (string $referencedTable, string $deleteRule, string $reason): array => [
            'category' => 'candidate_fk',
            'referenced_table' => $referencedTable,
            'delete_rule' => $deleteRule,
            'reason' => $reason,
        ];
        $snapshot = static fn (string $reason): array => [
            'category' => 'polymorphic_or_snapshot',
            'reason' => $reason,
        ];

        return [
            'account_transactions.user_id' => $candidate('users', 'RESTRICT', '账户流水必须保留用户审计归属'),
            'account_transactions.source_id' => $snapshot('source_type/source_id 是跨域来源快照'),
            'account_transactions.origin_id' => $snapshot('origin_type/origin_id 是跨域触发对象快照'),
            'account_transactions.trace_id' => $snapshot('trace_id 是链路追踪标识，不是关系字段'),
            'activity_logs.actor_id' => $snapshot('actor_type/actor_id 是多操作者类型'),
            'activity_logs.subject_id' => $snapshot('subject_type/subject_id 是多业务对象类型'),
            'admin_user_roles.admin_user_id' => $candidate('admin_users', 'CASCADE', '管理员与角色桥表随管理员删除清理'),
            'admin_user_roles.role_id' => $candidate('roles', 'RESTRICT', '有管理员绑定时禁止删除角色'),
            'admin_users.role_id' => $candidate('roles', 'RESTRICT', '管理员必须绑定有效角色'),
            'archive_audit_logs.batch_id' => $snapshot('归档批次号是业务批次标识，不对应表主键'),
            'automation_logs.object_id' => $snapshot('automation_logs 记录调度/业务对象快照，不固定引用单表'),
            'content_articles.category_id' => $candidate('content_categories', 'SET NULL', '文章可在分类删除后保留'),
            'content_articles.trace_id' => $snapshot('trace_id 是链路追踪标识，不是关系字段'),
            'coupon_campaigns.last_coupon_id' => $candidate('coupons', 'SET NULL', '最近生成优惠券仅作执行游标'),
            'coupon_campaigns.trace_id' => $snapshot('trace_id 是链路追踪标识，不是关系字段'),
            'coupons.coupon_campaign_id' => $candidate('coupon_campaigns', 'SET NULL', '优惠券保留，活动删除后清空来源活动'),
            'coupons.trace_id' => $snapshot('trace_id 是链路追踪标识，不是关系字段'),
            'gateway_logs.plugin_id' => $candidate('integration_plugins', 'SET NULL', '网关审计日志保留，插件删除后仅清空插件引用'),
            'gateway_logs.invoice_id' => $candidate('invoices', 'SET NULL', '网关审计日志保留，账单删除后清空引用'),
            'gateway_logs.trace_id' => $snapshot('trace_id 是链路追踪标识，不是关系字段'),
            'integration_plugin_bindings.bindable_id' => $snapshot('bindable_type/bindable_id 是多态插件绑定对象'),
            'integration_plugin_bindings.backfill_batch_id' => $snapshot('回填批次号是迁移追踪标识，不对应表主键'),
            'integration_plugin_runtime_logs.trace_id' => $snapshot('trace_id 是链路追踪标识，不是关系字段'),
            'integration_plugin_runtime_logs.binding_id' => $snapshot('运行日志里的绑定 ID 是历史执行快照，允许绑定已删除'),
            'integration_plugin_runtime_logs.bindable_id' => $snapshot('bindable_type/bindable_id 是多态插件绑定对象快照'),
            'integration_plugin_runtime_logs.actor_id' => $snapshot('actor_type/actor_id 是多操作者类型'),
            'invoices.service_id' => $candidate('services', 'SET NULL', '账单保留，服务删除后清空服务引用'),
            'invoices.coupon_id' => $candidate('coupons', 'SET NULL', '账单保留，优惠券模板删除后清空引用'),
            'invoices.refund_trace_id' => $snapshot('退款 trace_id 是链路追踪标识，不是关系字段'),
            'invoices.trace_id' => $snapshot('trace_id 是链路追踪标识，不是关系字段'),
            'message_logs.plugin_id' => $candidate('integration_plugins', 'SET NULL', '消息日志保留，插件删除后仅清空插件引用'),
            'message_logs.trace_id' => $snapshot('trace_id 是链路追踪标识，不是关系字段'),
            'message_logs.request_id' => $snapshot('request_id 是请求标识，不是关系字段'),
            'message_logs.origin_id' => $snapshot('origin_type/origin_id 是消息来源对象快照'),
            'notice_reads.user_id' => $candidate('users', 'CASCADE', '阅读记录随用户删除清理'),
            'notice_reads.article_id' => $candidate('content_articles', 'CASCADE', '阅读记录随文章删除清理'),
            'notification_templates.provider_template_id' => $snapshot('供应商模板 ID 是外部平台标识'),
            'operation_logs.user_id' => $snapshot('user_type/user_id 是用户、管理员、系统等混合操作者'),
            'operation_logs.subject_id' => $snapshot('subject_type/subject_id 是多业务对象类型'),
            'orders.user_id' => $candidate('users', 'RESTRICT', '订单作为交易审计记录必须保留用户归属'),
            'orders.product_id' => $candidate('products', 'RESTRICT', '订单快照可保留，但产品引用不应脏写'),
            'orders.service_id' => $candidate('services', 'SET NULL', '订单保留，服务删除后清空引用'),
            'orders.coupon_id' => $candidate('coupons', 'SET NULL', '订单保留，优惠券模板删除后清空引用'),
            'orders.user_coupon_id' => $candidate('user_coupons', 'SET NULL', '订单保留，用户优惠券删除后清空引用'),
            'orders.trace_id' => $snapshot('trace_id 是链路追踪标识，不是关系字段'),
            'payment_callbacks.trace_id' => $snapshot('trace_id 是链路追踪标识，不是关系字段'),
            'payments.order_id' => $candidate('orders', 'SET NULL', '支付记录保留，内部订单删除后清空引用'),
            'payments.trace_id' => $snapshot('trace_id 是链路追踪标识，不是关系字段'),
            'personal_access_tokens.tokenable_id' => $snapshot('tokenable_type/tokenable_id 是 Sanctum 多态令牌关系'),
            'product_upstream_bindings.upstream_product_id' => $snapshot('上游商品 ID 是外部供应商标识'),
            'product_upstream_bindings.backfill_batch_id' => $snapshot('回填批次号是迁移追踪标识，不对应表主键'),
            'recharge_records.operator_id' => $snapshot('operator_type/operator_id 是多操作者类型快照'),
            'recharge_records.trace_id' => $snapshot('trace_id 是链路追踪标识，不是关系字段'),
            'second_product_groups.first_product_group_id' => $candidate('first_product_groups', 'RESTRICT', '二级分组必须归属一个一级分组'),
            'referral_account_logs.user_id' => $candidate('users', 'RESTRICT', '返佣账户日志必须保留用户归属'),
            'referral_account_logs.reference_id' => $snapshot('reference_type/reference_id 是返佣来源对象快照'),
            'referral_account_logs.trace_id' => $snapshot('trace_id 是链路追踪标识，不是关系字段'),
            'referral_rewards.user_id' => $candidate('users', 'RESTRICT', '返佣奖励必须保留用户归属'),
            'referral_rewards.referrer_user_id' => $candidate('users', 'RESTRICT', '返佣奖励必须保留推荐人归属'),
            'referral_rewards.referred_user_id' => $candidate('users', 'RESTRICT', '返佣奖励必须保留被推荐用户归属'),
            'referral_rewards.order_id' => $candidate('orders', 'RESTRICT', '返佣奖励必须保留来源订单归属'),
            'referral_rewards.invoice_id' => $candidate('invoices', 'SET NULL', '返佣奖励保留，账单删除后清空引用'),
            'referral_rewards.product_id' => $candidate('products', 'SET NULL', '返佣奖励保留，商品删除后清空引用'),
            'referral_rewards.trace_id' => $snapshot('trace_id 是链路追踪标识，不是关系字段'),
            'referral_withdrawals.user_id' => $candidate('users', 'RESTRICT', '提现记录必须保留用户归属'),
            'referral_withdrawals.trace_id' => $snapshot('trace_id 是链路追踪标识，不是关系字段'),
            'refunds.operator_id' => $snapshot('operator_type/operator_id 是多操作者类型快照'),
            'refunds.trace_id' => $snapshot('trace_id 是链路追踪标识，不是关系字段'),
            'service_connection_snapshots.backfill_batch_id' => $snapshot('回填批次号是迁移追踪标识，不对应表主键'),
            'service_provision_attempts.trace_id' => $snapshot('trace_id 是链路追踪标识，不是关系字段'),
            'service_provision_attempts.backfill_batch_id' => $snapshot('回填批次号是迁移追踪标识，不对应表主键'),
            'service_runtime_snapshots.backfill_batch_id' => $snapshot('回填批次号是迁移追踪标识，不对应表主键'),
            'service_upstream_bindings.upstream_service_id' => $snapshot('上游服务 ID 是外部供应商标识'),
            'service_upstream_bindings.upstream_account_id' => $snapshot('上游账号 ID 是外部供应商标识'),
            'service_upstream_bindings.backfill_batch_id' => $snapshot('回填批次号是迁移追踪标识，不对应表主键'),
            'services.order_id' => $candidate('orders', 'SET NULL', '服务保留，内部订单删除后清空引用'),
            'services.trace_id' => $snapshot('trace_id 是链路追踪标识，不是关系字段'),
            'sessions.user_id' => $candidate('users', 'CASCADE', '会话随用户删除清理'),
            'supplier_plugin_bindings.backfill_batch_id' => $snapshot('回填批次号是迁移追踪标识，不对应表主键'),
            'third_product_groups.second_product_group_id' => $candidate('second_product_groups', 'RESTRICT', '三级分组必须归属一个二级分组'),
            'tickets.service_id' => $candidate('services', 'SET NULL', '工单可在服务删除后保留历史记录'),
            'tickets.assignee_id' => $candidate('admin_users', 'SET NULL', '负责人删除后保留工单并清空负责人'),
            'ticket_replies.user_id' => $snapshot('is_staff 决定 user_id 指向用户或管理员'),
            'ticket_replies.quote_reply_id' => $candidate('ticket_replies', 'SET NULL', '引用回复被删除时保留当前回复'),
            'user_coupons.user_id' => $candidate('users', 'RESTRICT', '优惠券归属用户必须存在'),
            'user_coupons.trace_id' => $snapshot('trace_id 是链路追踪标识，不是关系字段'),
            'user_notifications.user_id' => $candidate('users', 'CASCADE', '站内通知随用户删除清理'),
            'users.referrer_user_id' => $candidate('users', 'SET NULL', '推荐人删除后保留用户账号'),
            'users.member_level_id' => $candidate('member_levels', 'SET NULL', '会员等级删除后用户回到默认等级语义'),
            'users.verification_certify_id' => $snapshot('实名认证 certify_id 是外部平台标识'),
            'verification_histories.user_id' => $candidate('users', 'RESTRICT', '实名历史必须保留用户归属'),
            'verification_histories.verification_certify_id' => $snapshot('实名认证 certify_id 是外部平台标识'),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function zeroReferenceMetrics(): array
    {
        return [
            'services.order_id' => $this->countEquals('services', 'order_id', 0),
            'services.invoice_id' => $this->countEquals('services', 'invoice_id', 0),
            'payments.order_id' => $this->countEquals('payments', 'order_id', 0),
            'payments.invoice_id' => $this->countEquals('payments', 'invoice_id', 0),
            'invoices.order_id' => $this->countEquals('invoices', 'order_id', 0),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function orphanMetrics(): array
    {
        return [
            'invoice_items.invoice_id->invoices.id' => $this->countOrphans('invoice_items', 'invoice_id', 'invoices', 'id'),
            'payment_callbacks.payment_id->payments.id' => $this->countOrphans('payment_callbacks', 'payment_id', 'payments', 'id'),
            'user_accounts.user_id->users.id' => $this->countOrphans('user_accounts', 'user_id', 'users', 'id'),
            'ticket_replies.ticket_id->tickets.id' => $this->countOrphans('ticket_replies', 'ticket_id', 'tickets', 'id'),
            'services.user_id->users.id' => $this->countOrphans('services', 'user_id', 'users', 'id'),
            'services.product_id->products.id' => $this->countOrphans('services', 'product_id', 'products', 'id'),
            'services.invoice_id->invoices.id' => $this->countOrphans('services', 'invoice_id', 'invoices', 'id'),
            'invoices.order_id->orders.id' => $this->countOrphans('invoices', 'order_id', 'orders', 'id'),
            'invoices.user_id->users.id' => $this->countOrphans('invoices', 'user_id', 'users', 'id'),
            'invoices.product_id->products.id' => $this->countOrphans('invoices', 'product_id', 'products', 'id'),
            'payments.user_id->users.id' => $this->countOrphans('payments', 'user_id', 'users', 'id'),
            'payments.invoice_id->invoices.id' => $this->countOrphans('payments', 'invoice_id', 'invoices', 'id'),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function traceIdMetrics(): array
    {
        return [
            'invoices.trace_id_missing' => $this->countMissingTraceId('invoices'),
            'payments.trace_id_missing' => $this->countMissingTraceId('payments'),
            'services.trace_id_missing' => $this->countMissingTraceId('services'),
            'account_transactions.trace_id_missing' => $this->countMissingTraceId('account_transactions'),
        ];
    }

    /**
     * @return list<array{table_name: string, table_rows: int, size_mb: float, update_time: ?string}>
     */
    public function tableSizeMetrics(): array
    {
        return collect(DB::select("
            SELECT
                table_name AS table_name,
                table_rows AS table_rows,
                ROUND((data_length + index_length) / 1024 / 1024, 2) AS size_mb,
                update_time AS update_time
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_type = 'BASE TABLE'
            ORDER BY (data_length + index_length) DESC, table_name
        "))
            ->map(fn (object $row) => [
                'table_name' => (string) $row->table_name,
                'table_rows' => (int) ($row->table_rows ?? 0),
                'size_mb' => (float) ($row->size_mb ?? 0),
                'update_time' => $row->update_time ? (string) $row->update_time : null,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, list<string>>
     */
    private function indexMetrics(): array
    {
        $targets = [
            'services',
            'invoices',
            'payments',
            'invoice_items',
            'payment_callbacks',
            'user_accounts',
            'ticket_replies',
            'operation_logs',
            'message_logs',
        ];

        $rows = collect(DB::select("
            SELECT DISTINCT
                table_name AS table_name,
                index_name AS index_name
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name IN ('services','invoices','payments','invoice_items','payment_callbacks','user_accounts','ticket_replies','operation_logs','message_logs')
            ORDER BY table_name, index_name
        "));

        $result = [];

        foreach ($targets as $table) {
            $result[$table] = $rows
                ->where('table_name', $table)
                ->map(fn (object $row) => (string) $row->index_name)
                ->values()
                ->all();
        }

        return $result;
    }

    private function normalizeZeroReference(string $table, string $column): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        return DB::table($table)->where($column, 0)->update([$column => null]);
    }

    private function deleteOrphans(string $table, string $column, string $parentTable, string $parentColumn, string $primaryColumn = 'id'): int
    {
        $this->assertSafeIdentifier($table, 'table');
        $this->assertSafeIdentifier($column, 'column');
        $this->assertSafeIdentifier($parentTable, 'parentTable');
        $this->assertSafeIdentifier($parentColumn, 'parentColumn');
        $this->assertSafeIdentifier($primaryColumn, 'primaryColumn');

        if (! Schema::hasTable($table) || ! Schema::hasTable($parentTable) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        $ids = DB::table($table)
            ->leftJoin($parentTable.' as parent', "{$table}.{$column}", '=', "parent.{$parentColumn}")
            ->whereNotNull("{$table}.{$column}")
            ->whereNull("parent.{$parentColumn}")
            ->pluck("{$table}.{$primaryColumn}");

        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::table($table)->whereIn($primaryColumn, $ids->all())->delete();
    }

    private function clearOrphansToNull(string $table, string $column, string $parentTable, string $parentColumn): int
    {
        $this->assertSafeIdentifier($table, 'table');
        $this->assertSafeIdentifier($column, 'column');
        $this->assertSafeIdentifier($parentTable, 'parentTable');
        $this->assertSafeIdentifier($parentColumn, 'parentColumn');

        if (! Schema::hasTable($table) || ! Schema::hasTable($parentTable) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        $ids = DB::table($table)
            ->leftJoin($parentTable.' as parent', "{$table}.{$column}", '=', "parent.{$parentColumn}")
            ->whereNotNull("{$table}.{$column}")
            ->whereNull("parent.{$parentColumn}")
            ->pluck("{$table}.id");

        if ($ids->isEmpty()) {
            return 0;
        }

        return DB::table($table)->whereIn('id', $ids->all())->update([$column => null]);
    }

    private function countEquals(string $table, string $column, int $value): int
    {
        $this->assertSafeIdentifier($table, 'table');
        $this->assertSafeIdentifier($column, 'column');

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        return DB::table($table)->where($column, $value)->count();
    }

    private function ensureNullableUnsignedBigInt(string $table, string $column): void
    {
        $this->assertSafeIdentifier($table, 'table');
        $this->assertSafeIdentifier($column, 'column');

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        $columnInfo = DB::table('information_schema.columns')
            ->select('is_nullable', 'column_type')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->first();

        if (! $columnInfo) {
            return;
        }

        if ((string) ($columnInfo->is_nullable ?? 'YES') === 'YES') {
            return;
        }

        $columnType = (string) ($columnInfo->column_type ?? 'bigint unsigned');
        $this->assertSafeColumnType($columnType);
        DB::statement(sprintf(
            'ALTER TABLE %s MODIFY %s %s NULL',
            $this->quoteIdentifier($table),
            $this->quoteIdentifier($column),
            $columnType
        ));
    }

    /**
     * 校验标识符是否安全（字母/数字/下划线，最长 64 字符，符合 MySQL 限制）。
     */
    private function assertSafeIdentifier(string $name, string $label = 'identifier'): void
    {
        if ($name === '' || strlen($name) > 64 || preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new \RuntimeException("非法数据库标识符({$label}): {$name}");
        }
    }

    /**
     * 校验列类型是否安全（仅允许 MySQL 标准类型字符）。
     */
    private function assertSafeColumnType(string $type): void
    {
        // 允许字母、数字、空格、括号、下划线、逗号
        if ($type === '' || strlen($type) > 128 || preg_match('/^[A-Za-z0-9\s(),_]+$/', $type) !== 1) {
            throw new \RuntimeException("非法列类型: {$type}");
        }
    }

    /**
     * 反引号包裹已校验的标识符。
     */
    private function quoteIdentifier(string $name): string
    {
        $this->assertSafeIdentifier($name);

        return '`'.$name.'`';
    }

    private function countOrphans(string $table, string $column, string $parentTable, string $parentColumn): int
    {
        $this->assertSafeIdentifier($table, 'table');
        $this->assertSafeIdentifier($column, 'column');
        $this->assertSafeIdentifier($parentTable, 'parentTable');
        $this->assertSafeIdentifier($parentColumn, 'parentColumn');

        if (! Schema::hasTable($table) || ! Schema::hasTable($parentTable) || ! Schema::hasColumn($table, $column)) {
            return 0;
        }

        return DB::table($table)
            ->leftJoin($parentTable.' as parent', "{$table}.{$column}", '=', "parent.{$parentColumn}")
            ->whereNotNull("{$table}.{$column}")
            ->whereNull("parent.{$parentColumn}")
            ->count();
    }

    private function countMissingTraceId(string $table): int
    {
        $this->assertSafeIdentifier($table, 'table');

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'trace_id')) {
            return 0;
        }

        return DB::table($table)
            ->where(function ($query) {
                $query->whereNull('trace_id')
                    ->orWhere('trace_id', '');
            })
            ->count();
    }

    private function backfillTraceIds(string $table): int
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'trace_id')) {
            return 0;
        }

        $rows = DB::table($table)
            ->select('id')
            ->where(function ($query) {
                $query->whereNull('trace_id')
                    ->orWhere('trace_id', '');
            })
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            DB::table($table)
                ->where('id', $row->id)
                ->update(['trace_id' => "legacy-{$table}-{$row->id}"]);
        }

        return $rows->count();
    }
}
