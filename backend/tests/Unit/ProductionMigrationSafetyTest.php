<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ProductionMigrationSafetyTest extends TestCase
{
    public function test_candidate_migrations_do_not_destroy_mapping_or_identity_data(): void
    {
        $mappingMigration = $this->readMigration('2026_07_21_000002_drop_legacy_product_group_mapping_columns.php');
        $identityMigration = $this->readMigration('2026_07_20_002550_replace_id_card_encrypted_with_plaintext.php');

        $this->assertIsString($mappingMigration);
        $this->assertIsString($identityMigration);
        $this->assertStringNotContainsString('dropColumn', $mappingMigration);
        $this->assertStringNotContainsString('DB::table', $identityMigration);
        $this->assertStringNotContainsString('randomIdCard', $identityMigration);
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

        $this->fail("迁移文件未找到（活跃目录与归档目录均无）：{$filename}");
    }
}
