<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\IdentityMigrationService;

/**
 * 迁移 users 表（用户主表）。
 */
class MigrateIdentityUsersCommand extends IdentityMigrateBaseCommand
{
    protected $signature = 'migrate:identity:users
        {--dry-run : 只输出统计信息，不写入新库}
        {--plan : 输出更详细的迁移计划}
        {--force : 忽略幂等保护，强制重新迁移}
        {--batch-size=500 : 每批迁移行数}
        {--json : 以 JSON 输出结果}';

    protected $description = '迁移旧库 users 到新库 idc';

    protected function sourceTable(): string
    {
        return 'users';
    }

    protected function targetTable(): string
    {
        return 'users';
    }

    protected function migrationName(): string
    {
        return 'identity_users';
    }

    protected function preCheck(IdentityMigrationService $service): ?array
    {
        $deletedCount = $service->sourceQuery('SELECT COUNT(*) AS cnt FROM users WHERE deleted_at IS NOT NULL');
        $dupReferral = $service->sourceQuery(
            'SELECT COUNT(*) AS cnt FROM (
                SELECT referral_code FROM users
                WHERE referral_code IS NOT NULL AND referral_code != \'\'
                GROUP BY referral_code HAVING COUNT(*) > 1
            ) t'
        );

        return [
            '软删除用户数' => (int) ($deletedCount[0]->cnt ?? 0),
            '重复 referral_code' => (int) ($dupReferral[0]->cnt ?? 0),
        ];
    }

    protected function migrateRows(array $commonColumns, int $batchSize): int
    {
        /** @var IdentityMigrationService $service */
        $service = $this->laravel->make(IdentityMigrationService::class);
        $referrerIds = [];
        $totalMigrated = 0;
        $offset = 0;

        do {
            $rows = $service->sourcePaginate('users', $offset, $batchSize, $commonColumns);
            $count = count($rows);

            if ($count === 0) {
                break;
            }

            $insertRows = array_map(static function (object $row) use (&$referrerIds): array {
                $payload = (array) $row;

                if (array_key_exists('referrer_user_id', $payload)) {
                    $rawReferrerId = $payload['referrer_user_id'];
                    $payload['referrer_user_id'] = null;

                    if ($rawReferrerId !== null && (int) $rawReferrerId > 0) {
                        $referrerIds[(int) $payload['id']] = (int) $rawReferrerId;
                    }
                }

                return $payload;
            }, $rows);
            $totalMigrated += $service->batchInsertIgnore('users', $commonColumns, $insertRows);
            $offset += $count;

            $this->line("  已处理 {$offset} 行...");
        } while ($count === $batchSize);

        foreach ($referrerIds as $userId => $referrerUserId) {
            $exists = $service->targetQuery(
                'SELECT COUNT(*) AS cnt FROM users WHERE id = ?',
                [$referrerUserId]
            );

            if ((int) ($exists[0]->cnt ?? 0) === 0) {
                continue;
            }

            $service->targetStatement(
                'UPDATE users SET referrer_user_id = ? WHERE id = ?',
                [$referrerUserId, $userId]
            );
        }

        return $totalMigrated;
    }
}
