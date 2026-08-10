<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\IdentityMigrationService;

/**
 * 迁移 member_levels 表（会员等级/分销等级配置）。
 */
class MigrateIdentityMemberLevelsCommand extends IdentityMigrateBaseCommand
{
    protected $signature = 'migrate:identity:member-levels
        {--dry-run : 只输出统计信息，不写入新库}
        {--plan : 输出更详细的迁移计划}
        {--force : 忽略幂等保护，强制重新迁移}
        {--batch-size=500 : 每批迁移行数}
        {--json : 以 JSON 输出结果}';

    protected $description = '迁移旧库 member_levels 到新库 idc';

    protected function sourceTable(): string
    {
        return 'member_levels';
    }

    protected function targetTable(): string
    {
        return 'member_levels';
    }

    protected function migrationName(): string
    {
        return 'identity_member_levels';
    }

    protected function migrateRows(array $commonColumns, int $batchSize): int
    {
        /** @var IdentityMigrationService $service */
        $service = $this->laravel->make(IdentityMigrationService::class);
        $totalMigrated = 0;
        $offset = 0;

        do {
            $rows = $service->sourcePaginate('member_levels', $offset, $batchSize, $commonColumns);
            $count = count($rows);

            if ($count === 0) {
                break;
            }

            $insertRows = array_map(static fn (object $row) => (array) $row, $rows);
            $totalMigrated += $service->batchInsertIgnore('member_levels', $commonColumns, $insertRows);
            $offset += $count;

            $this->line("  已处理 {$offset} 行...");
        } while ($count === $batchSize);

        return $totalMigrated;
    }
}
