<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ApiKeyUsageLog;
use App\Services\OpenApi\OpenApiConfig;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class OpenApiPruneUsageLogsCommand extends Command
{
    /** 单批删除上限：分批删除避免长事务/长锁（MySQL 5.7 兼容口径） */
    private const CHUNK_SIZE = 2000;

    protected $signature = 'open-api:prune-usage-logs
                            {--days= : 保留天数，留空则读 open_api.usage_log_retention_days（默认 90）}';

    protected $description = '按保留期归档删除开放接口的使用日志（api_key_usage_logs 只增不减，需定期清理防膨胀）';

    public function handle(OpenApiConfig $config): int
    {
        $daysOption = trim((string) $this->option('days'));
        if ($daysOption !== '') {
            $retentionDays = max((int) $daysOption, 1);
        } else {
            $retentionDays = $config->usageLogRetentionDays();
        }

        $cutoff = CarbonImmutable::now()->subDays($retentionDays);
        $totalDeleted = 0;

        do {
            $deleted = ApiKeyUsageLog::query()
                ->where('created_at', '<', $cutoff->toDateTimeString())
                ->limit(self::CHUNK_SIZE)
                ->delete();
            $totalDeleted += (int) $deleted;
        } while ((int) $deleted === self::CHUNK_SIZE);

        $this->line(sprintf(
            '开放接口使用日志清理完成：保留 %d 天，删除 %d 条',
            $retentionDays,
            $totalDeleted
        ));

        if ($totalDeleted > 0) {
            Log::info('[open-api] 使用日志定期清理完成', [
                'retention_days' => $retentionDays,
                'deleted' => $totalDeleted,
            ]);
        }

        return self::SUCCESS;
    }
}
