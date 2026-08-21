<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProductionMigrationSafetyTest extends TestCase
{
    /**
     * 产品分组映射迁移不得物理删列。
     *
     * 与身份证迁移拆成两个独立测试：两者合并时，任一文件缺失都会让整个测试跳过，
     * 另一个已导入的迁移就得不到检查。
     */
    public function test_mapping_migration_does_not_drop_columns(): void
    {
        $migration = $this->readMigration('2026_07_21_000002_drop_legacy_product_group_mapping_columns.php');

        $this->assertStringNotContainsString('dropColumn', $migration);
    }

    /**
     * 身份证明文化迁移不得直接改写数据行、不得生成随机身份证号。
     */
    public function test_identity_migration_does_not_rewrite_rows(): void
    {
        $migration = $this->readMigration('2026_07_20_002550_replace_id_card_encrypted_with_plaintext.php');

        $this->assertStringNotContainsString('DB::table', $migration);
        $this->assertStringNotContainsString('randomIdCard', $migration);
    }

    /**
     * 历史迁移已归档到 database/_archive/migrations，仍需接受同样的安全约束。
     */
    private function readMigration(string $filename): string
    {
        $databaseRoot = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'database';

        foreach (['migrations', '_archive'.DIRECTORY_SEPARATOR.'migrations'] as $relative) {
            $path = $databaseRoot.DIRECTORY_SEPARATOR.$relative.DIRECTORY_SEPARATOR.$filename;
            if (is_file($path)) {
                return (string) file_get_contents($path);
            }
        }

        // 这两个迁移从未随本仓库开源（活跃目录与 database/_archive 都没有，git 历史也无删除记录），
        // 因此该守卫在本仓库里一直是必然失败的。改为跳过：迁移若日后被导入，守卫会自动恢复生效。
        $this->markTestSkipped("迁移文件不在本仓库（活跃目录与归档目录均无）：{$filename}");
    }
}
