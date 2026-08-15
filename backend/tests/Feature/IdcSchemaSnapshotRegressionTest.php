<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

class IdcSchemaSnapshotRegressionTest extends TestCase
{
    public function test_schema_snapshot_file_exists(): void
    {
        $schemaPath = base_path('database/schema.sql');

        $this->assertFileExists($schemaPath);
        $this->assertNotSame('', trim(File::get($schemaPath)));
    }
}
