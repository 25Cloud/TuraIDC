<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\AccountMigrationService;

class MigrateAccountLedgersCommand extends AccountMigrateBaseCommand
{
    protected $signature = 'migrate:account:ledgers
        {--dry-run : 只输出统计信息，不写入新库}
        {--plan : 输出更详细的迁移计划}
        {--force : 忽略幂等保护，强制重新迁移}
        {--batch-size=500 : 每批迁移行数}
        {--json : 以 JSON 输出结果}';

    protected $description = '迁移旧库 balance_logs / referral_account_logs 到 idc.account_ledgers';

    protected function sourceTable(): string
    {
        return 'balance_logs';
    }

    protected function targetTable(): string
    {
        return 'account_ledgers';
    }

    protected function migrationName(): string
    {
        return 'account_ledgers';
    }

    protected function preCheck(AccountMigrationService $service): ?array
    {
        $balanceLogs = $service->sourceCount('balance_logs');
        $referralLogs = $service->sourceCount('referral_account_logs');
        $partition = $service->deriveAccountLedgerPayloadPartition();
        $derivedTotal = count($partition['kept']) + count($partition['skipped_row_ids']);
        $openingBalanceCount = max(0, $derivedTotal - $balanceLogs - $referralLogs);

        return [
            'cash 流水条数' => $balanceLogs,
            'referral 流水条数' => $referralLogs,
            '期初余额账本条数' => $openingBalanceCount,
            '可迁移账本条数' => count($partition['kept']),
            '跳过账本条数（缺失用户）' => count($partition['skipped_row_ids']),
            '合计派生账本条数' => $derivedTotal,
        ];
    }

    protected function migrateRows(array $commonColumns, int $batchSize): int
    {
        $service = $this->laravel->make(AccountMigrationService::class);
        $partition = $service->deriveAccountLedgerPayloadPartition();
        $payloads = $partition['kept'];

        if ($payloads === []) {
            if ($partition['skipped_row_ids'] !== []) {
                $this->warn('全部账本记录均因缺失目标用户而被跳过：'.implode(', ', $partition['skipped_row_ids']));
            }

            return 0;
        }

        if ($partition['skipped_row_ids'] !== []) {
            $this->warn('以下账本记录因缺失目标用户被跳过：'.implode(', ', $partition['skipped_row_ids']));
            $this->warn('涉及缺失用户：'.implode(', ', $partition['skipped_user_ids']));
        }

        $columns = array_keys($payloads[0]);
        $chunks = array_chunk($payloads, $batchSize);
        $total = 0;

        foreach ($chunks as $index => $chunk) {
            $total += $service->batchUpsert(
                'account_ledgers',
                $columns,
                $chunk,
                ['id'],
                array_values(array_diff($columns, ['id']))
            );

            $processed = min(($index + 1) * $batchSize, count($payloads));
            $this->line("  已处理 {$processed} 行...");
        }

        return $total;
    }
}
