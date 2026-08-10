<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\IdentityMigrationService;

/**
 * 迁移 user_accounts 表（用户账户子表）。
 */
class MigrateIdentityUserAccountsCommand extends IdentityMigrateBaseCommand
{
    protected $signature = 'migrate:identity:user-accounts
        {--dry-run : 只输出统计信息，不写入新库}
        {--plan : 输出更详细的迁移计划}
        {--force : 忽略幂等保护，强制重新迁移}
        {--batch-size=500 : 每批迁移行数}
        {--json : 以 JSON 输出结果}';

    protected $description = '迁移旧库 user_accounts 到新库 idc';

    protected function sourceTable(): string
    {
        return 'user_accounts';
    }

    protected function targetTable(): string
    {
        return 'user_accounts';
    }

    protected function migrationName(): string
    {
        return 'identity_user_accounts';
    }

    protected function preCheck(IdentityMigrationService $service): ?array
    {
        $usersMigrated = $service->isMigrationCompleted('identity_users');
        $orphanCheck = $service->sourceQuery(
            'SELECT COUNT(*) AS cnt FROM user_accounts ua
             LEFT JOIN users u ON ua.user_id = u.id
             WHERE u.id IS NULL'
        );
        $missingAccountRows = $service->sourceQuery(
            'SELECT COUNT(*) AS cnt
             FROM users u
             LEFT JOIN user_accounts ua ON u.id = ua.user_id
             WHERE ua.user_id IS NULL'
        );
        $balanceStats = $service->sourceQuery(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN cash_balance > 0 THEN 1 ELSE 0 END) AS has_cash,
                SUM(cash_balance) AS sum_cash,
                SUM(CASE WHEN referral_available_balance > 0 THEN 1 ELSE 0 END) AS has_referral,
                SUM(referral_available_balance) AS sum_referral
             FROM user_accounts'
        );

        $summary = $balanceStats[0] ?? null;
        $result = [
            'users 已迁移' => $usersMigrated ? '是' : '否（建议先执行 migrate:identity:users）',
            '孤儿记录数' => (int) ($orphanCheck[0]->cnt ?? 0),
            '缺失账户用户数' => (int) ($missingAccountRows[0]->cnt ?? 0),
        ];

        if ($summary !== null) {
            $result['有现金余额账户数'] = (int) $summary->has_cash;
            $result['现金余额合计'] = (string) $summary->sum_cash;
            $result['有返佣余额账户数'] = (int) $summary->has_referral;
            $result['返佣余额合计'] = (string) $summary->sum_referral;
        }

        $balanceDiffRows = $service->sourceQuery(
            'SELECT COUNT(*) AS cnt
             FROM users u
             INNER JOIN user_accounts ua ON u.id = ua.user_id
             WHERE ABS(COALESCE(u.balance, 0) - COALESCE(ua.cash_balance, 0)) > 0.01'
        );
        $result['余额差异记录数'] = (int) ($balanceDiffRows[0]->cnt ?? 0);

