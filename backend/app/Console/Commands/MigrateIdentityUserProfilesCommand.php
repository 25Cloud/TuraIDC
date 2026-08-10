<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\IdentityMigrationService;

/**
 * 从旧库 users 表提取用户资料，迁移到新库 user_profiles。
 */
class MigrateIdentityUserProfilesCommand extends IdentityMigrateBaseCommand
{
    protected $signature = 'migrate:identity:user-profiles
        {--dry-run : 只输出统计信息，不写入新库}
        {--plan : 输出更详细的迁移计划}
        {--force : 忽略幂等保护，强制重新迁移}
        {--batch-size=500 : 每批迁移行数}
        {--json : 以 JSON 输出结果}';

    protected $description = '从旧库 users 表提取用户资料，迁移到新库 user_profiles';

    protected function sourceTable(): string
    {
        return 'users';
    }

    protected function targetTable(): string
    {
        return 'user_profiles';
    }

    protected function migrationName(): string
    {
        return 'identity_user_profiles';
    }

    protected function preCheck(IdentityMigrationService $service): ?array
    {
        $exists = $service->targetQuery(
            "SELECT COUNT(*) AS cnt FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'user_profiles'"
        );

        if ((int) ($exists[0]->cnt ?? 0) === 0) {
            return [
                'user_profiles 表' => '不存在于新库，需先创建迁移文件',
            ];
        }

        $stats = $service->sourceQuery(
            'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN nickname IS NOT NULL AND nickname != "" THEN 1 ELSE 0 END) AS has_nickname,
                SUM(CASE WHEN company IS NOT NULL AND company != "" THEN 1 ELSE 0 END) AS has_company,
                SUM(CASE WHEN qq IS NOT NULL AND qq != "" THEN 1 ELSE 0 END) AS has_qq,
                SUM(CASE WHEN real_name IS NOT NULL AND real_name != "" THEN 1 ELSE 0 END) AS has_real_name,
                SUM(CASE WHEN is_verified = 1 THEN 1 ELSE 0 END) AS verified
             FROM users'
        );

        $summary = $stats[0] ?? null;
        if ($summary === null) {
            return null;
        }

        return [
            '总用户数' => (int) $summary->total,
            '有昵称' => (int) $summary->has_nickname,
            '有公司名' => (int) $summary->has_company,
            '有QQ' => (int) $summary->has_qq,
            '有实名' => (int) $summary->has_real_name,
            '已认证' => (int) $summary->verified,
        ];
    }

    protected function migrateRows(array $commonColumns, int $batchSize): int
    {
        /** @var IdentityMigrationService $service */
        $service = $this->laravel->make(IdentityMigrationService::class);

        $targetColumns = $service->getColumnNames($service->targetConnection(), 'user_profiles');
        $mapping = [
            'id' => 'user_id',
            'nickname' => 'nickname',
            'company' => 'company',
            'qq' => 'qq',
            'real_name' => 'real_name',
            'id_card' => 'id_card',
            'alipay_real_name' => 'alipay_real_name',
            'alipay_account' => 'alipay_account',
            'admin_note' => 'admin_note',
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
        if ($sourceColumns === []) {
            $this->warn('未识别到 user_profiles 可迁移字段，跳过迁移。');

            return 0;
        }

        $insertColumns = array_values(array_unique(array_values($effectiveMapping)));
        $totalMigrated = 0;
        $offset = 0;

        do {
            $rows = $service->sourcePaginate('users', $offset, $batchSize, $sourceColumns);
            $count = count($rows);

            if ($count === 0) {
                break;
            }

            $insertRows = array_map(function (object $row) use ($effectiveMapping): array {
                $raw = (array) $row;
                $mapped = [];
                foreach ($effectiveMapping as $sourceColumn => $targetColumn) {
                    $value = $raw[$sourceColumn] ?? null;

                    if (in_array($targetColumn, [
                        'nickname',
                        'company',
                        'qq',
                        'real_name',
                        'id_card',
                        'alipay_real_name',
                        'alipay_account',
                    ], true)) {
                        $value = $value !== null ? (string) $value : '';
                    }

                    $mapped[$targetColumn] = $value;
                }

                return $mapped;
            }, $rows);

            $totalMigrated += $service->batchInsertIgnore('user_profiles', $insertColumns, $insertRows);
            $offset += $count;

            $this->line("  已处理 {$offset} 行...");
        } while ($count === $batchSize);

        return $totalMigrated;
    }
}
