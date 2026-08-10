<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\IdentityMigrationService;

class MigrateIdentityAdminUserRolesCommand extends IdentityMigrateBaseCommand
{
    protected $signature = 'migrate:identity:admin-user-roles
        {--dry-run : 只输出统计信息，不写入新库}
        {--plan : 输出更详细的迁移计划}
        {--force : 忽略幂等保护，强制重新迁移}
        {--batch-size=500 : 每批迁移行数}
        {--json : 以 JSON 输出结果}';

    protected $description = '迁移旧库 admin_user_roles 到新库 idc，并补齐 admin_users.role_id 桥接数据';

    protected function sourceTable(): string
    {
        return 'admin_user_roles';
    }

    protected function targetTable(): string
    {
        return 'admin_user_roles';
    }

    protected function migrationName(): string
    {
        return 'identity_admin_user_roles';
    }

    protected function preCheck(IdentityMigrationService $service): ?array
    {
        $sourceCount = $service->sourceCount('admin_user_roles');
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
            '旧 admin_user_roles 行数' => $sourceCount,
            '需补桥接记录数' => (int) ($bridgeRows[0]->cnt ?? 0),
        ];
    }

    protected function migrateRows(array $commonColumns, int $batchSize): int
    {
        /** @var IdentityMigrationService $service */
        $service = $this->laravel->make(IdentityMigrationService::class);

        $sourceRows = $service->sourceQuery(
            'SELECT admin_user_id, role_id
             FROM admin_user_roles
             ORDER BY admin_user_id ASC, role_id ASC'
        );

        $bridgeRows = $service->sourceQuery(
            'SELECT au.id AS admin_user_id, au.role_id
             FROM admin_users au
             LEFT JOIN admin_user_roles aur
               ON au.id = aur.admin_user_id
              AND au.role_id = aur.role_id
             WHERE au.role_id IS NOT NULL
               AND au.role_id != 0
               AND aur.admin_user_id IS NULL
             ORDER BY au.id ASC'
        );

        $merged = [];
        foreach (array_merge($sourceRows, $bridgeRows) as $row) {
            $adminUserId = (int) $row->admin_user_id;
            $roleId = (int) $row->role_id;
            $merged[$adminUserId.':'.$roleId] = [
                'admin_user_id' => $adminUserId,
                'role_id' => $roleId,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $rows = array_values($merged);
        $service->batchUpsert(
            'admin_user_roles',
            ['admin_user_id', 'role_id', 'created_at', 'updated_at'],
            $rows,
            ['admin_user_id', 'role_id'],
            ['updated_at']
        );

        return count($rows);
    }
}
