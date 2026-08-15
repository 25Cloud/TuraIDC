<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunHeartbeatTaskJob;
use App\Models\AdminUser;
use App\Models\Role;
use App\Models\ScheduleTaskRun;
use App\Services\System\OperationLogService;
use App\Support\AdminPermissions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class ScheduleTaskRunAdminApiTest extends TestCase
{
    /** @var list<int> */
    private array $runIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->ensureScheduleTaskRunTable();
    }

    protected function tearDown(): void
    {
        if (Schema::hasTable('schedule_task_runs') && $this->runIds !== []) {
            DB::table('schedule_task_runs')->whereIn('id', $this->runIds)->delete();
            // 人工重跑产生的子记录由服务创建、不在 runIds 中，若不清理会残留到共享测试库，
            // 使后续重跑测试的 activeRunForTask 误命中历史 queued 记录而 422。
            DB::table('schedule_task_runs')
                ->whereIn('parent_run_id', $this->runIds)
                ->whereIn('source', ['manual_retry', 'manual_retry_redispatch'])
                ->delete();
        }

        parent::tearDown();
    }

    public function test_schedule_runs_list_and_detail_require_view_permission(): void
    {
        // 运行台账是共享测试库中的持久数据；使用本测试专属键，避免历史/并行记录抢占倒序列表首位。
        $taskKey = $this->uniqueTaskKey();
        $run = $this->failedRun(['task_key' => $taskKey]);

        $this->getJson('/api/v2/admin/schedule-runs')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SETTINGS_VIEW]));
        $this->getJson('/api/v2/admin/schedule-runs')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SCHEDULE_VIEW]));
        $this->getJson('/api/v2/admin/schedule-runs?task_key='.$taskKey.'&status=failed&page=1&page_size=10')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', (int) $run->id)
            ->assertJsonPath('data.list.0.attempt', 3);

        $this->getJson('/api/v2/admin/schedule-runs/'.$run->id)
            ->assertOk()
            ->assertJsonPath('data.run.id', (int) $run->id)
            ->assertJsonPath('data.run.status', 'failed')
            ->assertJsonMissingPath('data.run.summary.password');

        $this->getJson('/api/v2/admin/schedule-runs?per_page=10')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);
    }

    public function test_only_schedule_retry_permission_can_retry_failed_run_once(): void
    {
        Queue::fake();
        config()->set('queue.default', 'array');
        $parent = $this->failedRun();
        $admin = $this->createAdmin([AdminPermissions::SCHEDULE_VIEW]);

        Sanctum::actingAs($admin);
        $this->postJson('/api/v2/admin/schedule-runs/'.$parent->id.'/retry', ['reason' => '核对后重跑'])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        $admin = $this->createAdmin([AdminPermissions::SCHEDULE_RETRY]);
        Sanctum::actingAs($admin);
        $this->mock(OperationLogService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('write')
                ->atLeast()->once()
                ->withAnyArgs()
                ->andReturnNull();
        });

        $response = $this->postJson('/api/v2/admin/schedule-runs/'.$parent->id.'/retry', [
            'reason' => '核对后重跑',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.detail.type', 'schedule_retry')
            ->assertJsonPath('data.detail.run.parent_run_id', (int) $parent->id);

        $childId = (int) $response->json('data.id');
        $this->runIds[] = $childId;
        $parent->refresh();

        $this->assertNotSame(0, $childId);
        $this->assertSame((int) $parent->id, (int) DB::table('schedule_task_runs')->where('id', $childId)->value('parent_run_id'));
        $this->assertSame('manual_retry', DB::table('schedule_task_runs')->where('id', $childId)->value('source'));
        $this->assertSame((int) $admin->id, (int) $parent->manual_retry_by);
        Queue::assertPushed(RunHeartbeatTaskJob::class, fn (RunHeartbeatTaskJob $job): bool => $job->taskRunId === $childId
            && $job->source === 'manual_retry');

        $this->postJson('/api/v2/admin/schedule-runs/'.$parent->id.'/retry', ['reason' => '再次重跑'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200);
    }

    public function test_retry_rejects_non_failed_and_dispatch_failed_runs(): void
    {
        $admin = $this->createAdmin([AdminPermissions::SCHEDULE_RETRY]);
        Sanctum::actingAs($admin);

        foreach ([
            ScheduleTaskRun::STATUS_QUEUED,
            ScheduleTaskRun::STATUS_RUNNING,
            ScheduleTaskRun::STATUS_RETRYING,
            ScheduleTaskRun::STATUS_SUCCESS,
            ScheduleTaskRun::STATUS_DISPATCH_FAILED,
        ] as $status) {
            $run = $this->runWithStatus($status);
            $this->postJson('/api/v2/admin/schedule-runs/'.$run->id.'/retry', ['reason' => '状态校验'])
                ->assertUnprocessable()
                ->assertJsonPath('code', 42200);
        }
    }

    public function test_retry_redispatches_manual_retry_run_after_dispatch_failed(): void
    {
        Queue::fake();
        config()->set('queue.default', 'array');
        $parent = $this->failedRun();
        $admin = $this->createAdmin([AdminPermissions::SCHEDULE_RETRY]);
        Sanctum::actingAs($admin);
        $this->mock(OperationLogService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('write')->atLeast()->once()->withAnyArgs()->andReturnNull();
        });

        // 第一次重跑成功，产生 manual_retry 子运行。
        $first = $this->postJson('/api/v2/admin/schedule-runs/'.$parent->id.'/retry', ['reason' => '首次重跑'])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', 'queued');
        $childId = (int) $first->json('data.id');
        $this->assertNotSame(0, $childId);

        // 模拟该子运行在队列派发阶段失败（基础设施瞬时不可用）。
        DB::table('schedule_task_runs')->where('id', $childId)->update([
            'status' => ScheduleTaskRun::STATUS_DISPATCH_FAILED,
            'error_msg' => '队列派发失败：connection refused',
            'updated_at' => now(),
        ]);

        // 对派发失败的 manual_retry 子运行再次重跑：应重派同一记录，而非死胡同。
        $retry = $this->postJson('/api/v2/admin/schedule-runs/'.$childId.'/retry', ['reason' => '恢复后重派'])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.id', $childId)
            ->assertJsonPath('data.detail.run.parent_run_id', (int) $parent->id);

        $this->assertSame(ScheduleTaskRun::STATUS_QUEUED, DB::table('schedule_task_runs')->where('id', $childId)->value('status'));
        $this->assertSame((int) $parent->id, (int) DB::table('schedule_task_runs')->where('id', $childId)->value('parent_run_id'));

        Queue::assertPushed(RunHeartbeatTaskJob::class, fn (RunHeartbeatTaskJob $job): bool => $job->taskRunId === $childId
            && $job->source === 'manual_retry_redispatch');
    }

    public function test_schedule_runs_list_supports_comma_separated_multi_status_filter(): void
    {
        $admin = $this->createAdmin([AdminPermissions::SCHEDULE_VIEW]);
        Sanctum::actingAs($admin);

        $taskKey = $this->uniqueTaskKey();
        $queued = $this->runWithStatus(ScheduleTaskRun::STATUS_QUEUED, ['task_key' => $taskKey]);
        $running = $this->runWithStatus(ScheduleTaskRun::STATUS_RUNNING, ['task_key' => $taskKey]);
        $failed = $this->runWithStatus(ScheduleTaskRun::STATUS_FAILED, ['task_key' => $taskKey]);

        $response = $this->getJson('/api/v2/admin/schedule-runs?task_key='.$taskKey.'&status=queued,running&page=1&page_size=10')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $ids = array_column((array) $response->json('data.list'), 'id');
        $this->assertCount(2, $ids);
        $this->assertContains((int) $queued->id, $ids);
        $this->assertContains((int) $running->id, $ids);
        $this->assertNotContains((int) $failed->id, $ids);

        $statuses = array_column((array) $response->json('data.list'), 'status');
        $this->assertNotContains(ScheduleTaskRun::STATUS_FAILED, $statuses);

        $this->getJson('/api/v2/admin/schedule-runs?task_key='.$taskKey.'&status=queued,invalid')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200);
    }

    /** @param array<string, mixed> $overrides */
    private function failedRun(array $overrides = []): ScheduleTaskRun
    {
        return $this->runWithStatus(ScheduleTaskRun::STATUS_FAILED, [
            'attempt' => 3,
            'summary' => ['processed' => 1, 'password' => 'must-not-leak'],
            'error_msg' => 'failed token=must-not-leak',
            ...$overrides,
        ]);
    }

    private function uniqueTaskKey(): string
    {
        return 'service-status-sync-test-'.bin2hex(random_bytes(8));
    }

    /** @param array<string, mixed> $overrides */
    private function runWithStatus(string $status, array $overrides = []): ScheduleTaskRun
    {
        $run = ScheduleTaskRun::query()->create(array_merge([
            'task_key' => 'service-status-sync',
            'task_name' => '服务状态同步',
            'rule_description' => '每次心跳',
            'source' => 'heartbeat',
            'queue' => 'automation',
            'status' => $status,
            'attempt' => 1,
            'queued_at' => now()->subMinutes(5),
            'finished_at' => $status === ScheduleTaskRun::STATUS_FAILED ? now() : null,
        ], $overrides));
        $this->runIds[] = (int) $run->id;

        return $run;
    }

    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'schedule-run-'.$suffix,
            'label' => 'Schedule Run Test',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'schedule-run-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Schedule Run Test',
            'email' => 'schedule-run-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    private function ensureScheduleTaskRunTable(): void
    {
        if (! Schema::hasTable('schedule_task_runs')) {
            Schema::create('schedule_task_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('schedule_tick_id')->nullable()->index();
                $table->unsignedBigInteger('parent_run_id')->nullable()->index();
                $table->string('task_key', 120);
                $table->string('task_name', 160);
                $table->string('rule_description', 160)->nullable();
                $table->string('source', 40)->default('heartbeat');
                $table->string('queue', 80)->nullable();
                $table->string('status', 30)->default('queued');
                $table->unsignedSmallInteger('attempt')->default(1);
                $table->unsignedInteger('duration_ms')->nullable();
                $table->json('summary')->nullable();
                $table->text('error_msg')->nullable();
                $table->timestamp('queued_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('manual_retry_at')->nullable();
                $table->unsignedBigInteger('manual_retry_by')->nullable();
                $table->timestamps();
            });
        }
    }
}
