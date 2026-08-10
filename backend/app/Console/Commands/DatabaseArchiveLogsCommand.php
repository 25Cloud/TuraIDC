<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\System\LogArchiveService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class DatabaseArchiveLogsCommand extends Command
{
    protected $signature = 'db:archive-logs
        {--dry-run : 仅预览影响行数并校验 pt-archiver 命令；未指定 --execute 时默认 dry-run}
        {--execute : 使用 pt-archiver 导出 CSV 并通过 --purge 物理删除超过保留期限的日志}
        {--table=* : 只处理指定日志表，可重复}
        {--retain-days= : 日志保留天数，显式覆盖后台日志归档设置}
        {--file-retain-days= : 归档文件保留天数，显式覆盖后台日志归档设置}
        {--concurrency= : 最大并行表数量，默认读取后台日志归档设置}
        {--batch-size= : 每批处理行数，默认读取后台日志归档设置}
        {--sleep-seconds= : 批次间隔秒数，默认读取后台日志归档设置}
        {--json : 以 JSON 输出结果}';

    protected $description = '使用 pt-archiver 并行归档并物理删除超过保留期限的日志记录';

    public function handle(LogArchiveService $service): int
    {
        try {
            if ((bool) $this->option('execute') && (bool) $this->option('dry-run')) {
                throw new InvalidArgumentException('--execute and --dry-run cannot be used together.');
            }

            $options = ['tables' => array_values((array) $this->option('table'))];
            $this->copyIntegerOption($options, 'retain-days', 'retention_days');
            $this->copyIntegerOption($options, 'file-retain-days', 'file_retention_days');
            $this->copyIntegerOption($options, 'concurrency', 'concurrency');
            $this->copyIntegerOption($options, 'batch-size', 'batch_size');
            $this->copyIntegerOption($options, 'sleep-seconds', 'sleep_seconds');

            $execute = (bool) $this->option('execute');
            $result = $execute ? $service->archive($options) : $service->dryRun($options);

            return $this->outputResult($result);
        } catch (Throwable $exception) {
            if ((bool) $this->option('json')) {
                $this->line(json_encode(
                    ['error' => $exception->getMessage()],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
                ));
            } else {
                $this->error($exception->getMessage());
            }

            return self::FAILURE;
        }
    }

    /** @param array<string, mixed> $result */
    private function outputResult(array $result): int
    {
        $failed = (string) ($result['status'] ?? 'failed') !== 'completed';

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $result,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT,
            ));

            return $failed ? self::FAILURE : self::SUCCESS;
        }

        $dryRun = (string) ($result['mode'] ?? '') === 'dry_run';
        $this->info($dryRun ? '日志归档 dry-run 完成' : '日志归档执行完成');
        $this->line('数据库: '.($result['database'] ?? ''));
        $this->line(sprintf(
            '参数: retention=%sd batch=%d sleep=%ds concurrency=%d',
            (int) ($result['retention_days'] ?? 30),
            (int) ($result['batch_size'] ?? 0),
            (int) ($result['sleep_seconds'] ?? 0),
            (int) ($result['concurrency'] ?? 0),
        ));
        $this->line('执行报告: '.($result['report_path'] ?? ''));
        $this->line(sprintf(
            '合计: eligible=%d exported=%d deleted=%d failed=%d',
            (int) ($result['totals']['eligible_rows'] ?? 0),
            (int) ($result['totals']['exported_rows'] ?? 0),
            (int) ($result['totals']['deleted_rows'] ?? 0),
            (int) ($result['totals']['failed_tables'] ?? 0),
        ));

        foreach ((array) ($result['tables'] ?? []) as $table => $tableReport) {
            $this->line(sprintf(
                '- %s: eligible=%d exported=%d deleted=%d status=%s file=%s',
                $table,
                (int) ($tableReport['eligible_rows'] ?? 0),
                (int) ($tableReport['exported_rows'] ?? 0),
                (int) ($tableReport['deleted_rows'] ?? 0),
                (string) ($tableReport['status'] ?? ''),
                (string) ($tableReport['archive_file'] ?? ''),
            ));
        }

        if ($dryRun) {
            $this->warn('当前为 dry-run，未创建归档数据文件、未删除数据库记录、未清理历史文件。');
        } else {
            $this->line(sprintf(
                '历史文件清理: files=%d bytes=%d',
                (int) ($result['cleanup']['deleted_files'] ?? 0),
                (int) ($result['cleanup']['deleted_bytes'] ?? 0),
            ));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string, mixed> $options */
    private function copyIntegerOption(array &$options, string $commandOption, string $serviceOption): void
    {
        $value = $this->option($commandOption);
        if ($value !== null && $value !== '') {
            $options[$serviceOption] = $value;
        }
    }
}
