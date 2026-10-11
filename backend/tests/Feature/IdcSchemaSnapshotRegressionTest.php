<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class IdcSchemaSnapshotRegressionTest extends TestCase
{
    public function test_schema_snapshot_file_exists(): void
    {
        // 基线快照随失效迁移工具链清理迁入 database/schema/ 子目录（export_schema_baseline.php 输出路径）
        $schemaPath = base_path('database/schema/mysql-schema.sql');

        $this->assertFileExists($schemaPath);
        $this->assertNotSame('', trim(File::get($schemaPath)));
    }
}
