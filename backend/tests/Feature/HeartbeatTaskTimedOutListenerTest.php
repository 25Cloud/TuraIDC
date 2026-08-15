<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunHeartbeatTaskJob;
use App\Models\ScheduleTaskRun;
use App\Services\Automation\Heartbeat\ScheduleTaskRunRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HeartbeatTaskTimedOutListenerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureScheduleTaskRunsTable();

        ScheduleTaskRun::query()
            ->where('task_key', 'heartbeat-timeout-test')
            ->delete();
    }

    public function test_timeout_before_final_attempt_marks_run_retrying(): void
    {
        $run = $this->createRunningRun();

        Event::dispatch(new JobTimedOut(
            'database',
            $this->makeJob($this->payloadFor(new RunHeartbeatTaskJob('heartbeat-timeout-test', null, (int) $run->id, null, 'heartbeat', 120)), 1),
        ));

        $this->assertDatabaseHas('schedule_task_runs', [
            'id' => $run->id,
            'status' => ScheduleTaskRun::STATUS_RETRYING,
            'error_msg' => '任务执行超时，已终止进程，等待队列重试',
        ]);
    }

    public function test_timeout_on_final_attempt_marks_run_failed(): void
    {
        $run = $this->createRunningRun();

        Event::dispatch(new JobTimedOut(
            'database',
            $this->makeJob($this->payloadFor(new RunHeartbeatTaskJob('heartbeat-timeout-test', null, (int) $run->id, null, 'heartbeat', 120)), 3),
        ));

        $this->assertDatabaseHas('schedule_task_runs', [
            'id' => $run->id,
            'status' => ScheduleTaskRun::STATUS_FAILED,
            'error_msg' => '任务执行超时，已终止进程并达到最大重试次数',
        ]);
    }

    public function test_non_heartbeat_job_timeout_leaves_run_untouched(): void
    {
        $run = $this->createRunningRun();

        $payload = json_encode([
            'displayName' => 'App\\Jobs\\SomeOtherJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'commandName' => 'App\\Jobs\\SomeOtherJob',
                'command' => serialize(new \stdClass),
            ],
        ], JSON_THROW_ON_ERROR);

        Event::dispatch(new JobTimedOut('database', $this->makeJob($payload, 1)));

        $this->assertDatabaseHas('schedule_task_runs', [
            'id' => $run->id,
            'status' => ScheduleTaskRun::STATUS_RUNNING,
        ]);
    }

    public function test_corrupted_payload_is_isolated(): void
    {
        $run = $this->createRunningRun();

        Event::dispatch(new JobTimedOut('database', $this->makeJob('not-json-payload', 1)));

        $this->assertDatabaseHas('schedule_task_runs', [
            'id' => $run->id,
            'status' => ScheduleTaskRun::STATUS_RUNNING,
        ]);
    }

    public function test_timeout_state_convergence_allows_queue_retry_to_run(): void
    {
        $run = $this->createRunningRun();

        // 模拟超时收敛：running -> retrying
        Event::dispatch(new JobTimedOut(
            'database',
            $this->makeJob($this->payloadFor(new RunHeartbeatTaskJob('heartbeat-timeout-test', null, (int) $run->id, null, 'heartbeat', 120)), 1),
        ));

        // 重试 Job 再执行时，markRunning 的 CAS 必须放行 retrying -> running
        $updated = app(ScheduleTaskRunRepository::class)
            ->markRunning((int) $run->id, 2);

        $this->assertTrue($updated);
        $this->assertDatabaseHas('schedule_task_runs', [
            'id' => $run->id,
            'status' => ScheduleTaskRun::STATUS_RUNNING,
        ]);
    }

    private function createRunningRun(): ScheduleTaskRun
    {
        return ScheduleTaskRun::query()->create([
            'task_key' => 'heartbeat-timeout-test',
            'task_name' => '心跳超时测试',
            'rule_description' => '测试',
            'source' => 'heartbeat',
            'queue' => 'automation',
            'status' => ScheduleTaskRun::STATUS_RUNNING,
            'queued_at' => now(),
            'started_at' => now(),
        ]);
    }

    private function payloadFor(RunHeartbeatTaskJob $job): string
    {
        return json_encode([
            'displayName' => $job::class,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => $job->tries,
            'timeout' => $job->timeout,
            'data' => [
                'commandName' => $job::class,
                'command' => serialize($job),
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function makeJob(string $rawBody, int $attempts): object
    {
        return new class($rawBody, $attempts)
        {
            public function __construct(
                private readonly string $rawBody,
                private readonly int $attempts,
            ) {}

            public function getRawBody(): string
            {
                return $this->rawBody;
            }

            public function attempts(): int
            {
                return $this->attempts;
            }
        };
    }

    private function ensureScheduleTaskRunsTable(): void
    {
        if (Schema::hasTable('schedule_task_runs')) {
            return;
        }

        Schema::create('schedule_task_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('schedule_tick_id')->nullable()->index();
            $table->string('task_key', 120);
            $table->string('task_name', 160);
            $table->string('rule_description', 160)->nullable();
            $table->string('source', 40)->default('heartbeat');
            $table->string('queue', 80)->nullable();
            $table->string('status', 30)->default('queued');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('summary')->nullable();
            $table->text('error_msg')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['schedule_tick_id', 'task_key', 'source'], 'schedule_task_runs_tick_task_source_unique');
            $table->index(['task_key', 'created_at']);
            $table->index(['status', 'created_at']);
            $table->index(['source', 'created_at']);
        });
    }
}
