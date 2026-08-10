<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\AccountMigrationService;

class MigrateAccountReferralRewardsCommand extends AccountMigrateBaseCommand
{
    protected $signature = 'migrate:account:referral-rewards
        {--dry-run : 只输出统计信息，不写入新库}
        {--plan : 输出更详细的迁移计划}
        {--force : 忽略幂等保护，强制重新迁移}
        {--batch-size=500 : 每批迁移行数}
        {--json : 以 JSON 输出结果}';

    protected $description = '迁移旧库 referral_rewards 到 idc.referral_rewards';

    protected function sourceTable(): string
    {
        return 'referral_rewards';
    }

    protected function targetTable(): string
    {
        return 'referral_rewards';
    }

    protected function migrationName(): string
    {
        return 'referral_rewards';
    }

    protected function preCheck(AccountMigrationService $service): ?array
    {
        $stats = $service->referralRewardMigrationStats();

        return [
            '返佣总记录数' => $stats['total'],
            '可派生迁移记录数' => $stats['derived'],
            '缺少旧发票映射记录数' => $stats['skipped_missing_legacy_invoice'],
            '缺少新发票落库记录数' => $stats['skipped_missing_target_invoice'],
        ];
    }

    protected function migrateRows(array $commonColumns, int $batchSize): int
    {
        $service = $this->laravel->make(AccountMigrationService::class);
        $payloads = $service->deriveReferralRewardPayloads();

        if ($payloads === []) {
            return 0;
        }

        $columns = array_keys($payloads[0]);
        $chunks = array_chunk($payloads, $batchSize);
        $total = 0;

        foreach ($chunks as $index => $chunk) {
            $total += $service->batchUpsert(
                'referral_rewards',
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
