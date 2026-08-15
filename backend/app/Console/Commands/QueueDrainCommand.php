<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Automation\Heartbeat\QueueDrainService;
use Illuminate\Console\Command;

/**
 * 独立的队列消费入口：由 schedule:run 以 runInBackground() 每分钟后台触发，
 * 与心跳派发解耦，避免长任务阻塞心跳进程。drain 锁保证同一队列不并发消费。
 */
class QueueDrainCommand extends Command
{
    protected $signature = 'queue:drain';

    protected $description = '消费业务与 automation 队列，由 schedule:run 每分钟后台触发';

    public function handle(QueueDrainService $queueDrain): int
    {
        try {
            $summary = $queueDrain->drainOnceIfDatabaseQueue();
        } catch (\Throwable $exception) {
            $this->error('队列消费失败：'.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line((string) json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // drainOnceIfDatabaseQueue 在 worker 失败时抛异常（上方 catch 已返回 FAILURE），
        // 因此这里恒为 SUCCESS，无需再按 status 分支。
        return self::SUCCESS;
    }
}