        return $result;
    }

    protected function migrateRows(array $commonColumns, int $batchSize): int
    {
        /** @var IdentityMigrationService $service */
        $service = $this->laravel->make(IdentityMigrationService::class);

        if (! $service->isMigrationCompleted('identity_users')) {
            $this->warn('警告：users 迁移尚未完成，user_accounts 的 user_id 可能因外键约束而失败。');
            $this->warn('建议先执行 php artisan migrate:identity:users');
        }

        $targetColumns = $service->getColumnNames($service->targetConnection(), 'user_accounts');
        $mapping = [
            'user_id' => 'user_id',
            'cash_balance' => 'cash_balance',
            'credit_limit' => 'credit_limit',
            'referral_available_balance' => 'referral_available_balance',
            'referral_frozen_balance' => 'referral_frozen_balance',
            'referral_pending_withdrawal_balance' => 'referral_withdrawing_balance',
            'referral_withdrawn_balance' => 'referral_withdrawn_balance',
            'version' => 'version',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ];

        $effectiveMapping = [];
        foreach ($mapping as $sourceColumn => $targetColumn) {
            if (in_array($targetColumn, $targetColumns, true)) {
                $effectiveMapping[$sourceColumn] = $targetColumn;
            }
        }

        $sourceColumns = array_keys($effectiveMapping);
        $insertColumns = array_values(array_unique(array_values($effectiveMapping)));
        $totalMigrated = 0;
        $offset = 0;

        do {
            $rows = $service->sourcePaginate('user_accounts', $offset, $batchSize, $sourceColumns, 'user_id');
            $count = count($rows);

            if ($count === 0) {
                break;
            }

            $insertRows = array_map(function (object $row) use ($effectiveMapping, $targetColumns): array {
                $raw = (array) $row;
                $mapped = [];
                foreach ($effectiveMapping as $sourceColumn => $targetColumn) {
                    $mapped[$targetColumn] = $raw[$sourceColumn] ?? null;
                }

                if (in_array('frozen_cash_balance', $targetColumns, true)) {
                    $mapped['frozen_cash_balance'] = 0;
                }

                if (in_array('last_reconciled_at', $targetColumns, true)) {
                    $mapped['last_reconciled_at'] = null;
                }

                if (in_array('migrated_balance_diff', $targetColumns, true)) {
                    $mapped['migrated_balance_diff'] = 0;
                }

                return $mapped;
            }, $rows);

            $totalMigrated += $service->batchInsertIgnore('user_accounts', $insertColumns, $insertRows);
            $offset += $count;

            $this->line("  已处理 {$offset} 行...");
        } while ($count === $batchSize);

        $missingUsers = $service->sourceQuery(
            'SELECT u.id AS user_id, u.created_at, u.updated_at
             FROM users u
             LEFT JOIN user_accounts ua ON u.id = ua.user_id
             WHERE ua.user_id IS NULL
             ORDER BY u.id ASC'
        );

        if ($missingUsers !== []) {
            $zeroRows = array_map(static function (object $row) use ($targetColumns): array {
                $payload = [
                    'user_id' => (int) $row->user_id,
                    'cash_balance' => 0,
                    'credit_limit' => 0,
                    'referral_available_balance' => 0,
                    'referral_frozen_balance' => 0,
                    'referral_withdrawing_balance' => 0,
                    'referral_withdrawn_balance' => 0,
                    'version' => 0,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                ];

                if (in_array('frozen_cash_balance', $targetColumns, true)) {
                    $payload['frozen_cash_balance'] = 0;
                }

                if (in_array('last_reconciled_at', $targetColumns, true)) {
                    $payload['last_reconciled_at'] = null;
                }

                if (in_array('migrated_balance_diff', $targetColumns, true)) {
                    $payload['migrated_balance_diff'] = 0;
                }

                return $payload;
            }, $missingUsers);

            $zeroInsertColumns = array_keys($zeroRows[0] ?? []);

            $totalMigrated += $service->batchInsertIgnore(
                'user_accounts',
                $zeroInsertColumns,
                $zeroRows
            );

            $this->line('  已为缺失账户用户补齐零余额账户：'.count($zeroRows).' 行');
        }

        if (in_array('migrated_balance_diff', $targetColumns, true)) {
            $balanceDiffRows = $service->sourceQuery(
                'SELECT
                    u.id AS user_id,
                    ROUND(COALESCE(u.balance, 0) - COALESCE(ua.cash_balance, 0), 2) AS diff
                 FROM users u
                 INNER JOIN user_accounts ua ON ua.user_id = u.id
                 WHERE ABS(COALESCE(u.balance, 0) - COALESCE(ua.cash_balance, 0)) > 0.01
                 ORDER BY u.id ASC'
            );

            foreach ($balanceDiffRows as $diffRow) {
                $service->targetStatement(
                    'UPDATE user_accounts SET migrated_balance_diff = ? WHERE user_id = ?',
                    [(float) ($diffRow->diff ?? 0), (int) ($diffRow->user_id ?? 0)]
                );
            }
        }

        return $totalMigrated;
    }
}
