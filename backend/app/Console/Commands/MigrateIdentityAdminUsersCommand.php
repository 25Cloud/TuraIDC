<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\IdentityMigrationService;

class MigrateIdentityAdminUsersCommand extends IdentityMigrateBaseCommand
{
    protected $signature = 'migrate:identity:admin-users
        {--dry-run : 只输出统计信息，不写入新库}
        {--plan : 输出更详细的迁移计划}
        {--force : 忽略幂等保护，强制重新迁移}
        {--batch-size=500 : 每批迁移行数}
        {--json : 以 JSON 输出结果}';

    protected $description = '迁移旧库 admin_users 到新库 idc';

    protected function sourceTable(): string
    {
        return 'admin_users';
    }

    protected function targetTable(): string
    {
        return 'admin_users';
    }

    protected function migrationName(): string
    {
        return 'identity_admin_users';
    }

    protected function preCheck(IdentityMigrationService $service): ?array
    {
        $bridgeRows = $service->sourceQuery(
            'SELECT COUNT(*) AS cnt
             FROM admin_users au
             LEFT JOIN admin_user_roles aur
               ON au.id = aur.admin_user_id
              AND au.role_id = aur.role_id
             WHERE au.role_id IS NOT NULL
               AND au.role_id != 0
               AND aur.admin_user_id IS NULL'
        );

        return [
            '待桥接 role_id 记录数' => (int) ($bridgeRows[0]->cnt ?? 0),
        ];
    }

    protected function migrateRows(array $commonColumns, int $batchSize): int
    {
        /** @var IdentityMigrationService $service */
        $service = $this->laravel->make(IdentityMigrationService::class);

        $targetColumns = $service->getColumnNames($service->targetConnection(), 'admin_users');
        $mapping = [
            'id' => 'id',
            'username' => 'username',
            'email' => 'email',
            'password' => 'password',
            'nickname' => 'nickname',
            'status' => 'status',
            'last_login_ip' => 'last_login_ip',
            'last_login_at' => 'last_login_at',
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
            $rows = $service->sourcePaginate('admin_users', $offset, $batchSize, $sourceColumns);
            $count = count($rows);

            if ($count === 0) {
                break;
            }

            $insertRows = array_map(function (object $row) use ($effectiveMapping): array {
                $raw = (array) $row;
                $mapped = [];
                foreach ($effectiveMapping as $sourceColumn => $targetColumn) {
                    $mapped[$targetColumn] = $raw[$sourceColumn] ?? null;
                }

                return $mapped;
            }, $rows);

            $totalMigrated += $service->batchInsertIgnore('admin_users', $insertColumns, $insertRows);
            $offset += $count;

            $this->line("  已处理 {$offset} 行...");
        } while ($count === $batchSize);

        return $totalMigrated;
    }
}
