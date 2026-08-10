<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Models\ArchiveAuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

class LogArchiveService
{
    public function __construct(private readonly SettingService $settings) {}

    /**
     * @param  array{tables?: list<string>, retention_days?: int|null, file_retention_days?: int|null, pt_archiver_binary?: string|null, pt_archiver_defaults_file?: string|null, concurrency?: int|null, batch_size?: int|null, sleep_seconds?: int|null}  $options
     * @return array<string, mixed>
     */
    public function dryRun(array $options = []): array
    {
        $settings = $this->resolveSettings($options);
        $policies = $this->resolvePolicies($options['tables'] ?? []);
        $this->assertPreconditions($policies, $settings, false);

        $report = $this->buildReport('dry_run', $policies, $settings);
        $this->writeReport($report);
        $this->appendExecutionEvent($report, 'run_started');

        try {
            $report = $this->runPtArchiver($report, $settings, true);
            $report = $this->finishReport($report);
            $this->writeReport($report);
            $this->appendExecutionEvent($report, 'run_finished');

            return $report;
        } catch (Throwable $exception) {
            $report['status'] = 'failed';
            $report['error_message'] = mb_substr($exception->getMessage(), 0, 500);
            $report['finished_at'] = now()->toISOString();
            $this->writeReport($report);
            $this->appendExecutionEvent($report, 'run_failed');

            throw $exception;
        }
    }

