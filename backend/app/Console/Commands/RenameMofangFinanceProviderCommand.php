<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Integrations\Plugins\MofangFinanceProviderKeyMigrationService;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Throwable;

final class RenameMofangFinanceProviderCommand extends Command
{
    protected $signature = 'db:rename-mofang-finance-provider
        {--dry-run : 只输出受控切换预检报告，不写入数据库}
        {--execute : 在事务中执行受控 provider key 切换}
        {--json : 已兼容保留；命令始终输出 JSON}';

    protected $description = '将实时上游绑定从 mofang_finance_api 受控切换为 zjmf_finance_api';

    public function handle(MofangFinanceProviderKeyMigrationService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $execute = (bool) $this->option('execute');

        if ($dryRun && $execute) {
            $this->writeJson([
                'ok' => false,
                'mode' => 'invalid',
                'error' => [
                    'code' => 'invalid_options',
                    'message' => '不能同时传入 --dry-run 和 --execute。',
                ],
            ]);

            return self::FAILURE;
        }

        $mode = $execute ? 'execute' : 'dry_run';

        try {
            $report = $execute ? $service->execute() : $service->inspect();
        } catch (Throwable $exception) {
            $this->writeJson([
                'ok' => false,
                'mode' => $mode,
                'error' => [
                    'code' => $exception instanceof RuntimeException ? 'precondition_failed' : 'migration_failed',
                    'message' => $exception instanceof RuntimeException
                        ? $exception->getMessage()
                        : '受控 provider key 切换失败，请检查应用日志。',
                ],
            ]);

            return self::FAILURE;
        }

        $this->writeJson([
            'ok' => true,
            'mode' => $mode,
            'report' => $report,
        ]);

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function writeJson(array $payload): void
    {
        try {
            $this->line(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } catch (JsonException) {
            $this->line('{"ok":false,"mode":"serialization_failed"}');
        }
    }
}
