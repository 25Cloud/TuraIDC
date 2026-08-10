<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\AccountMigrationService;

class MigrateAccountWithdrawalsCommand extends AccountMigrateBaseCommand
{
    protected $signature = 'migrate:account:withdrawals
        {--dry-run : 只输出统计信息，不写入新库}
        {--plan : 输出更详细的迁移计划}
        {--force : 忽略幂等保护，强制重新迁移}
        {--batch-size=500 : 每批迁移行数}
        {--json : 以 JSON 输出结果}';

    protected $description = '迁移旧库 referral_withdrawals 到 idc.withdrawals';

    protected function sourceTable(): string
    {
        return 'referral_withdrawals';
    }

    protected function targetTable(): string
    {
        return 'withdrawals';
    }

    protected function migrationName(): string
    {
        return 'withdrawals';
    }

    protected function preCheck(AccountMigrationService $service): ?array
    {
        $processed = $service->sourceQuery('SELECT COUNT(*) AS cnt FROM referral_withdrawals WHERE status = 1');
        $partition = $service->deriveWithdrawalPayloadPartition();

        return [
            '已处理提现记录数' => (int) ($processed[0]->cnt ?? 0),
            '可迁移提现记录数' => count($partition['kept']),
            '跳过提现记录数（缺失用户）' => count($partition['skipped_row_ids']),
        ];
    }

    protected function migrateRows(array $commonColumns, int $batchSize): int
    {
        $service = $this->laravel->make(AccountMigrationService::class);
        $partition = $service->deriveWithdrawalPayloadPartition();
        $payloads = $partition['kept'];

        if ($payloads === []) {
            if ($partition['skipped_row_ids'] !== []) {
                $this->warn('全部提现记录均因缺失目标用户而被跳过：'.implode(', ', $partition['skipped_row_ids']));
            }

            return 0;
        }

        if ($partition['skipped_row_ids'] !== []) {
            $this->warn('以下提现记录因缺失目标用户被跳过：'.implode(', ', $partition['skipped_row_ids']));
            $this->warn('涉及缺失用户：'.implode(', ', $partition['skipped_user_ids']));
        }

        $columns = array_keys($payloads[0]);
        $chunks = array_chunk($payloads, $batchSize);
        $total = 0;

        foreach ($chunks as $index => $chunk) {
            $total += $service->batchUpsert(
                'withdrawals',
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
