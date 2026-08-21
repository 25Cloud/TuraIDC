<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ScheduleTick;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 心跳自身的死亡检测：心跳停滞时输出结构化告警并返回失败。
 *
 * 驱动方式有两条，能覆盖的故障范围不同，不要混淆：
 * 1. routes/console.php 里注册的 Schedule 条目——与心跳是各自独立的条目，
 *    因此心跳命令抛异常时本探针仍会执行；但它同样由 schedule:run 驱动，
 *    一旦 schedule:run 整体不执行（如 cron PATH 缺失），本探针会一起失效。
 * 2. Docker 镜像的 entrypoint 额外给本命令排了一行独立 crontab，
 *    不经 schedule:run，因此能发现"schedule:run 整体不执行"这类故障。
 *
 * 只配了一行 schedule:run 的部署（如宝塔）只有第 1 条，建议按第 2 条补一行 crontab，
 * 或改由外部监控轮询 /api/ready。
 */
class SchedulerLivenessCommand extends Command
{
    protected $signature = 'scheduler:liveness {--max-age= : 心跳最大允许间隔秒数，默认取 health.scheduler_max_age_seconds}';

    protected $description = '独立于心跳的存活探针：心跳停滞时告警并返回失败退出码';

    public function handle(): int
    {
        $maxAge = $this->resolveMaxAge();

        try {
            if (! Schema::hasTable('schedule_ticks')) {
                $this->reportStale(null, $maxAge, 'schedule_ticks 表不存在');

                return self::FAILURE;
            }

            $latest = ScheduleTick::query()->max('triggered_at');
            if (! is_string($latest) || trim($latest) === '') {
                $this->reportStale(null, $maxAge, '尚无任何心跳记录');

                return self::FAILURE;
            }

            $latestTime = CarbonImmutable::parse($latest);
            if (! $latestTime->greaterThanOrEqualTo(CarbonImmutable::now()->subSeconds($maxAge))) {
                $this->reportStale($latestTime, $maxAge, '心跳停滞超过最大允许间隔');

                return self::FAILURE;
            }

            $this->line('心跳正常，最新心跳：'.$latestTime->toDateTimeString());

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error('[调度] 心跳存活探针检测失败', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return self::FAILURE;
        }
    }

    private function resolveMaxAge(): int
    {
        $option = trim((string) $this->option('max-age'));

        return $option !== ''
            ? max(30, min(86400, (int) $option))
            : max(60, (int) config('health.scheduler_max_age_seconds', 180));
    }

    private function reportStale(?CarbonImmutable $latest, int $maxAge, string $reason): void
    {
        Log::error('[调度] 心跳停滞告警', [
            'latest_heartbeat' => $latest?->toDateTimeString(),
            'max_age_seconds' => $maxAge,
            'reason' => $reason,
            'source' => 'scheduler:liveness',
        ]);
    }
}
