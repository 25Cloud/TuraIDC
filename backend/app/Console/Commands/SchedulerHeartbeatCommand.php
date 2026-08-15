<?php

namespace App\Console\Commands;

use App\Services\Automation\Heartbeat\HeartbeatScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SchedulerHeartbeatCommand extends Command
{
    protected $signature = 'scheduler:heartbeat {--at= : 指定心跳时间，供测试和排查使用}';

    protected $description = '按 15 分钟槽位异步派发到期定时任务；队列由 queue:drain 独立消费';

    public function handle(HeartbeatScheduler $scheduler): int
    {
        $at = trim((string) $this->option('at'));
        $now = $at !== ''
            ? CarbonImmutable::parse($at, (string) config('app.timezone', date_default_timezone_get()))
            : CarbonImmutable::now((string) config('app.timezone', date_default_timezone_get()));

        $summary = $scheduler->tick($now);

        $this->line((string) json_encode($summary->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
