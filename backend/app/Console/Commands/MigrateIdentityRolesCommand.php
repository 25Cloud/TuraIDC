<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\IdentityMigrationService;

class MigrateIdentityRolesCommand extends IdentityMigrateBaseCommand
{
    protected $signature = 'migrate:identity:roles
        {--dry-run : 只输出统计信息，不写入新库}
        {--plan : 输出更详细的迁移计划}
        {--force : 忽略幂等保护，强制重新迁移}
        {--batch-size=500 : 每批迁移行数}
        {--json : 以 JSON 输出结果}';

    protected $description = '迁移旧库 roles 到新库 idc';

    protected function sourceTable(): string
    {
        return 'roles';
    }

    protected function targetTable(): string
    {
        return 'roles';
    }

    protected function migrationName(): string
    {
        return 'identity_roles';
    }

    protected function preCheck(IdentityMigrationService $service): ?array
    {
        $invalidRows = $service->sourceQuery(
            'SELECT COUNT(*) AS cnt
             FROM roles
             WHERE JSON_VALID(permissions) = 0'
        );

        return [
            '非法 permissions JSON 记录数' => (int) ($invalidRows[0]->cnt ?? 0),
        ];
    }

    protected function migrateRows(array $commonColumns, int $batchSize): int
    {
        /** @var IdentityMigrationService $service */
        $service = $this->laravel->make(IdentityMigrationService::class);

        $rows = $service->sourceQuery(
            'SELECT id, name, label, permissions, created_at, updated_at
             FROM roles
             ORDER BY id ASC'
        );

        $insertRows = array_map(static function (object $row): array {
            $permissions = (string) ($row->permissions ?? '[]');

            return [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'label' => $row->label !== null ? (string) $row->label : null,
                'permissions_json' => json_encode(
                    json_decode($permissions, true, 512, JSON_THROW_ON_ERROR),
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                ),
                'status' => 1,
                'created_at' => $row->created_at,
                'updated_at' => $row->updated_at,
            ];
        }, $rows);

        $service->batchUpsert(
            'roles',
            ['id', 'name', 'label', 'permissions_json', 'status', 'created_at', 'updated_at'],
            $insertRows,
            ['id'],
            ['name', 'label', 'permissions_json', 'status', 'created_at', 'updated_at']
        );

        return count($insertRows);
    }
}
