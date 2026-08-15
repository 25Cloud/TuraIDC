<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class TraceIdBackfillCommandTest extends BaseTestCase
{
    private ?string $createdBackupPath = null;

    public function createApplication()
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('sqlite');
        DB::reconnect('sqlite');
        app('db')->setDefaultConnection('sqlite');

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        if ($this->createdBackupPath && File::exists($this->createdBackupPath)) {
            File::delete($this->createdBackupPath);
        }

        parent::tearDown();
    }

    public function test_dry_run_reports_missing_trace_ids_without_writing(): void
    {
        $this->seedTraceRows();

        $exitCode = Artisan::call('db:backfill-trace-id', [
            '--dry-run' => true,
            '--json' => true,
            '--sample' => 1,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertTrue((bool) $payload['dry_run']);
        $this->assertSame(1, (int) $payload['summary']['invoices']);
        $this->assertSame(1, (int) $payload['summary']['payments']);
        $this->assertSame(1, (int) $payload['summary']['services']);
        $this->assertSame(1, (int) $payload['summary']['account_transactions']);
        $this->assertDatabaseHas('invoices', ['id' => 1, 'trace_id' => null]);
    }

    public function test_execute_backs_up_and_backfills_missing_trace_ids(): void
    {
        $this->seedTraceRows();

        $exitCode = Artisan::call('db:backfill-trace-id', [
            '--execute' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $this->createdBackupPath = (string) ($payload['backup_path'] ?? '');

        $this->assertSame(0, $exitCode);
        $this->assertFalse((bool) $payload['dry_run']);
        $this->assertFileExists($this->createdBackupPath);
        $this->assertSame(1, (int) $payload['updated']['invoices']);
        $this->assertSame(0, (int) $payload['remaining_missing']['invoices']);
        $this->assertDatabaseHas('invoices', ['id' => 1, 'trace_id' => 'legacy-invoices-1']);
        $this->assertDatabaseHas('payments', ['id' => 1, 'trace_id' => 'legacy-payments-1']);
        $this->assertDatabaseHas('services', ['id' => 1, 'trace_id' => 'legacy-services-1']);
        $this->assertDatabaseHas('account_transactions', ['id' => 1, 'trace_id' => 'legacy-account_transactions-1']);
        $this->assertDatabaseHas('invoices', ['id' => 2, 'trace_id' => 'existing-invoice']);
    }

    private function createSchema(): void
    {
        foreach (['account_transactions', 'services', 'payments', 'invoices'] as $table) {
            Schema::dropIfExists($table);
            Schema::create($table, function (Blueprint $table): void {
                $table->id();
                $table->string('trace_id')->nullable();
                $table->timestamps();
            });
        }
    }

    private function seedTraceRows(): void
    {
        foreach (['invoices', 'payments', 'services', 'account_transactions'] as $table) {
            DB::table($table)->insert([
                ['id' => 1, 'trace_id' => null, 'created_at' => now(), 'updated_at' => now()],
                ['id' => 2, 'trace_id' => 'existing-'.rtrim($table, 's'), 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }
}
