<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Models\Setting;
use App\Services\System\SettingService;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class DatabaseArchiveLogsCommandTest extends TestCase
{
    private string $temporaryRoot;

    private string $archiveRoot;

    private string $reportRoot;

    private string $defaultsFile;

    /** @var array<string, mixed> */
    private array $originalLogArchiveSettings = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryRoot = storage_path('framework/testing/turaidc-pt-archive-test-'.bin2hex(random_bytes(8)));
        $this->archiveRoot = $this->temporaryRoot.DIRECTORY_SEPARATOR.'archives';
        $this->reportRoot = $this->temporaryRoot.DIRECTORY_SEPARATOR.'reports';
        $this->defaultsFile = $this->temporaryRoot.DIRECTORY_SEPARATOR.'pt-archiver.cnf';
        File::ensureDirectoryExists($this->temporaryRoot);
        file_put_contents($this->defaultsFile, "[client]\nhost=127.0.0.1\nuser=test\npassword=test\n");

        config()->set([
            'log_archive.archive_root' => $this->archiveRoot,
            'log_archive.report_root' => $this->reportRoot,
        ]);

        $this->originalLogArchiveSettings = DB::table('settings')
            ->where('group_key', 'log_archive')
            ->pluck('item_value', 'item_key')
            ->all();
        DB::table('settings')->where('group_key', 'log_archive')->delete();
        Setting::forgetCachedGroup('log_archive');
        Setting::setValues('log_archive', [
            'retention_days' => 45,
            'file_retention_days' => 180,
            'pt_archiver_binary' => $this->temporaryRoot.DIRECTORY_SEPARATOR.'pt-archiver-test',
            'pt_archiver_defaults_file' => $this->defaultsFile,
            'concurrency' => 3,
            'batch_size' => 700,
            'sleep_seconds' => 2,
        ]);
    }

    protected function tearDown(): void
    {
        DB::table('settings')->where('group_key', 'log_archive')->delete();
        if ($this->originalLogArchiveSettings !== []) {
            DB::table('settings')->insert(array_map(
                fn (mixed $value, string $key): array => [
                    'group_key' => 'log_archive',
                    'item_key' => $key,
                    'item_value' => $value,
                ],
                $this->originalLogArchiveSettings,
                array_keys($this->originalLogArchiveSettings),
            ));
        }
        Setting::forgetCachedGroup('log_archive');
        File::deleteDirectory($this->temporaryRoot);

        parent::tearDown();
    }

    public function test_dry_run_covers_seven_log_tables_and_has_no_archive_side_effects(): void
    {
        Process::fake(fn () => Process::result(output: 'pt-archiver test'));

        $exitCode = Artisan::call('db:archive-logs', [
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('dry_run', $payload['mode'] ?? null);
        $this->assertSame('completed', $payload['status'] ?? null);
        $this->assertSame(45, (int) ($payload['retention_days'] ?? 0));
        $this->assertSame(7, count((array) ($payload['ordinary_log_tables'] ?? [])));
        $this->assertContains('activity_logs', (array) $payload['ordinary_log_tables']);
        $this->assertContains('gateway_logs', (array) $payload['ordinary_log_tables']);
        $this->assertNotContains('schedule_task_runs', (array) $payload['ordinary_log_tables']);
        $this->assertDirectoryDoesNotExist($this->archiveRoot);
        $this->assertFileExists((string) $payload['report_path']);
        $this->assertFileExists((string) $payload['execution_log']);

        foreach ((array) $payload['tables'] as $table) {
            $this->assertSame('completed', $table['status'] ?? null);
            $this->assertSame('created_at < DATE_SUB(NOW(), INTERVAL 45 DAY)', $table['where'] ?? null);
            $this->assertSame(0, (int) ($table['deleted_rows'] ?? -1));
        }

        Process::assertRan(function (PendingProcess $process): bool {
            $command = (array) $process->command;

            return in_array('--dry-run', $command, true)
                && in_array('--purge', $command, true)
                && in_array('--output-format=csv', $command, true)
                && in_array('--where=created_at < DATE_SUB(NOW(), INTERVAL 45 DAY)', $command, true)
                && in_array('--limit=700', $command, true)
                && in_array('--sleep=2', $command, true);
        });
    }

    public function test_cli_options_override_admin_log_archive_settings(): void
    {
        Process::fake(fn () => Process::result(output: 'pt-archiver test'));

        $exitCode = Artisan::call('db:archive-logs', [
            '--dry-run' => true,
            '--json' => true,
            '--retain-days' => 30,
            '--file-retain-days' => 90,
            '--concurrency' => 2,
            '--batch-size' => 500,
            '--sleep-seconds' => 0,
        ]);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame(30, (int) ($payload['retention_days'] ?? 0));
        $this->assertSame(90, (int) ($payload['file_retention_days'] ?? 0));
        $this->assertSame(2, (int) ($payload['concurrency'] ?? 0));
        $this->assertSame(500, (int) ($payload['batch_size'] ?? 0));
        $this->assertSame(0, (int) ($payload['sleep_seconds'] ?? -1));

        foreach ((array) ($payload['tables'] ?? []) as $table) {
            $this->assertSame('created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)', $table['where'] ?? null);
        }

        Process::assertRan(function (PendingProcess $process): bool {
            $command = (array) $process->command;

            return in_array('--limit=500', $command, true)
                && in_array('--sleep=0', $command, true)
                && in_array('--where=created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)', $command, true);
        });
    }

    public function test_log_archive_settings_hide_location_fields_and_reject_them_on_save(): void
    {
        Setting::setValue('log_archive', 'archive_root', 'C:\\legacy-nas-archive');

        $settings = app(SettingService::class)->getGroupSettings('log_archive')->keyBy('key');

        $this->assertFalse($settings->has('archive_root'));
        $this->assertFalse($settings->has('report_root'));
        $this->assertFalse($settings->has('mount_point'));
        $this->assertTrue($settings->has('retention_days'));
        $this->assertTrue($settings->has('pt_archiver_binary'));

        try {
            app(SettingService::class)->saveGroupSettings('log_archive', [
                'archive_root' => 'C:\\legacy-nas-archive',
            ]);
            $this->fail('Expected unsupported archive location setting to be rejected.');
        } catch (BusinessException $exception) {
            $this->assertSame(42200, $exception->getErrorCode());
        }
    }

    public function test_command_rejects_unsafe_runtime_setting_paths_before_starting_pt_archiver(): void
    {
        Setting::setValue('log_archive', 'pt_archiver_binary', '../pt-archiver');
        Process::fake();

        $exitCode = Artisan::call('db:archive-logs', [
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('pt-archiver binary must be an absolute path without parent traversal', (string) ($payload['error'] ?? ''));
        Process::assertNothingRan();
    }

    public function test_command_rejects_archive_location_outside_backend_storage(): void
    {
        config()->set('log_archive.archive_root', sys_get_temp_dir().DIRECTORY_SEPARATOR.'turaidc-unmanaged-archive');
        Process::fake();

        $exitCode = Artisan::call('db:archive-logs', [
            '--dry-run' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('archive root must remain inside the backend storage directory', (string) ($payload['error'] ?? ''));
        Process::assertNothingRan();
    }

    public function test_command_rejects_financial_tables_before_starting_pt_archiver(): void
    {
        Process::fake();

        $exitCode = Artisan::call('db:archive-logs', [
            '--dry-run' => true,
            '--json' => true,
            '--table' => ['payments'],
        ]);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('audit/financial table', (string) ($payload['error'] ?? ''));
        Process::assertNothingRan();
    }

    public function test_execute_builds_csv_archive_updates_audit_and_cleans_expired_files(): void
    {
        $logId = (int) DB::table('operation_logs')->insertGetId([
            'action' => 'test.pt_archive',
            'created_at' => now()->subDays(46),
        ]);
        $expiredFile = $this->archiveRoot.DIRECTORY_SEPARATOR.'message_logs'.DIRECTORY_SEPARATOR.'message_logs_20250101.log';
        File::ensureDirectoryExists(dirname($expiredFile));
        file_put_contents($expiredFile, "id,created_at\n1,2025-01-01 00:00:00\n");
        touch($expiredFile, now()->subDays(181)->getTimestamp());

        Process::fake(function (PendingProcess $process) use ($logId) {
            $command = (array) $process->command;
            if (in_array('--version', $command, true)) {
                return Process::result(output: 'pt-archiver 3.7.1');
            }

            $fileArgument = collect($command)->first(
                static fn (string $argument): bool => str_starts_with($argument, '--file='),
            );
            $archiveFile = substr((string) $fileArgument, strlen('--file='));
            File::ensureDirectoryExists(dirname($archiveFile));
            file_put_contents($archiveFile, "id,action,created_at\n{$logId},test.pt_archive,2026-01-01 00:00:00\n");

            return Process::result(output: "DELETE 1\n");
        });

        try {
            $exitCode = Artisan::call('db:archive-logs', [
                '--execute' => true,
                '--json' => true,
                '--table' => ['operation_logs'],
                '--batch-size' => 500,
                '--sleep-seconds' => 1,
            ]);

            $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
            $table = (array) ($payload['tables']['operation_logs'] ?? []);
            $batchId = (string) ($payload['batch_id'] ?? '');
            $archiveFile = (string) ($table['archive_file'] ?? '');

            $this->assertSame(0, $exitCode);
            $this->assertSame('completed', $payload['status'] ?? null);
            $this->assertSame('completed', $table['status'] ?? null);
            $this->assertSame(1, (int) ($table['exported_rows'] ?? 0));
            $this->assertSame(1, (int) ($table['deleted_rows'] ?? 0));
            $this->assertMatchesRegularExpression(
                '/operation_logs_'.preg_quote(now()->format('Ymd'), '/').'_\d{6}_[0-9a-f]{8}\.log$/',
                $archiveFile,
            );
            $this->assertFileExists($archiveFile);
            $handle = fopen($archiveFile, 'rb');
            $this->assertNotFalse($handle);
            $header = fgets($handle);
            fclose($handle);
            $this->assertSame('id,action,created_at', trim((string) $header));
            $this->assertSame(hash_file('sha256', $archiveFile), $table['checksum_sha256'] ?? null);
            $this->assertFileDoesNotExist($expiredFile);
            $this->assertSame(1, (int) ($payload['cleanup']['deleted_files'] ?? 0));
            $this->assertDatabaseHas('archive_audit_logs', [
                'batch_id' => $batchId,
                'table_name' => 'operation_logs',
                'mode' => 'archive',
                'row_count' => 1,
                'status' => 'completed',
            ]);

            Process::assertRan(function (PendingProcess $process): bool {
                $command = (array) $process->command;

                return in_array('--purge', $command, true)
                    && in_array('--commit-each', $command, true)
                    && in_array('--limit=500', $command, true)
                    && in_array('--sleep=1', $command, true)
                    && ! in_array('--dry-run', $command, true);
            });
        } finally {
            DB::table('operation_logs')->where('id', $logId)->delete();
            if (isset($batchId) && $batchId !== '') {
                DB::table('archive_audit_logs')->where('batch_id', $batchId)->delete();
            }
        }
    }

    public function test_execute_records_failed_table_and_returns_failure(): void
    {
        Process::fake(function (PendingProcess $process) {
            return in_array('--version', (array) $process->command, true)
                ? Process::result(output: 'pt-archiver 3.7.1')
                : Process::result(errorOutput: 'simulated archiver failure', exitCode: 2);
        });

        $exitCode = Artisan::call('db:archive-logs', [
            '--execute' => true,
            '--json' => true,
            '--table' => ['gateway_logs'],
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $batchId = (string) ($payload['batch_id'] ?? '');

        try {
            $this->assertSame(1, $exitCode);
            $this->assertSame('failed', $payload['status'] ?? null);
            $this->assertSame('failed', $payload['tables']['gateway_logs']['status'] ?? null);
            $this->assertStringContainsString(
                'simulated archiver failure',
                (string) ($payload['tables']['gateway_logs']['error_message'] ?? ''),
            );
            $this->assertDatabaseHas('archive_audit_logs', [
                'batch_id' => $batchId,
                'table_name' => 'gateway_logs',
                'status' => 'failed',
            ]);
        } finally {
            if ($batchId !== '') {
                DB::table('archive_audit_logs')->where('batch_id', $batchId)->delete();
            }
        }
    }
}
