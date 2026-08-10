<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\AccountMigrationService;

class MigrateAccountReferralRelationsCommand extends AccountMigrateBaseCommand
{
    protected $signature = 'migrate:account:referral-relations
        {--dry-run : 只输出统计信息，不写入新库}
        {--plan : 输出更详细的迁移计划}
        {--force : 忽略幂等保护，强制重新迁移}
        {--batch-size=500 : 每批迁移行数}
        {--json : 以 JSON 输出结果}';

    protected $description = '根据旧库 users 推荐关系迁移到 idc.referral_relations';

    protected function sourceTable(): string
    {
        return 'users';
    }

    protected function targetTable(): string
    {
        return 'referral_relations';
    }

    protected function migrationName(): string
    {
        return 'referral_relations';
    }

    protected function preCheck(AccountMigrationService $service): ?array
    {
        $stats = $service->referralRelationMigrationStats();

        return [
            '可派生推荐关系数' => $stats['total'],
            '可迁移推荐关系数' => $stats['derived'],
            '跳过记录数（推荐人为空或 0）' => $stats['skipped_invalid_referrer'],
            '跳过记录数（自引用）' => $stats['skipped_self_referral'],
            '跳过记录数（软删除用户）' => $stats['skipped_deleted_users'],
            '跳过记录数（目标用户缺失）' => $stats['skipped_missing_target_users'],
        ];
    }

    protected function migrateRows(array $commonColumns, int $batchSize): int
    {
        $service = $this->laravel->make(AccountMigrationService::class);
        $partition = $service->deriveReferralRelationPayloadPartition();
        $payloads = $partition['kept'];

        if ($payloads === []) {
            if ($partition['skipped_referred_user_ids'] !== [] || $partition['skipped_referrer_user_ids'] !== []) {
                $this->warn('全部推荐关系均因目标用户缺失被跳过。');
            }

            return 0;
        }

        if ($partition['skipped_referred_user_ids'] !== []) {
            $this->warn('以下被推荐用户因目标用户缺失被跳过：'.implode(', ', $partition['skipped_referred_user_ids']));
        }

        if ($partition['skipped_referrer_user_ids'] !== []) {
            $this->warn('以下推荐人因目标用户缺失被跳过：'.implode(', ', $partition['skipped_referrer_user_ids']));
        }

        $columns = array_keys($payloads[0]);
        $chunks = array_chunk($payloads, $batchSize);
        $total = 0;

        foreach ($chunks as $index => $chunk) {
            $total += $service->batchUpsert(
                'referral_relations',
                $columns,
                $chunk,
                ['referred_user_id'],
                array_values(array_diff($columns, ['referred_user_id']))
            );

            $processed = min(($index + 1) * $batchSize, count($payloads));
            $this->line("  已处理 {$processed} 行...");
        }

        return $total;
    }
}