    /**
     * @param  array{tables?: list<string>, retention_days?: int|null, file_retention_days?: int|null, pt_archiver_binary?: string|null, pt_archiver_defaults_file?: string|null, concurrency?: int|null, batch_size?: int|null, sleep_seconds?: int|null}  $options
     * @return array<string, mixed>
     */
    public function archive(array $options = []): array
    {
        $settings = $this->resolveSettings($options);
        $policies = $this->resolvePolicies($options['tables'] ?? []);
        $lock = $this->acquireGlobalLock();

        try {
            $this->assertPreconditions($policies, $settings, true);
            $report = $this->buildReport('archive', $policies, $settings);
            $audits = [];

            foreach (array_keys($policies) as $table) {
                $this->ensureDirectory(dirname((string) $report['tables'][$table]['archive_file']));
                $audits[$table] = $this->createAuditLog((string) $report['batch_id'], $table);
            }

            $this->writeReport($report);
            $this->appendExecutionEvent($report, 'run_started');

            try {
                $report = $this->runPtArchiver($report, $settings, false, $audits);
                $report['cleanup'] = $this->cleanupExpiredArchives(
                    (string) $settings['archive_root'],
                    (int) $settings['file_retention_days'],
                );
                $report = $this->finishReport($report);
                if ($report['cleanup']['errors'] !== []) {
                    $report['status'] = 'failed';
                }
                $this->writeReport($report);
                $this->appendExecutionEvent($report, 'run_finished');

                return $report;
            } catch (Throwable $exception) {
                foreach ($audits as $table => $audit) {
                    if ((string) ($report['tables'][$table]['status'] ?? 'running') === 'running') {
                        $this->finishAuditLog($audit, [
                            'status' => 'failed',
                            'error_message' => mb_substr($exception->getMessage(), 0, 500),
                        ]);
                    }
                }

                $report['status'] = 'failed';
                $report['error_message'] = mb_substr($exception->getMessage(), 0, 500);
                $report['finished_at'] = now()->toISOString();
                $this->writeReport($report);
                $this->appendExecutionEvent($report, 'run_failed');

                throw $exception;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param  array<string, string>  $policies
     * @param  array<string, mixed>  $settings
     */
    private function assertPreconditions(array $policies, array $settings, bool $execute): void
    {
        $database = (string) DB::getDatabaseName();
        if (! preg_match('/^[A-Za-z0-9_$-]+$/', $database)) {
            throw new RuntimeException('Database name contains unsupported characters.');
        }

        foreach (array_keys($policies) as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Required log table does not exist: {$table}");
            }
            if (! Schema::hasColumn($table, 'id') || ! Schema::hasColumn($table, 'created_at')) {
                throw new RuntimeException("Log table must contain id and created_at columns: {$table}");
            }

            if (DB::getDriverName() === 'mysql') {
                $metadata = DB::selectOne(
                    'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
                    [$database, $table],
                );
                if (strtoupper((string) ($metadata->ENGINE ?? '')) !== 'INNODB') {
                    throw new RuntimeException("Log table must use InnoDB: {$table}");
                }
            }
        }

        $defaultsFile = (string) $settings['defaults_file'];
        if (! is_file($defaultsFile) || ! is_readable($defaultsFile)) {
            throw new RuntimeException("pt-archiver defaults file is not readable: {$defaultsFile}");
        }
        if (str_contains($defaultsFile, ',')) {
            throw new RuntimeException('pt-archiver defaults file path cannot contain commas.');
        }

        $version = Process::timeout(10)->run([(string) $settings['binary'], '--version']);
        if ($version->failed()) {
            $message = trim($version->errorOutput() ?: $version->output());
            throw new RuntimeException('pt-archiver is unavailable'.($message !== '' ? ": {$message}" : '.'));
        }

        $this->ensureDirectory((string) $settings['report_root']);
        $this->ensureDirectory(dirname((string) $settings['lock_path']));

        if ($execute) {
            $this->ensureDirectory((string) $settings['archive_root']);
        }

    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $settings
     * @param  array<string, ArchiveAuditLog>  $audits
     * @return array<string, mixed>
     */
    private function runPtArchiver(array $report, array $settings, bool $dryRun, array $audits = []): array
    {
        $tables = array_keys((array) $report['tables']);

        foreach (array_chunk($tables, (int) $settings['concurrency']) as $group) {
            $commands = [];
            foreach ($group as $table) {
                $commands[$table] = $this->buildCommand(
                    $table,
                    (string) $report['tables'][$table]['archive_file'],
                    $settings,
                    $dryRun,
                );
                $report['tables'][$table]['status'] = 'running';
                $report['tables'][$table]['started_at'] = now()->toISOString();
            }
            $this->writeReport($report);

            $results = Process::concurrently(function (Pool $pool) use ($commands): void {
                foreach ($commands as $table => $command) {
                    $pool->as($table)->forever()->command($command);
                }
            });

            foreach ($group as $table) {
                $result = $results[$table];
                $output = trim($result->output().PHP_EOL.$result->errorOutput());
                $tableReport = (array) $report['tables'][$table];
                $tableReport['exit_code'] = $result->exitCode();
                $tableReport['tool_output'] = $this->truncate($output, 8000);
                $tableReport['finished_at'] = now()->toISOString();

                if ($dryRun) {
                    $tableReport['status'] = $result->successful() ? 'completed' : 'failed';
                    $tableReport['error_message'] = $result->successful()
                        ? null
                        : $this->failureMessage($output, $result->exitCode());
                } else {
                    $tableReport = $this->finishArchiveTable($table, $tableReport, $result->successful(), $output);
                    $audit = $audits[$table];
                    $this->finishAuditLog($audit, [
                        'row_count' => (int) $tableReport['deleted_rows'],
                        'file_path' => (string) $tableReport['archive_file'],
                        'file_size' => $tableReport['file_size'],
                        'checksum_sha256' => $tableReport['checksum_sha256'],
                        'status' => (string) $tableReport['status'],
                        'error_message' => $tableReport['error_message'],
                    ]);
                }

                $report['tables'][$table] = $tableReport;
                $this->appendExecutionEvent($report, 'table_finished', $table, $tableReport);
            }

            $report = $this->refreshTotals($report);
            $this->writeReport($report);
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $tableReport
     * @return array<string, mixed>
     */
    private function finishArchiveTable(string $table, array $tableReport, bool $successful, string $output): array
    {
        $remaining = (int) DB::table($table)
            ->whereRaw($this->archiveWhere((int) ($tableReport['retention_days'] ?? 30)))
            ->count();
        $countDifference = max(0, (int) $tableReport['eligible_rows'] - $remaining);
        $toolCount = $this->parseDeletedRows($output);
        $deletedRows = $toolCount ?? $countDifference;
        $archiveFile = (string) $tableReport['archive_file'];
        $fileExists = is_file($archiveFile);
        $fileSize = $fileExists ? filesize($archiveFile) : false;
        $checksum = $fileExists ? hash_file('sha256', $archiveFile) : false;
        $headerValid = $fileExists && $this->hasHeader($archiveFile);

        if ($fileExists) {
            @chmod($archiveFile, 0640);
        }

        $tableReport['eligible_remaining'] = $remaining;
        $tableReport['exported_rows'] = $deletedRows;
        $tableReport['deleted_rows'] = $deletedRows;
        $tableReport['file_size'] = $fileSize === false ? null : $fileSize;
        $tableReport['checksum_sha256'] = $checksum === false ? null : $checksum;
        $tableReport['status'] = $successful && $fileExists && $headerValid ? 'completed' : 'failed';

        if ($tableReport['status'] === 'failed') {
            $tableReport['error_message'] = ! $successful
                ? $this->failureMessage($output, (int) $tableReport['exit_code'])
                : (! $fileExists
                    ? 'pt-archiver completed without creating the archive file.'
                    : 'Archive file does not contain a valid header row.');
        } else {
            $tableReport['error_message'] = null;
        }

        return $tableReport;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    private function buildCommand(string $table, string $archiveFile, array $settings, bool $dryRun): array
    {
        $database = (string) DB::getDatabaseName();
        $pidFile = dirname((string) $settings['lock_path']).DIRECTORY_SEPARATOR."pt-archiver-{$table}.pid";
        $command = [
            (string) $settings['binary'],
            '--source=F='.(string) $settings['defaults_file'].",D={$database},t={$table},i=PRIMARY",
            '--where='.$this->archiveWhere((int) $settings['retention_days']),
            '--file='.$archiveFile,
            '--output-format=csv',
            '--header',
            '--purge',
            '--limit='.(string) $settings['batch_size'],
            '--commit-each',
            '--sleep='.(string) $settings['sleep_seconds'],
            '--progress='.(string) $settings['batch_size'],
            '--retries=3',
            '--statistics',
            '--why-quit',
            '--charset=utf8mb4',
            '--pid='.$pidFile,
            '--no-version-check',
        ];

        if ($dryRun) {
            $command[] = '--dry-run';
        }

        return $command;
    }

    /**
     * @param  array<string, string>  $policies
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function buildReport(string $mode, array $policies, array $settings): array
    {
        $batchId = Str::uuid()->toString();
        $now = CarbonImmutable::now();
        $runDate = $now->format('Ymd');
        $reportPath = rtrim((string) $settings['report_root'], DIRECTORY_SEPARATOR.'/\\')
            .DIRECTORY_SEPARATOR.'run_'.$now->format('Ymd_His').'_'.substr(str_replace('-', '', $batchId), 0, 8).'.json';
        $executionLog = rtrim((string) $settings['report_root'], DIRECTORY_SEPARATOR.'/\\')
            .DIRECTORY_SEPARATOR.'archive-'.$now->format('Y-m-d').'.log';
        $archiveWhere = $this->archiveWhere((int) $settings['retention_days']);
        $tables = [];

        foreach ($policies as $table => $description) {
            $eligibleRows = (int) DB::table($table)->whereRaw($archiveWhere)->count();
            $archiveFile = rtrim((string) $settings['archive_root'], DIRECTORY_SEPARATOR.'/\\')
                .DIRECTORY_SEPARATOR.$table
                .DIRECTORY_SEPARATOR.$table.'_'.$runDate.'.log';

            $tables[$table] = [
                'description' => $description,
                'date_column' => 'created_at',
                'retention_days' => (int) $settings['retention_days'],
                'where' => $archiveWhere,
                'cutoff' => $now->subDays((int) $settings['retention_days'])->toDateTimeString(),
                'total_rows' => (int) DB::table($table)->count(),
                'eligible_rows' => $eligibleRows,
                'eligible_remaining' => $eligibleRows,
                'oldest_at' => DB::table($table)->min('created_at'),
                'newest_at' => DB::table($table)->max('created_at'),
                'archive_file' => $archiveFile,
                'file_size' => null,
                'checksum_sha256' => null,
                'exported_rows' => 0,
                'deleted_rows' => 0,
                'exit_code' => null,
                'tool_output' => null,
                'status' => 'pending',
                'error_message' => null,
                'started_at' => null,
                'finished_at' => null,
            ];
        }

        return [
            'batch_id' => $batchId,
            'mode' => $mode,
            'status' => 'running',
            'generated_at' => $now->toISOString(),
            'finished_at' => null,
            'database' => DB::getDatabaseName(),
            'retention_days' => (int) $settings['retention_days'],
            'file_retention_days' => (int) $settings['file_retention_days'],
            'batch_size' => (int) $settings['batch_size'],
            'sleep_seconds' => (int) $settings['sleep_seconds'],
            'concurrency' => (int) $settings['concurrency'],
            'archive_root' => (string) $settings['archive_root'],
            'report_path' => $reportPath,
            'execution_log' => $executionLog,
            'ordinary_log_tables' => array_keys($policies),
            'excluded_audit_tables' => array_values((array) config('log_archive.excluded_tables', [])),
            'tables' => $tables,
            'totals' => [
                'eligible_rows' => array_sum(array_column($tables, 'eligible_rows')),
                'exported_rows' => 0,
                'deleted_rows' => 0,
                'failed_tables' => 0,
            ],
            'cleanup' => [
                'deleted_files' => 0,
                'deleted_bytes' => 0,
                'errors' => [],
            ],
            'error_message' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function resolveSettings(array $options): array
    {
        $runtime = $this->settings->getLogArchiveConfig();
        $archiveRoot = trim((string) config('log_archive.archive_root'));
        $reportRoot = trim((string) config('log_archive.report_root'));
        $binary = trim((string) ($options['pt_archiver_binary'] ?? $runtime['pt_archiver_binary']));
        $defaultsFile = trim((string) ($options['pt_archiver_defaults_file'] ?? $runtime['pt_archiver_defaults_file']));

        foreach (['archive root' => $archiveRoot, 'report root' => $reportRoot] as $label => $path) {
            if (! $this->isAbsolutePath($path) || $this->containsParentTraversal($path)) {
                throw new InvalidArgumentException("{$label} must be an absolute path without parent traversal.");
            }
            if (! $this->isPathWithinStorage($path)) {
                throw new InvalidArgumentException("{$label} must remain inside the backend storage directory.");
            }
        }
        foreach (['pt-archiver binary' => $binary, 'defaults file' => $defaultsFile] as $label => $path) {
            if (! $this->isAbsolutePath($path) || $this->containsParentTraversal($path)) {
                throw new InvalidArgumentException("{$label} must be an absolute path without parent traversal.");
            }
        }
        if (str_contains($archiveRoot, '%')) {
            throw new InvalidArgumentException('Archive root cannot contain percent format tokens.');
        }

        return [
            'retention_days' => $this->boundedInteger($options['retention_days'] ?? $runtime['retention_days'], 1, 3650, 'retention days'),
            'file_retention_days' => $this->boundedInteger($options['file_retention_days'] ?? $runtime['file_retention_days'], 1, 3650, 'file retention days'),
            'archive_root' => rtrim($archiveRoot, DIRECTORY_SEPARATOR.'/\\'),
            'report_root' => rtrim($reportRoot, DIRECTORY_SEPARATOR.'/\\'),
            'binary' => $binary,
            'defaults_file' => $defaultsFile,
            'concurrency' => $this->boundedInteger($options['concurrency'] ?? $runtime['concurrency'], 1, 8, 'concurrency'),
            'batch_size' => $this->boundedInteger($options['batch_size'] ?? $runtime['batch_size'], 100, 10000, 'batch size'),
            'sleep_seconds' => $this->boundedInteger($options['sleep_seconds'] ?? $runtime['sleep_seconds'], 0, 60, 'sleep seconds'),
            'lock_path' => storage_path('framework/cache/log-archive.lock'),
        ];
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, string>
     */
    private function resolvePolicies(array $tables): array
    {
        $configured = (array) config('log_archive.tables', []);
        $excluded = array_values((array) config('log_archive.excluded_tables', []));

        if ($tables === []) {
            return $configured;
        }

        $resolved = [];
        foreach ($tables as $table) {
            $table = trim((string) $table);
            if ($table === '') {
                continue;
            }
            if (in_array($table, $excluded, true)) {
                throw new InvalidArgumentException("{$table} is an audit/financial table and cannot be archived by this command.");
            }
            if (! array_key_exists($table, $configured)) {
                throw new InvalidArgumentException("Unsupported log archive table: {$table}");
            }

            $resolved[$table] = (string) $configured[$table];
        }

        if ($resolved === []) {
            throw new InvalidArgumentException('At least one supported log table is required.');
        }

        return $resolved;
    }

    /** @return resource */
    private function acquireGlobalLock(): mixed
    {
        $path = storage_path('framework/cache/log-archive.lock');
        $this->ensureDirectory(dirname($path));
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException("Unable to open archive lock file: {$path}");
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new RuntimeException('Another log archive process is already running.');
        }

        return $handle;
    }

    /**
     * @return array{deleted_files: int, deleted_bytes: int, errors: list<string>}
     */
    private function cleanupExpiredArchives(string $archiveRoot, int $retentionDays): array
    {
        $result = ['deleted_files' => 0, 'deleted_bytes' => 0, 'errors' => []];
        if (! is_dir($archiveRoot)) {
            return $result;
        }

        $threshold = CarbonImmutable::now()->subDays($retentionDays)->getTimestamp();
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($archiveRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (! $file->isFile() || $file->isLink() || strtolower($file->getExtension()) !== 'log') {
                continue;
            }
            if ($file->getMTime() >= $threshold) {
                continue;
            }

            $size = max(0, (int) $file->getSize());
            if (@unlink($path)) {
                $result['deleted_files']++;
                $result['deleted_bytes'] += $size;
            } else {
                $result['errors'][] = "Unable to delete expired archive: {$path}";
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function refreshTotals(array $report): array
    {
        $tables = (array) $report['tables'];
        $report['totals'] = [
            'eligible_rows' => array_sum(array_column($tables, 'eligible_rows')),
            'exported_rows' => array_sum(array_column($tables, 'exported_rows')),
            'deleted_rows' => array_sum(array_column($tables, 'deleted_rows')),
            'failed_tables' => count(array_filter(
                $tables,
                static fn (array $table): bool => (string) ($table['status'] ?? '') === 'failed',
            )),
        ];

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private function finishReport(array $report): array
    {
        $report = $this->refreshTotals($report);
        $report['status'] = (int) $report['totals']['failed_tables'] > 0 ? 'failed' : 'completed';
        $report['finished_at'] = now()->toISOString();

        return $report;
    }

    /** @param array<string, mixed> $report */
    private function writeReport(array $report): void
    {
        $path = (string) $report['report_path'];
        $this->ensureDirectory(dirname($path));
        $content = json_encode(
            $report,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
        ).PHP_EOL;
        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write archive report: {$path}");
        }
        @chmod($path, 0640);
    }

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $detail
     */
    private function appendExecutionEvent(array $report, string $event, ?string $table = null, array $detail = []): void
    {
        $path = (string) $report['execution_log'];
        $payload = [
            'timestamp' => now()->toISOString(),
            'batch_id' => $report['batch_id'],
            'mode' => $report['mode'],
            'event' => $event,
            'table' => $table,
            'status' => $table === null ? ($report['status'] ?? null) : ($detail['status'] ?? null),
            'eligible_rows' => $detail['eligible_rows'] ?? ($report['totals']['eligible_rows'] ?? null),
            'deleted_rows' => $detail['deleted_rows'] ?? ($report['totals']['deleted_rows'] ?? null),
            'error_message' => $detail['error_message'] ?? ($report['error_message'] ?? null),
        ];
        $line = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Unable to write archive execution log: {$path}");
        }
        @chmod($path, 0640);
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create directory: {$directory}");
        }
        if (! is_writable($directory)) {
            throw new RuntimeException("Directory is not writable: {$directory}");
        }
        @chmod($directory, 0750);
    }

    private function hasHeader(string $archiveFile): bool
    {
        $handle = fopen($archiveFile, 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            $header = fgets($handle);
        } finally {
            fclose($handle);
        }

        return is_string($header) && trim($header) !== '';
    }

    private function parseDeletedRows(string $output): ?int
    {
        if (! preg_match_all('/^\s*DELETE\s+(\d+)(?:\s|$)/mi', $output, $matches) || $matches[1] === []) {
            return null;
        }

        return (int) end($matches[1]);
    }

    private function failureMessage(string $output, int $exitCode): string
    {
        $message = trim($output);

        return $this->truncate($message !== '' ? $message : "pt-archiver exited with code {$exitCode}.", 500);
    }

    private function truncate(string $value, int $length): string
    {
        return mb_strlen($value) <= $length ? $value : mb_substr($value, 0, $length).'...';
    }

    private function boundedInteger(mixed $value, int $minimum, int $maximum, string $label): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException("{$label} must be an integer.");
        }
        $value = (int) $value;
        if ($value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException("{$label} must be between {$minimum} and {$maximum}.");
        }

        return $value;
    }

    private function archiveWhere(int $retentionDays): string
    {
        $retentionDays = $this->boundedInteger($retentionDays, 1, 3650, 'retention days');

        return "created_at < DATE_SUB(NOW(), INTERVAL {$retentionDays} DAY)";
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    private function containsParentTraversal(string $path): bool
    {
        return preg_match('#(^|[\\\\/])\.\.([\\\\/]|$)#', $path) === 1;
    }

    private function isPathWithinStorage(string $path): bool
    {
        $normalize = static function (string $value): string {
            $value = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $value), DIRECTORY_SEPARATOR);

            return DIRECTORY_SEPARATOR === '\\' ? strtolower($value) : $value;
        };

        $storageRoot = $normalize(storage_path());
        $candidate = $normalize($path);

        return $candidate === $storageRoot
            || str_starts_with($candidate, $storageRoot.DIRECTORY_SEPARATOR);
    }

    private function createAuditLog(string $batchId, string $table): ArchiveAuditLog
    {
        return ArchiveAuditLog::query()->create([
            'batch_id' => $batchId,
            'table_name' => $table,
            'mode' => 'archive',
            'row_count' => 0,
            'status' => 'running',
            'started_at' => now(),
            'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function finishAuditLog(ArchiveAuditLog $auditLog, array $attributes): void
    {
        $auditLog->forceFill(array_merge($attributes, ['finished_at' => now()]))->save();
    }
}
