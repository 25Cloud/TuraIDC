<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 管理端队列任务治理接口回归。
 *
 * 设计要点（与实现一一对应，改实现先改这里）：
 * - pending（jobs 表）只读：其中是尚未运行的业务任务，删除即静默丢弃，
 *   所以删除/清空在请求层就拒绝 pending，只放行 failed。
 * - 列表必须带连接元信息：队列运行时可能切到 redis 等驱动，
 *   前端靠它提示「本页看不见真实积压」的覆盖缺口。
 */
class AdminScheduleQueueJobsTest extends TestCase
{
    use DatabaseTransactions;

    /** @var list<int> */
    private array $jobIds = [];

    /** @var list<int> */
    private array $failedJobIds = [];

    protected function tearDown(): void
    {
        // 与其它共享库测试同款双保险：事务回滚之外再显式清一次，
        // 防止进程被中断时把脏行留给下一轮的外键/计数断言。
        if ($this->jobIds !== []) {
            DB::table('jobs')->whereIn('id', $this->jobIds)->delete();
        }
        if ($this->failedJobIds !== []) {
            DB::table('failed_jobs')->whereIn('id', $this->failedJobIds)->delete();
        }

        parent::tearDown();
    }

    public function test_queue_jobs_endpoints_require_the_manage_permission(): void
    {
        $this->getJson('/api/v2/admin/schedule-queue-jobs?queue=failed')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SCHEDULE_VIEW]));
        $this->getJson('/api/v2/admin/schedule-queue-jobs?queue=failed')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        // 未认证的写请求在本仓统一被鉴权中间件按 40300 拒绝（无 401 先例），照实际口径断言
        $this->deleteJson('/api/v2/admin/schedule-queue-jobs/failed/1')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);
    }

    public function test_list_returns_jobs_with_connection_metadata(): void
    {
        $jobId = $this->insertPendingJob(displayName: 'App\Jobs\SendUserNotificationJob');
        $failedId = $this->insertFailedJob(displayName: 'App\Jobs\SyncSupplierBalanceJob');

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SCHEDULE_QUEUE_MANAGE]));

        $pending = $this->getJson('/api/v2/admin/schedule-queue-jobs?queue=pending&page=1&page_size=10')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $pendingRow = collect($pending->json('data.list'))->firstWhere('id', $jobId);
        $this->assertNotNull($pendingRow, '插入的待执行任务必须出现在列表中');
        $this->assertSame('App\Jobs\SendUserNotificationJob', $pendingRow['display_name']);
        $this->assertSame('pending', $pendingRow['state']);
        $this->assertSame('referral', $pendingRow['queue']);
        $this->assertArrayHasKey('connection', $pending->json('data'), '列表必须带连接元信息供前端判断覆盖缺口');
        $this->assertSame((string) config('queue.default'), $pending->json('data.connection.runtime'));

        $failed = $this->getJson('/api/v2/admin/schedule-queue-jobs?queue=failed&page=1&page_size=10')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $failedRow = collect($failed->json('data.list'))->firstWhere('id', $failedId);
        $this->assertNotNull($failedRow, '插入的失败任务必须出现在列表中');
        $this->assertSame('App\Jobs\SyncSupplierBalanceJob', $failedRow['display_name']);
        $this->assertSame('failed', $failedRow['state']);
        $this->assertStringContainsString('上游余额同步失败', (string) $failedRow['exception']);
    }

    public function test_pending_queue_is_read_only(): void
    {
        $jobId = $this->insertPendingJob();

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SCHEDULE_QUEUE_MANAGE]));

        $this->deleteJson('/api/v2/admin/schedule-queue-jobs/pending/'.$jobId)
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200);

        $this->deleteJson('/api/v2/admin/schedule-queue-jobs/pending')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200);

        $this->assertSame(
            1,
            DB::table('jobs')->where('id', $jobId)->count(),
            '待执行任务必须原样保留'
        );
    }

    public function test_failed_job_can_be_deleted_and_leaves_an_audit_record(): void
    {
        $failedId = $this->insertFailedJob();

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SCHEDULE_QUEUE_MANAGE]));

        $this->deleteJson('/api/v2/admin/schedule-queue-jobs/failed/'.$failedId)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $failedId);

        $this->assertSame(0, DB::table('failed_jobs')->where('id', $failedId)->count());

        $this->deleteJson('/api/v2/admin/schedule-queue-jobs/failed/'.$failedId)
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200);
    }

    public function test_failed_queue_can_be_cleared(): void
    {
        $first = $this->insertFailedJob();
        $second = $this->insertFailedJob();

        Sanctum::actingAs($this->createAdmin([AdminPermissions::SCHEDULE_QUEUE_MANAGE]));

        $this->deleteJson('/api/v2/admin/schedule-queue-jobs/failed')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.deleted_count', 2);

        $this->assertSame(0, DB::table('failed_jobs')->whereIn('id', [$first, $second])->count());
    }

    public function test_unknown_queue_name_is_rejected(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::SCHEDULE_QUEUE_MANAGE]));

        $this->getJson('/api/v2/admin/schedule-queue-jobs?queue=redis')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200);
    }

    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'queue-job-'.$suffix,
            'label' => 'Queue Job Test',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'queue-job-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Queue Job Test',
            'email' => 'queue-job-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    private function insertPendingJob(string $displayName = 'App\Jobs\SendUserNotificationJob'): int
    {
        $id = (int) DB::table('jobs')->insertGetId([
            'queue' => 'referral',
            'payload' => json_encode([
                'uuid' => 'uuid-'.bin2hex(random_bytes(8)),
                'displayName' => $displayName,
                'job' => 'Illuminate\Queue\CallQueuedClosure',
                'maxTries' => 3,
                'data' => [],
            ], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => time(),
            'created_at' => time(),
        ]);
        $this->jobIds[] = $id;

        return $id;
    }

    private function insertFailedJob(string $displayName = 'App\Jobs\SyncSupplierBalanceJob'): int
    {
        $id = (int) DB::table('failed_jobs')->insertGetId([
            'uuid' => 'uuid-'.bin2hex(random_bytes(8)),
            'connection' => 'database',
            'queue' => 'referral',
            'payload' => json_encode([
                'uuid' => 'uuid-'.bin2hex(random_bytes(8)),
                'displayName' => $displayName,
                'job' => 'Illuminate\Queue\CallQueuedClosure',
                'maxTries' => 3,
                'data' => [],
            ], JSON_THROW_ON_ERROR),
            'exception' => "InvalidArgumentException: 上游余额同步失败\n#0 /var/www/app/Jobs/SyncSupplierBalanceJob.php(42)",
            'failed_at' => now(),
        ]);
        $this->failedJobIds[] = $id;

        return $id;
    }
}
