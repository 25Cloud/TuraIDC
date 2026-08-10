<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\AccountMigrationService;

class MigrateAccountBalanceSnapshotsCommand extends AccountMigrateBaseCommand
{
    protected $signature = 'migrate:account:balance-snapshots
        {--dry-run : 只输出统计信息，不写入新库}
        {--plan : 输出更详细的迁移计划}
        {--force : 忽略幂等保护，强制重新迁移}
        {--batch-size=500 : 每批迁移行数}
        {--json : 以 JSON 输出结果}
        {--snapshot-date= : 指定快照日期，默认今天}';

    protected $description = '根据 idc.user_accounts 生成 account_balance_snapshots';

    protected function sourceTable(): string
    {
        return 'user_accounts';
    }

    protected function targetTable(): string
    {
        return 'account_balance_snapshots';
    }

    protected function migrationName(): string
    {
        return 'account_balance_snapshots';
    }

    protected function preCheck(AccountMigrationService $service): ?array
    {
        $userAccounts = $service->targetCount('user_accounts');

        return [
            '目标库 user_accounts 行数' => $userAccounts,
            '预计快照条数' => $userAccounts * 5,
            '快照日期' => (string) ($this->option('snapshot-date') ?: date('Y-m-d')),
        ];
    }

    protected function migrateRows(array $commonColumns, int $batchSize): int
    {
        $service = $this->laravel->make(AccountMigrationService::class);
        $snapshotDate = (string) ($this->option('snapshot-date') ?: date('Y-m-d'));
        $payloads = $service->deriveBalanceSnapshotPayloads($snapshotDate);

        if ($payloads === []) {
            return 0;
        }

        $columns = array_keys($payloads[0]);
        $chunks = array_chunk($payloads, $batchSize);
        $total = 0;

        foreach ($chunks as $index => $chunk) {
            $total += $service->batchUpsert(
                'account_balance_snapshots',
                $columns,
                $chunk,
                ['user_id', 'account_type', 'snapshot_date'],
                array_values(array_diff($columns, ['user_id', 'account_type', 'snapshot_date']))
            );

            $processed = min(($index + 1) * $batchSize, count($payloads));
            $this->line("  已处理 {$processed} 行...");
        }

        return $total;
    }
}
