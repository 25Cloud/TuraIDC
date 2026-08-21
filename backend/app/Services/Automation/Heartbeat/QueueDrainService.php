<?php

declare(strict_types=1);

namespace App\Services\Automation\Heartbeat;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;

class QueueDrainService
{
    /**
     * @return array<string, mixed>
     */
    public function drainOnceIfDatabaseQueue(): array
    {
        if ($this->shouldSkipQueueDrain()) {
            return [
                'status' => 'skipped',
                'reason' => 'running_unit_tests',
            ];
        }

        if ((string) config('queue.default', 'sync') !== 'database') {
            return [
                'status' => 'skipped',
                'reason' => 'queue_driver_not_database',
                'queue_driver' => (string) config('queue.default', 'sync'),
            ];
        }

        [$connection, $table] = $this->databaseQueueTable();
        try {
            $tableReady = $table !== '' && Schema::connection($connection)->hasTable($table);
        } catch (\Throwable $exception) {
            Log::warning('[调度] 检查数据库队列表失败', [
                'connection' => $connection,
                'table' => $table,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);
            $tableReady = false;
        }

        if (! $tableReady) {
            return [
                'status' => 'skipped',
                'reason' => 'jobs_table_missing',
                'connection' => $connection,
                'table' => $table,
            ];
        }

        $locks = [];

        try {
            [$definitions, $locks, $busyWorkers] = $this->acquireAvailableWorkerLocks($this->workerDefinitions());

            if ($definitions === []) {
                return [
                    'status' => 'skipped',
                    'reason' => 'queue_drains_in_progress',
                    'busy_workers' => array_keys($busyWorkers),
                    'workers' => $this->inProgressWorkers($busyWorkers),
                ];
            }

            return $this->runWorkersInParallel($definitions, $locks, $busyWorkers);
        } finally {
            $this->releaseWorkerLocks($locks);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function runWorkersInParallel(array $definitions = [], array &$locks = [], array $busyWorkers = []): array
    {
        $definitions = $definitions !== [] ? $definitions : $this->workerDefinitions();
        $processes = [];
        $outputs = [];

        try {
            foreach ($definitions as $name => $queues) {
                $process = $this->buildWorkerProcess($queues);
                // 进程级超时必须容纳最长的已注册任务（max_timeout），
                // 否则 Windows（无 pcntl）下 job 超时失效时 worker 卡死会无限阻塞 drain。
                $process->setTimeout($this->workerProcessTimeout());
                $process->start();
                $processes[$name] = $process;
                $outputs[$name] = ['stdout' => '', 'stderr' => ''];
            }

            $heartbeatRefreshedAt = 0.0;

            while (true) {
                $hasRunningProcess = false;
                foreach ($processes as $name => $process) {
                    $this->captureProcessOutput($outputs[$name], $process);
                    if ($process->isRunning()) {
                        $hasRunningProcess = true;

                        continue;
                    }

                    $this->releaseWorkerLock($locks, $name);
                }

                if (! $hasRunningProcess) {
                    break;
                }

                // 定期刷新存活心跳：进程被强杀就不会再刷新，后续 drain 据此判定残锁。
                // 刷新周期取心跳 TTL 的三分之一，既留足容错又不至于每 100ms 打一次缓存。
                $now = microtime(true);
                if ($now - $heartbeatRefreshedAt >= max(5, (int) ($this->drainHeartbeatTtl() / 3))) {
                    foreach (array_keys($locks) as $name) {
                        $this->touchDrainHeartbeat($name);
                    }
                    $heartbeatRefreshedAt = $now;
                }

                usleep(100000);
            }

            foreach ($processes as $name => $process) {
                $this->captureProcessOutput($outputs[$name], $process);
            }
        } catch (\Throwable $exception) {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }

            Log::error('[调度] 并行队列 Worker 启动或等待失败', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            throw $exception;
        }

        $workers = [];
        $failures = [];
        foreach ($processes as $name => $process) {
            $exitCode = $process->getExitCode();
            $worker = [
                'queue' => $definitions[$name],
                'exit_code' => $exitCode,
                'status' => $process->isSuccessful() ? 'completed' : 'failed',
                'output' => $this->truncateOutput($outputs[$name]['stdout']),
                'error_output' => $this->truncateOutput($outputs[$name]['stderr']),
            ];
            $workers[$name] = $worker;

            if (! $process->isSuccessful()) {
                $failures[] = sprintf(
                    '%s Worker 退出码：%s%s',
                    $name,
                    $exitCode === null ? 'unknown' : (string) $exitCode,
                    $worker['error_output'] !== null ? '，错误输出：'.$worker['error_output'] : '',
                );
            }
        }

        $workers = array_replace($workers, $this->inProgressWorkers($busyWorkers));

        $summary = [
            'status' => $failures !== [] ? 'failed' : ($busyWorkers === [] ? 'drained' : 'partially_drained'),
            'exit_code' => $failures === [] ? 0 : 1,
            'workers' => $workers,
            'busy_workers' => array_keys($busyWorkers),
        ];

        if ($failures !== []) {
            Log::error('[调度] 并行队列 Worker 执行失败', [
                'workers' => $workers,
            ]);
            throw new RuntimeException('队列并行消费执行失败：'.implode('；', $failures));
        }

        return $summary;
    }

    /**
     * @return array<string, string>
     */
    protected function workerDefinitions(): array
    {
        $provision = $this->normalizeQueueList(
            (string) config('queue.turaidc_provision_queues', 'provision')
        );
        $business = $this->normalizeQueueList(
            (string) config('queue.turaidc_business_queues', 'referral,notification,coupon,default')
        );

        // 已在 provision 组里的队列要从 business 组剔除：存量部署的 .env 里
        // TURAIDC_BUSINESS_QUEUES 仍可能包含 provision，不剔除就会有两个 worker
        // 同时消费同一队列，把本次拆分想避免的并发又带回来。
        $business = array_values(array_diff($business, $provision));

        $definitions = [];
        if ($provision !== []) {
            $definitions['provision'] = implode(',', $provision);
        }
        if ($business !== []) {
            $definitions['business'] = implode(',', $business);
        }

        $automation = $this->normalizeQueueList(
            (string) config('queue.turaidc_schedule_queue', 'automation')
        );
        if ($automation !== []) {
            $definitions['automation'] = implode(',', $automation);
        }

        return $definitions;
    }

    /**
     * 把逗号分隔的队列列表规整为去空、去重、保序的数组。
     *
     * @return list<string>
     */
    private function normalizeQueueList(string $queues): array
    {
        $items = array_map('trim', explode(',', $queues));

        return array_values(array_unique(array_filter($items, static fn (string $q): bool => $q !== '')));
    }

    /**
     * @param  array<string, string>  $definitions
     * @return array{0:array<string, string>,1:array<string, mixed>,2:array<string, string>}
     */
    protected function acquireAvailableWorkerLocks(array $definitions): array
    {
        if (! $this->shouldUseDrainLock()) {
            return [$definitions, [], []];
        }

        $available = [];
        $locks = [];
        $busyWorkers = [];
        foreach ($definitions as $name => $queues) {
            try {
                $lock = Cache::lock('scheduler:queue-drain:'.$name, $this->drainLockTtl());
                if ($lock->get()) {
                    $this->touchDrainHeartbeat($name);
                    $available[$name] = $queues;
                    $locks[$name] = $lock;

                    continue;
                }

                // 锁被占用时要区分两种情形：确有 drain 在跑，还是上一个持有者
                // 被 SIGKILL / OOM / 容器重启带走后留下的残锁。
                // 锁 TTL 必须覆盖最长任务（drainLockTtl() 可达 3960s）且无法续期，
                // 仅靠它自然过期会让队列静默停摆最长 66 分钟；这里用短周期心跳判活。
                if ($this->drainHeartbeatIsStale($name)) {
                    Log::warning('[调度] 检测到队列 Worker 残锁（持有者心跳已过期），强制释放后重试', [
                        'worker' => $name,
                        'queue' => $queues,
                        'heartbeat_ttl' => $this->drainHeartbeatTtl(),
                    ]);

                    $lock->forceRelease();

                    if ($lock->get()) {
                        $this->touchDrainHeartbeat($name);
                        $available[$name] = $queues;
                        $locks[$name] = $lock;

                        continue;
                    }
                }

                $busyWorkers[$name] = $queues;
            } catch (\Throwable $exception) {
                // 一个队列的锁后端异常不应释放或阻断另一个队列；当前队列宁可跳过，
                // 避免在无法证明互斥时启动重复 Worker。
                $busyWorkers[$name] = $queues;
                Log::warning('[调度] 获取队列 Worker 互斥锁失败，已跳过该队列', [
                    'worker' => $name,
                    'queue' => $queues,
                    'message' => $exception->getMessage(),
                    'exception' => $exception::class,
                ]);
            }
        }

        return [$available, $locks, $busyWorkers];
    }

    protected function buildWorkerProcess(string $queues): Process
    {
        return new Process([
            PHP_BINARY,
            base_path('artisan'),
            'queue:work',
            '--queue='.$queues,
            '--sleep=1',
            '--tries='.(string) config('queue.turaidc_worker_tries', 3),
            '--timeout='.$this->workerTimeout(),
            '--stop-when-empty',
            '--max-time=50',
        ], base_path());
    }

    /**
     * @param  array<string, mixed>  $locks
     */
    private function releaseWorkerLocks(array &$locks): void
    {
        foreach (array_keys($locks) as $name) {
            $this->releaseWorkerLock($locks, $name);
        }
    }

    /**
     * @param  array<string, mixed>  $locks
     */
    private function releaseWorkerLock(array &$locks, string $name): void
    {
        if (! isset($locks[$name])) {
            return;
        }

        $locks[$name]->release();
        unset($locks[$name]);
        $this->forgetDrainHeartbeat($name);
    }

    /**
     * 某个 Worker 的存活心跳缓存键。
     *
     * 与 drain 互斥锁（scheduler:queue-drain:{name}）配对使用：锁负责互斥，
     * 心跳负责证明持有者还活着。
     */
    private function drainHeartbeatKey(string $name): string
    {
        return 'scheduler:queue-drain:'.$name.':heartbeat';
    }

    /**
     * 存活心跳的有效期（秒）。
     *
     * 刻意远小于锁的 TTL（drainLockTtl() 可达 3960 秒）：锁的 TTL 必须覆盖
     * 最长任务，而心跳只需覆盖一个刷新周期，这样进程被强杀后能在分钟级
     * 被判定为已死，而不是等锁自然过期。下限 30 秒，避免配置成过短的值
     * 导致正常运行中的 drain 被误判。
     */
    private function drainHeartbeatTtl(): int
    {
        return max(30, (int) config('queue.turaidc_worker_drain_heartbeat_ttl', 90));
    }

    /**
     * 写入/刷新某个 Worker 的存活心跳。
     *
     * 在取得锁时写一次，并由 runWorkersInParallel() 的等待循环按周期刷新。
     * 进程被 SIGKILL / OOM 带走后不会再刷新，心跳随即过期——这正是残锁的判定依据。
     * 写失败只记日志不抛：心跳仅服务于残锁判定，不应影响本轮 drain 的正常执行。
     */
    private function touchDrainHeartbeat(string $name): void
    {
        try {
            Cache::put($this->drainHeartbeatKey($name), time(), $this->drainHeartbeatTtl());
        } catch (\Throwable $exception) {
            // 心跳只服务于残锁判定，写失败不应影响本轮 drain 的正常执行
            Log::warning('[调度] 写入队列 Worker 存活心跳失败', [
                'worker' => $name,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * 判断锁的持有者是否已经死亡（心跳已过期或从未写入）。
     *
     * 返回 true 表示可以安全地强制释放该锁并重新获取。
     * 读取失败时返回 false（按"未过期"处理）：宁可本轮跳过该队列，
     * 也不要在无法证明持有者已死的情况下抢锁、启动重复 Worker。
     */
    private function drainHeartbeatIsStale(string $name): bool
    {
        try {
            return Cache::get($this->drainHeartbeatKey($name)) === null;
        } catch (\Throwable $exception) {
            // 读不到心跳状态时按"未过期"处理：宁可本轮跳过，也不要在无法证明
            // 持有者已死的情况下强制释放锁并启动重复 Worker。
            Log::warning('[调度] 读取队列 Worker 存活心跳失败，按未过期处理', [
                'worker' => $name,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 正常释放锁时清掉对应的存活心跳，避免残留键误导下一轮判定。
     *
     * 清理失败可忽略：心跳自带 TTL，会在有效期内自然过期。
     */
    private function forgetDrainHeartbeat(string $name): void
    {
        try {
            Cache::forget($this->drainHeartbeatKey($name));
        } catch (\Throwable) {
            // 心跳残留会在 TTL 内自然过期，忽略即可
        }
    }

    /**
     * @param  array<string, string>  $busyWorkers
     * @return array<string, array{queue:string,exit_code:null,status:string,output:null,error_output:null}>
     */
    private function inProgressWorkers(array $busyWorkers): array
    {
        $workers = [];
        foreach ($busyWorkers as $name => $queues) {
            $workers[$name] = [
                'queue' => $queues,
                'exit_code' => null,
                'status' => 'in_progress',
                'output' => null,
                'error_output' => null,
            ];
        }

        return $workers;
    }

    protected function shouldSkipQueueDrain(): bool
    {
        return app()->runningUnitTests();
    }

    /**
     * @param  array{stdout:string,stderr:string}  $output
     */
    private function captureProcessOutput(array &$output, Process $process): void
    {
        $stdout = $process->getIncrementalOutput();
        $stderr = $process->getIncrementalErrorOutput();

        if ($stdout !== '') {
            $output['stdout'] .= $stdout;
        }
        if ($stderr !== '') {
            $output['stderr'] .= $stderr;
        }
    }

    private function truncateOutput(string $output): ?string
    {
        $output = trim($output);

        return $output !== '' ? mb_substr($output, 0, 2000) : null;
    }

    private function shouldUseDrainLock(): bool
    {
        return ! (PHP_OS_FAMILY === 'Windows' && (string) config('cache.default', 'file') === 'file');
    }

    private function drainLockTtl(): int
    {
        return max(
            (int) config('queue.turaidc_worker_drain_lock_ttl', 3960),
            (int) config('queue.turaidc_worker_max_timeout', 3600) + 60,
            (int) config('queue.connections.database.retry_after', 3900) + 60,
        );
    }

    /**
     * worker 级超时至少覆盖最长的已注册任务，避免无 timeout 属性的 Job 被
     * TURAIDC_worker_timeout（默认 1200s）强杀；有 timeout 属性的 Job 仍由 Laravel
     * timeoutForJob 优先采用自身 timeout。
     */
    private function workerTimeout(): int
    {
        return max(
            (int) config('queue.turaidc_worker_timeout', 1200),
            (int) config('queue.turaidc_worker_max_timeout', 3600),
        );
    }

    /** 进程级硬超时：worker 处理最长任务之外再多留 60 秒余量。 */
    private function workerProcessTimeout(): int
    {
        return $this->workerTimeout() + 60;
    }

    /** @return array{0:string,1:string} */
    private function databaseQueueTable(): array
    {
        $config = (array) config('queue.connections.database', []);
        $connection = trim((string) ($config['connection'] ?? '')) ?: (string) config('database.default');
        $table = trim((string) ($config['table'] ?? 'jobs'));

        return [$connection, $table];
    }
}
