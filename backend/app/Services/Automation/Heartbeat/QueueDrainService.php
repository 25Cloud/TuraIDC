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
        return [
            'business' => (string) config('queue.turaidc_business_queues', 'provision,referral,notification,coupon,default'),
            'automation' => (string) config('queue.turaidc_schedule_queue', 'automation'),
        ];
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
                    $available[$name] = $queues;
                    $locks[$name] = $lock;

                    continue;
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
