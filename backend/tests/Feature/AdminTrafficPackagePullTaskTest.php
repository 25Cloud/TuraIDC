<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\BusinessException;
use App\Jobs\PullTrafficPackageCatalogJob;
use App\Models\AdminUser;
use App\Models\FirstProductGroup;
use App\Models\Role;
use App\Models\SecondProductGroup;
use App\Services\Admin\V2\AdminCatalogActionV2Service;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 一键拉取流量包档位改异步后的行为契约。
 *
 * 锁三件事：
 * 1. `QUEUE_CONNECTION=sync`（本地/测试没有 worker）时必须就地执行并返回 done + 完整结果，
 *    响应顶层继续带老的 source/traffic/packages，老前端不改也能用；
 * 2. 有队列时提交立即返回 pending 任务，轮询接口能读到 running 的关键节点（调用上游时进度 60%）与终态；
 * 3. 失败要落到任务状态里（error 可读），同步路径同时保持改造前的 422 业务错误信封。
 */
class AdminTrafficPackagePullTaskTest extends TestCase
{
    use DatabaseTransactions;

    public function test_sync_queue_runs_inline_and_keeps_legacy_response_fields(): void
    {
        config(['queue.default' => 'sync']);
        $secondGroup = $this->createSecondGroup();
        $this->bindPullAction($this->sampleResult());

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_SYNC]));

        $response = $this->postJson('/api/v2/admin/products/traffic-package-pulls', [
            'second_product_group_id' => (int) $secondGroup->id,
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', PullTrafficPackageCatalogJob::STATUS_DONE)
            ->assertJsonPath('data.progress', 100)
            ->assertJsonPath('data.step', '拉取完成')
            // 老字段平铺在顶层：老前端读 data.packages / data.source 仍然拿得到
            ->assertJsonPath('data.packages.0.name', '流量包 100G')
            ->assertJsonPath('data.source.mode', 'service')
            ->assertJsonPath('data.result.packages.0.name', '流量包 100G')
            // 既有净化不能丢：上游结果里的 token 不得下发
            ->assertJsonMissingPath('data.packages.0.token');

        $taskId = (string) $response->json('data.task_id');
        $this->assertNotSame('', $taskId);
        $this->assertNotNull($response->json('data.finished_at'));

        // 同一个 task_id 走查询接口，返回同一份终态
        $this->getJson('/api/v2/admin/products/traffic-package-pulls/'.$taskId)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.task_id', $taskId)
            ->assertJsonPath('data.status', PullTrafficPackageCatalogJob::STATUS_DONE)
            ->assertJsonPath('data.packages.0.name', '流量包 100G');
    }

    public function test_async_submit_returns_pending_then_polling_reports_progress_and_done(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);

        $secondGroup = $this->createSecondGroup();
        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_SYNC]));

        $response = $this->postJson('/api/v2/admin/products/traffic-package-pulls', [
            'second_product_group_id' => (int) $secondGroup->id,
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', PullTrafficPackageCatalogJob::STATUS_PENDING)
            ->assertJsonPath('data.progress', 0)
            ->assertJsonPath('data.packages', []);

        $taskId = (string) $response->json('data.task_id');
        $this->assertTrue(Str::isUuid($taskId));

        Queue::assertPushed(
            PullTrafficPackageCatalogJob::class,
            static fn (PullTrafficPackageCatalogJob $job): bool => $job->taskId === $taskId
                // 必须落在 queue:drain 实际消费的 default 队列上，
                // 换成别的队列名在存量部署里没人消费，前端会一直轮询到超时
                && $job->queue === 'default'
        );

        $this->getJson('/api/v2/admin/products/traffic-package-pulls/'.$taskId)
            ->assertOk()
            ->assertJsonPath('data.status', PullTrafficPackageCatalogJob::STATUS_PENDING)
            ->assertJsonPath('data.progress', 0);

        // 模拟 worker 执行任务体
        $action = $this->bindPullAction($this->sampleResult(), $taskId);
        (new PullTrafficPackageCatalogJob($taskId, ['second_product_group_id' => (int) $secondGroup->id]))
            ->handle($action);

        // 关键节点：真正调用上游时，状态已经是 running / 60%，前端据此显示「读取上游流量包档位」
        $observed = $action->observedState ?? [];
        $this->assertSame(PullTrafficPackageCatalogJob::STATUS_RUNNING, $observed['status'] ?? null);
        $this->assertSame(60, $observed['progress'] ?? null);
        $this->assertStringContainsString('读取上游流量包档位', (string) ($observed['step'] ?? ''));

        $this->getJson('/api/v2/admin/products/traffic-package-pulls/'.$taskId)
            ->assertOk()
            ->assertJsonPath('data.status', PullTrafficPackageCatalogJob::STATUS_DONE)
            ->assertJsonPath('data.progress', 100)
            ->assertJsonPath('data.packages.0.name', '流量包 100G')
            ->assertJsonPath('data.error', null)
            ->assertJsonPath('data.result.source.mode', 'service');
    }

    public function test_sync_failure_returns_business_error_and_keeps_failed_state(): void
    {
        config(['queue.default' => 'sync']);
        $secondGroup = $this->createSecondGroup();
        $this->bindPullAction([], '', new BusinessException('未从上游解析到可导入的流量包档位'));

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_SYNC]));

        $response = $this->postJson('/api/v2/admin/products/traffic-package-pulls', [
            'second_product_group_id' => (int) $secondGroup->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);

        $this->assertStringContainsString('流量包档位', (string) $response->json('message'));

        $taskId = (string) $response->json('data.task_id');
        $this->assertNotSame('', $taskId);

        $this->getJson('/api/v2/admin/products/traffic-package-pulls/'.$taskId)
            ->assertOk()
            ->assertJsonPath('data.status', PullTrafficPackageCatalogJob::STATUS_FAILED)
            ->assertJsonPath('data.error', '未从上游解析到可导入的流量包档位');
    }

    public function test_async_failure_is_visible_through_polling(): void
    {
        config(['queue.default' => 'database']);
        $secondGroup = $this->createSecondGroup();
        $taskId = (string) Str::uuid();
        PullTrafficPackageCatalogJob::seedState($taskId);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_SYNC]));

        $action = $this->bindPullAction([], $taskId, new BusinessException('上游实例访问超时'));

        try {
            (new PullTrafficPackageCatalogJob($taskId, ['second_product_group_id' => (int) $secondGroup->id]))
                ->handle($action);
            $this->fail('任务失败时必须继续抛给队列，让 failed_jobs 记下来');
        } catch (BusinessException $exception) {
            $this->assertSame('上游实例访问超时', $exception->getMessage());
        }

        $this->getJson('/api/v2/admin/products/traffic-package-pulls/'.$taskId)
            ->assertOk()
            ->assertJsonPath('data.status', PullTrafficPackageCatalogJob::STATUS_FAILED)
            ->assertJsonPath('data.error', '上游实例访问超时')
            ->assertJsonPath('data.step', '拉取失败');

        // 队列判死回调不应把已失败的任务又写回 running/pending
        (new PullTrafficPackageCatalogJob($taskId, []))->failed(new \RuntimeException('worker 超时'));
        $this->getJson('/api/v2/admin/products/traffic-package-pulls/'.$taskId)
            ->assertOk()
            ->assertJsonPath('data.status', PullTrafficPackageCatalogJob::STATUS_FAILED);
    }

    public function test_unknown_task_returns_project_error_envelope(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_SYNC]));

        $this->getJson('/api/v2/admin/products/traffic-package-pulls/'.Str::uuid())
            ->assertStatus(404)
            ->assertJsonPath('code', 40400);
    }

    public function test_pull_endpoints_require_product_sync_permission(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::PRODUCT_LIST]));

        $this->postJson('/api/v2/admin/products/traffic-package-pulls', ['second_product_group_id' => 1])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        $this->getJson('/api/v2/admin/products/traffic-package-pulls/'.Str::uuid())
            ->assertForbidden()
            ->assertJsonPath('code', 40300);
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleResult(): array
    {
        return [
            'source' => [
                'mode' => 'service',
                'service_id' => 31,
                'supplier_name' => '测试供应商',
            ],
            'traffic' => ['used' => 10, 'total' => 100],
            'packages' => [
                ['name' => '流量包 100G', 'price' => 20, 'token' => 'must-not-leak'],
            ],
        ];
    }

    /**
     * 用匿名子类顶掉动作服务：任务体只调 `pullTrafficPackageCatalog()`，
     * 这样不必碰 `ServiceTrafficPackageService`（另一边在改）也不会真的访问上游。
     *
     * @param  array<string, mixed>  $result
     */
    private function bindPullAction(array $result, string $taskId = '', ?\Throwable $exception = null): AdminCatalogActionV2Service
    {
        $fake = new class($result, $taskId, $exception) extends AdminCatalogActionV2Service
        {
            /** @var array<string, mixed>|null 调用上游那一刻读到的任务状态 */
            public ?array $observedState = null;

            /**
             * @param  array<string, mixed>  $result
             */
            public function __construct(
                private array $result,
                private string $taskId,
                private ?\Throwable $exception,
            ) {}

            public function pullTrafficPackageCatalog(array $payload): array
            {
                if ($this->taskId !== '') {
                    $this->observedState = PullTrafficPackageCatalogJob::readState($this->taskId);
                }

                if ($this->exception !== null) {
                    throw $this->exception;
                }

                return $this->result;
            }
        };

        $this->app->instance(AdminCatalogActionV2Service::class, $fake);

        return $fake;
    }

    private function createSecondGroup(): SecondProductGroup
    {
        $suffix = bin2hex(random_bytes(4));
        $firstGroup = FirstProductGroup::query()->create([
            'code' => 'pull-task-'.$suffix,
            'name' => 'Pull Task '.$suffix,
            'slug' => 'pull-task-'.$suffix,
            'description' => 'Pull Task',
            'sort_order' => 1,
            'is_visible' => 1,
            'is_system' => 0,
            'legacy_product_type' => 'pull-task-'.$suffix,
        ]);

        return SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => 'Pull Task Second '.$suffix,
            'slug' => 'pull-task-second-'.$suffix,
            'description' => 'Pull Task Second',
            'sort_order' => 1,
            'is_visible' => 1,
        ]);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'pull-task-'.$suffix,
            'label' => 'Pull Task',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'pull-task-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Pull Task',
            'email' => 'pull-task-'.$suffix.'@example.com',
            // status 必须显式给 1：中间件把「非启用」直接判 403「管理员账号已被禁用」
            'status' => 1,
        ]);
    }
}
