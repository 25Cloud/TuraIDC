<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Automation\Heartbeat\QueueDrainService;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class QueueDrainServiceTest extends TestCase
{
    public function test_busy_automation_worker_does_not_prevent_the_business_worker_from_draining(): void
    {
        config()->set('queue.default', 'database');
        config()->set('cache.default', 'array');
        app('cache')->setDefaultDriver('array');
        app('cache')->forgetDriver('array');
        $this->ensureJobsTable();

        $automationLock = Cache::lock('scheduler:queue-drain:automation', 60);
        $this->assertTrue($automationLock->get());

        $service = new class extends QueueDrainService
        {
            /** @var array<string, Process> */
            public array $processes = [];

            protected function shouldSkipQueueDrain(): bool
            {
                return false;
            }

            protected function buildWorkerProcess(string $queues): Process
            {
                $process = new Process([
                    PHP_BINARY,
                    '-r',
                    'fwrite(STDOUT, "business completed");',
                ], base_path());
                $this->processes[$queues] = $process;

                return $process;
            }
        };

        try {
            $summary = $service->drainOnceIfDatabaseQueue();
        } finally {
            $automationLock->release();
        }

        $this->assertSame('partially_drained', $summary['status']);
        $this->assertSame(['automation'], $summary['busy_workers']);
        $this->assertSame('completed', $summary['workers']['business']['status']);
        $this->assertSame('in_progress', $summary['workers']['automation']['status']);
        $this->assertArrayHasKey('provision,referral,notification,coupon,default', $service->processes);
    }

    public function test_parallel_workers_wait_for_automation_after_business_worker_fails(): void
    {
        config()->set('queue.turaidc_business_queues', 'provision,referral,notification,coupon,default');
        config()->set('queue.turaidc_schedule_queue', 'automation');

        $service = new class extends QueueDrainService
        {
            /** @var array<string, Process> */
            public array $processes = [];

            public function runWorkers(): array
            {
                return $this->runWorkersInParallel();
            }

            protected function buildWorkerProcess(string $queues): Process
            {
                if ($queues === 'automation') {
                    $process = new Process([
                        PHP_BINARY,
                        '-r',
                        'usleep(250000); fwrite(STDOUT, "automation completed");',
                    ], base_path());
                    $this->processes['automation'] = $process;

                    return $process;
                }

                $process = new Process([
                    PHP_BINARY,
                    '-r',
                    'fwrite(STDERR, "business failed"); exit(7);',
                ], base_path());
                $this->processes['business'] = $process;

                return $process;
            }
        };

        try {
            $service->runWorkers();
            $this->fail('Expected business Worker failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('business Worker 退出码：7', $exception->getMessage());
        }

        $this->assertTrue($service->processes['automation']->isSuccessful());
        $this->assertSame(0, $service->processes['automation']->getExitCode());
        $this->assertSame(7, $service->processes['business']->getExitCode());
    }

    public function test_lock_exception_for_one_worker_does_not_block_the_other_worker(): void
    {
        config()->set('queue.default', 'database');
        config()->set('cache.default', 'array');
        $this->ensureJobsTable();

        $businessLock = new RecordingQueueDrainLock;
        Cache::shouldReceive('lock')
            ->once()
            ->with('scheduler:queue-drain:business', \Mockery::type('int'))
            ->andReturn($businessLock);
        Cache::shouldReceive('lock')
            ->once()
            ->with('scheduler:queue-drain:automation', \Mockery::type('int'))
            ->andThrow(new RuntimeException('automation lock backend unavailable'));
        Log::shouldReceive('warning')
            ->once()
            ->with('[调度] 获取队列 Worker 互斥锁失败，已跳过该队列', \Mockery::on(
                fn (array $context): bool => ($context['worker'] ?? null) === 'automation',
            ));

        $service = new class extends QueueDrainService
        {
            protected function shouldSkipQueueDrain(): bool
            {
                return false;
            }

            protected function buildWorkerProcess(string $queues): Process
            {
                return new Process([PHP_BINARY, '-r', 'fwrite(STDOUT, "completed");'], base_path());
            }
        };

        $summary = $service->drainOnceIfDatabaseQueue();

        $this->assertSame('partially_drained', $summary['status']);
        $this->assertSame(['automation'], $summary['busy_workers']);
        $this->assertSame('completed', $summary['workers']['business']['status']);
        $this->assertSame('in_progress', $summary['workers']['automation']['status']);
        $this->assertSame(1, $businessLock->releaseCount);
    }

    public function test_business_worker_lock_is_released_while_automation_worker_is_still_running(): void
    {
        config()->set('queue.turaidc_business_queues', 'provision,referral,notification,coupon,default');
        config()->set('queue.turaidc_schedule_queue', 'automation');

        $service = new class extends QueueDrainService
        {
            /** @var array<string, Process> */
            public array $processes = [];

            public bool $automationWasRunningWhenBusinessLockReleased = false;

            /**
             * @param  array<string, Lock>  $locks
             * @return array<string, mixed>
             */
            public function runWorkersWithLocks(array &$locks): array
            {
                return $this->runWorkersInParallel([], $locks);
            }

            protected function buildWorkerProcess(string $queues): Process
            {
                $process = $queues === 'automation'
                    ? new Process([PHP_BINARY, '-r', 'usleep(600000);'], base_path())
                    : new Process([PHP_BINARY, '-r', 'fwrite(STDOUT, "business completed");'], base_path());
                $this->processes[$queues === 'automation' ? 'automation' : 'business'] = $process;

                return $process;
            }
        };
        $businessLock = new RecordingQueueDrainLock(function () use ($service): void {
            $service->automationWasRunningWhenBusinessLockReleased = $service->processes['automation']->isRunning();
        });
        $automationLock = new RecordingQueueDrainLock;
        $locks = [
            'business' => $businessLock,
            'automation' => $automationLock,
        ];

        $summary = $service->runWorkersWithLocks($locks);

        $this->assertSame('drained', $summary['status']);
        $this->assertTrue($service->automationWasRunningWhenBusinessLockReleased);
        $this->assertSame(1, $businessLock->releaseCount);
        $this->assertSame(1, $automationLock->releaseCount);
        $this->assertSame([], $locks);
    }

    private function ensureJobsTable(): void
    {
        if (Schema::hasTable('jobs')) {
            return;
        }

        Schema::create('jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
    }
}

final class RecordingQueueDrainLock implements Lock
{
    public int $releaseCount = 0;

    public function __construct(
        private readonly ?Closure $onRelease = null,
    ) {}

    public function get($callback = null)
    {
        return true;
    }

    public function block($seconds, $callback = null)
    {
        return true;
    }

    public function release(): bool
    {
        $this->releaseCount++;
        ($this->onRelease)?->__invoke();

        return true;
    }

    public function owner(): string
    {
        return 'test-lock-owner';
    }

    public function forceRelease(): void {}
}
