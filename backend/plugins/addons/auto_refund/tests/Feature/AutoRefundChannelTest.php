<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/../Support/InstallsAutoRefundAddon.php';

use App\Jobs\DeliverTicketToUpstreamJob;
use App\Models\AdminUser;
use App\Models\Role;
use App\Models\Service;
use App\Models\Supplier;
use App\Models\Ticket;
use App\Models\TicketDeliveryRule;
use App\Models\TicketReply;
use App\Models\TicketUpstreamBinding;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InstallsAutoRefundAddon;
use Tests\TestCase;
use TuraIDC\Plugins\Addons\AutoRefund\Models\AutoRefundApplication;
use TuraIDC\Plugins\Addons\AutoRefund\Models\AutoRefundProductRule;

/**
 * 四种退款类型的分流（`manual` / `auto` / `bridge` / `api_ticket`）与上游工单两条协议。
 *
 * 上游一律 `Http::fake()`，**绝不发真实请求**。断言重点不是「调用发生没发生」，
 * 而是「钱和留痕按类型落在了正确的地方」：谁进了余额、谁建了工单、谁只落台账、
 * 谁带了护栏（闸门保留 / 释放）。
 */
class AutoRefundChannelTest extends TestCase
{
    use DatabaseTransactions;
    use InstallsAutoRefundAddon;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->installAutoRefundAddon();
        $this->user = $this->makeRefundUser();
    }

    public function test_manual_type_only_records_the_ledger_and_never_calls_upstream(): void
    {
        Http::fake();

        $fixture = $this->makeRefundService($this->user, [
            'host_id' => '7001',
            'amount' => '100.00',
            'refund_type' => AutoRefundProductRule::TYPE_MANUAL,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/auto-refund/applications', [
                'service_id' => (int) $fixture['service']->id,
                'reason' => '人工审核通道',
            ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', AutoRefundApplication::STATUS_PENDING_REVIEW)
            ->assertJsonPath('data.refund_type', AutoRefundProductRule::TYPE_MANUAL)
            ->assertJsonPath('data.channel', AutoRefundApplication::CHANNEL_LOCAL)
            ->assertJsonPath('data.ticket_id', null);

        Http::assertNothingSent();

        $application = AutoRefundApplication::query()->where('service_id', (int) $fixture['service']->id)->firstOrFail();
        $this->assertSame(AutoRefundApplication::CHANNEL_LOCAL, (string) $application->channel);
        $this->assertSame((int) $fixture['service']->id, (int) $application->active_service_id);

        // 提交时的策略快照必须落库：事后要能解释「当时为什么能退、金额怎么算的」
        $this->assertIsArray($application->policy_snapshot);
        $this->assertSame('100.00', $application->policy_snapshot['resolved']['amount']);
        $this->assertSame(AutoRefundProductRule::TYPE_MANUAL, $application->policy_snapshot['refund_type']);
        $this->assertSame('product_first', $application->policy_snapshot['requirement']);
    }

    public function test_auto_type_settles_immediately_and_credits_the_balance(): void
    {
        Http::fake();

        $fixture = $this->makeRefundService($this->user, [
            'host_id' => '7002',
            'amount' => '100.00',
            'refund_rule' => AutoRefundProductRule::RULE_FULL,
            'refund_type' => AutoRefundProductRule::TYPE_AUTO,
        ]);

        $balanceBefore = (float) $this->user->fresh()->balance;

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/auto-refund/applications', [
                'service_id' => (int) $fixture['service']->id,
                'reason' => '自动退款通道',
            ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', AutoRefundApplication::STATUS_SETTLED)
            ->assertJsonPath('data.settled_amount', '100.00')
            ->assertJsonPath('data.refund_type', AutoRefundProductRule::TYPE_AUTO)
            ->assertJsonPath('data.channel', AutoRefundApplication::CHANNEL_LOCAL);

        Http::assertNothingSent();

        $application = AutoRefundApplication::query()->where('service_id', (int) $fixture['service']->id)->firstOrFail();
        $this->assertSame(AutoRefundApplication::STATUS_SETTLED, (string) $application->status);
        $this->assertNotNull($application->settled_at);
        // 结算必须走既有账务链路：退款单落库、余额增加、闸门释放
        $this->assertNull($application->active_service_id);
        $this->assertSame(
            1,
            (int) DB::table('refunds')->where('invoice_id', (int) $fixture['invoice']->id)->count(),
        );
        $this->assertEqualsWithDelta(
            $balanceBefore + 100.0,
            (float) $this->user->fresh()->balance,
            0.001,
        );
    }

    public function test_bridge_type_submits_to_upstream_without_an_amount(): void
    {
        $fixture = $this->makeRefundService($this->user, [
            'host_id' => '7003',
            'refund_type' => AutoRefundProductRule::TYPE_BRIDGE,
        ]);

        $this->fakeBridgeUpstream(['7003'], ['code' => 200, 'msg' => '申请已创建', 'trace_id' => 'BR-TRACE-1']);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/auto-refund/applications', [
                'service_id' => (int) $fixture['service']->id,
                'reason' => '插件间对接通道',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', AutoRefundApplication::STATUS_PENDING_REVIEW)
            ->assertJsonPath('data.channel', AutoRefundApplication::CHANNEL_BRIDGE)
            ->assertJsonPath('data.refund_type', AutoRefundProductRule::TYPE_BRIDGE)
            ->assertJsonPath('data.trace_id', 'BR-TRACE-1');

        // 商业版契约：调用方不得指定金额 —— 表单里绝不能出现 amount
        Http::assertSent(function (Request $request): bool {
            return $request->isForm()
                && $request['action'] === 'submit'
                && ! array_key_exists('amount', $request->data());
        });
    }

    public function test_api_ticket_type_submits_a_zjfm_ticket_on_302_and_records_the_local_ticket(): void
    {
        $fixture = $this->makeRefundService($this->user, [
            'host_id' => '7101',
            'refund_type' => AutoRefundProductRule::TYPE_API_TICKET,
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'login_api')) {
                return Http::response(['jwt' => $this->fakeJwt()], 200);
            }

            if (str_contains($request->url(), 'submitticket')) {
                return Http::response('', 302, ['Location' => 'https://upstream.example.test/supporttickets']);
            }

            return Http::response('unexpected', 500);
        });

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/auto-refund/applications', [
                'service_id' => (int) $fixture['service']->id,
                'reason' => '工单退款通道',
            ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', AutoRefundApplication::STATUS_PENDING_REVIEW)
            ->assertJsonPath('data.channel', AutoRefundApplication::CHANNEL_API_TICKET)
            ->assertJsonPath('data.refund_type', AutoRefundProductRule::TYPE_API_TICKET);

        $application = AutoRefundApplication::query()->where('service_id', (int) $fixture['service']->id)->firstOrFail();
        $this->assertNotNull($application->ticket_id);

        $ticket = Ticket::query()->find((int) $application->ticket_id);
        $this->assertInstanceOf(Ticket::class, $ticket);
        $this->assertSame('billing', (string) $ticket->department);
        $this->assertSame((int) $fixture['service']->id, (int) $ticket->service_id);
        $this->assertSame(1, (int) TicketReply::query()->where('ticket_id', (int) $ticket->id)->count());

        // zjfm 协议拿不到上游工单号：这一格允许为空，但要在摘要里说明
        $this->assertNull($application->upstream_ticket_id);
        $this->assertSame('上游未返回工单号', $application->response_meta['ticket_channel']['note'] ?? null);

        // multipart 表单 + Bearer 头 + dptid 参数，字段名必须是 title/content/host_id
        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'submitticket?step=2&dptid=2')) {
                return false;
            }

            $body = $request->body();

            return $request->isMultipart()
                && str_contains((string) $request->header('Authorization')[0] ?? '', 'Bearer ')
                && str_contains($body, 'name="title"')
                && str_contains($body, 'name="content"')
                && str_contains($body, 'name="host_id"')
                && str_contains($body, '7101');
        });
    }

    public function test_api_ticket_also_accepts_the_200_json_variant(): void
    {
        $fixture = $this->makeRefundService($this->user, [
            'host_id' => '7102',
            'refund_type' => AutoRefundProductRule::TYPE_API_TICKET,
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'login_api')) {
                return Http::response(['jwt' => $this->fakeJwt()], 200);
            }

            // 变体形态：直接 200 + {"code":200}
            return Http::response(['code' => 200, 'msg' => 'ok'], 200);
        });

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/auto-refund/applications', [
                'service_id' => (int) $fixture['service']->id,
                'reason' => '变体判据',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', AutoRefundApplication::STATUS_PENDING_REVIEW);
    }

    public function test_api_ticket_failure_marks_the_application_failed_and_rolls_back_the_gate(): void
    {
        $fixture = $this->makeRefundService($this->user, [
            'host_id' => '7103',
            'refund_type' => AutoRefundProductRule::TYPE_API_TICKET,
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'login_api')) {
                return Http::response(['jwt' => $this->fakeJwt()], 200);
            }

            return Http::response('upstream exploded', 500);
        });

        $ticketsBefore = Ticket::query()->count();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/auto-refund/applications', [
                'service_id' => (int) $fixture['service']->id,
                'reason' => '上游失败',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);

        $application = AutoRefundApplication::query()->where('service_id', (int) $fixture['service']->id)->firstOrFail();
        $this->assertSame(AutoRefundApplication::STATUS_FAILED, (string) $application->status);
        // 上游没建单：闸门必须释放，不能把用户永久锁死在这台机器上
        $this->assertNull($application->active_service_id);
        $this->assertStringContainsString('HTTP 500', (string) $application->upstream_msg);
        // 失败不留在本站建单：先上游后本地
        $this->assertSame($ticketsBefore, Ticket::query()->count());
        $this->assertNull($application->ticket_id);
    }

    public function test_api_ticket_refuses_to_submit_without_ticket_credentials(): void
    {
        $this->configureAutoRefund([
            'ticket_protocol' => 'zjfm',
            'ticket_hostname' => self::TEST_TICKET_HOSTNAME,
            'ticket_username' => '',
            'ticket_department_id' => '',
        ]);

        $fixture = $this->makeRefundService($this->user, [
            'host_id' => '7104',
            'refund_type' => AutoRefundProductRule::TYPE_API_TICKET,
        ]);

        Http::fake();

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/auto-refund/applications', [
                'service_id' => (int) $fixture['service']->id,
                'reason' => '未配置凭据',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);

        $this->assertStringContainsString('工单退款通道未就绪', (string) $response->json('message'));
        $this->assertStringContainsString('ticket_username', (string) $response->json('message'));
        // 配置没填齐时连台账都不该留：绝不带空密钥发单
        $this->assertSame(0, AutoRefundApplication::query()->count());
        Http::assertNothingSent();
    }

    public function test_api_ticket_creates_a_tura_ticket_and_binds_it_to_the_upstream_service(): void
    {
        $this->configureAutoRefund([
            'ticket_protocol' => 'tura',
            'ticket_department_id' => '8',
        ]);

        $fixture = $this->makeRefundService($this->user, [
            'host_id' => '7201',
            'refund_type' => AutoRefundProductRule::TYPE_API_TICKET,
        ]);

        $supplierId = $this->makeTuraUpstreamFixture($fixture['service'], '7201');

        // 投递动作不进真实队列、也不打真实上游：只断言「该发的那一条发出去了」
        Queue::fake();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/auto-refund/applications', [
                'service_id' => (int) $fixture['service']->id,
                'reason' => 'TuraIDC 工单协议',
            ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', AutoRefundApplication::STATUS_PENDING_REVIEW)
            ->assertJsonPath('data.channel', AutoRefundApplication::CHANNEL_API_TICKET);

        $application = AutoRefundApplication::query()->where('service_id', (int) $fixture['service']->id)->firstOrFail();
        $this->assertNotNull($application->ticket_id);

        $ticket = Ticket::query()->find((int) $application->ticket_id);
        $this->assertInstanceOf(Ticket::class, $ticket);
        $this->assertSame('billing', (string) $ticket->department);
        $this->assertSame(1, (int) TicketReply::query()->where('ticket_id', (int) $ticket->id)->count());

        // 绑定由既有的 ticket_upstream_bindings 承载，供应商/部门/上游实例三个键都要对上
        $binding = TicketUpstreamBinding::query()->where('ticket_id', (int) $ticket->id)->first();
        $this->assertInstanceOf(TicketUpstreamBinding::class, $binding);
        $this->assertSame('zjmf_finance_api', (string) $binding->provider_key);
        $this->assertSame($supplierId, (int) $binding->supplier_id);
        $this->assertSame('8', (string) $binding->upstream_department_id);
        $this->assertSame('7201', (string) $binding->upstream_service_id);
        $this->assertSame('pending', (string) $binding->status);

        Queue::assertPushed(DeliverTicketToUpstreamJob::class, fn ($job) => (int) $job->ticketId === (int) $ticket->id);

        // 投递留痕走既有 ticket_upstream_delivery_logs，不新建日志表
        $this->assertSame(
            1,
            (int) DB::table('ticket_upstream_delivery_logs')
                ->where('ticket_id', (int) $ticket->id)
                ->where('event', 'queued')
                ->count(),
        );

        // 异步投递：提交那一刻上游还没建单，工单号只能等投递完成后回填；
        // 台账读的时候会回查绑定，所以管理员点开详情一定能看到上游工单号。
        $this->assertNull($application->upstream_ticket_id);

        TicketUpstreamBinding::query()->whereKey($binding->id)->update([
            'status' => 'delivered',
            'upstream_ticket_id' => 'TURA-TICKET-9',
            'delivered_at' => now(),
        ]);

        $admin = $this->makeAdmin([AdminPermissions::INVOICE_LIST]);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v2/admin/auto-refund/applications/'.(int) $application->id)
            ->assertOk()
            ->assertJsonPath('data.upstream_ticket_id', 'TURA-TICKET-9')
            ->assertJsonPath('data.channel', AutoRefundApplication::CHANNEL_API_TICKET)
            ->assertJsonPath('data.ticket_id', (int) $ticket->id);
    }

    public function test_manual_type_stays_available_when_no_outbound_channel_is_configured(): void
    {
        // 只配 `manual` 策略、没填任何上游凭据的站点是正常部署形态：
        // 页面要开着、列表不能因为「上游不可用」整页降级。
        $fixture = $this->makeRefundService($this->user, [
            'host_id' => '7004',
            'refund_type' => AutoRefundProductRule::TYPE_MANUAL,
        ]);
        $this->wipeAutoRefundConfig();

        Http::fake();

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v2/client/auto-refund/bootstrap')
            ->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.open', true);

        $candidates = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v2/client/auto-refund/candidates')
            ->assertOk();

        $this->assertTrue((bool) $candidates->json('data.upstream_available'));
        $this->assertNull($candidates->json('data.upstream_error'));

        $row = collect($candidates->json('data.items'))->firstWhere('service_id', (int) $fixture['service']->id);
        $this->assertNotNull($row);
        $this->assertTrue((bool) $row['refundable']);
        $this->assertSame(AutoRefundProductRule::TYPE_MANUAL, $row['refund_type']);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/auto-refund/applications', [
                'service_id' => (int) $fixture['service']->id,
                'reason' => '不出站的人工审核',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', AutoRefundApplication::STATUS_PENDING_REVIEW);

        Http::assertNothingSent();
    }

    public function test_api_ticket_type_does_not_require_the_bridge_channel(): void
    {
        // 只用工单通道、压根没配 api.php 的站点：不该被「上游列表里没有这台」挡住
        $fixture = $this->makeRefundService($this->user, [
            'host_id' => '7105',
            'refund_type' => AutoRefundProductRule::TYPE_API_TICKET,
        ]);

        $this->configureAutoRefund([
            'api_base_url' => '',
            'ticket_protocol' => 'zjfm',
            'ticket_hostname' => self::TEST_TICKET_HOSTNAME,
            'ticket_username' => self::TEST_TICKET_USERNAME,
            'ticket_api_key' => self::TEST_TICKET_API_KEY,
            'ticket_department_id' => self::TEST_TICKET_DEPARTMENT_ID,
        ]);

        Http::fake(function (Request $request) {
            if (str_contains($request->url(), 'login_api')) {
                return Http::response(['jwt' => $this->fakeJwt()], 200);
            }

            if (str_contains($request->url(), 'submitticket')) {
                return Http::response('', 302, ['Location' => 'https://upstream.example.test/supporttickets']);
            }

            return Http::response('should not be called', 500);
        });

        $candidates = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v2/client/auto-refund/candidates')
            ->assertOk();

        $this->assertTrue((bool) $candidates->json('data.upstream_available'));
        $this->assertNull($candidates->json('data.upstream_error'));

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/auto-refund/applications', [
                'service_id' => (int) $fixture['service']->id,
                'reason' => '只用工单通道',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', AutoRefundApplication::STATUS_PENDING_REVIEW);

        // 一次都不该往 api.php 打
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'auto_refund/api.php'));
    }

    public function test_tura_refuses_to_send_when_no_upstream_department_is_configured(): void
    {
        // 既没填 ticket_department_id，也没有匹配的工单传递规则：报错并整体回滚，
        // 绝不允许拿一个猜出来的部门去发单。
        $this->configureAutoRefund([
            'ticket_protocol' => 'tura',
            'ticket_department_id' => '',
        ]);

        $fixture = $this->makeRefundService($this->user, [
            'host_id' => '7202',
            'refund_type' => AutoRefundProductRule::TYPE_API_TICKET,
        ]);

        $this->makeTuraUpstreamFixture($fixture['service'], '7202', withDeliveryRule: false);

        Queue::fake();
        Http::fake();

        $ticketsBefore = Ticket::query()->count();

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/auto-refund/applications', [
                'service_id' => (int) $fixture['service']->id,
                'reason' => '缺部门配置',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);

        $application = AutoRefundApplication::query()->where('service_id', (int) $fixture['service']->id)->firstOrFail();
        $this->assertSame(AutoRefundApplication::STATUS_FAILED, (string) $application->status);
        $this->assertNull($application->active_service_id);
        $this->assertNull($application->ticket_id);
        $this->assertStringContainsString('未配置上游工单部门', (string) $application->upstream_msg);
        // 工单跟着事务回滚：不留一张没人处理的孤儿工单
        $this->assertSame($ticketsBefore, Ticket::query()->count());
        Http::assertNothingSent();
    }

    /**
     * bridge 通道的固定上游行为：`query` 返回指定 hostid，`submit` 返回给好的业务码。
     *
     * @param  array<int, string>  $hostIds
     * @param  array<string, mixed>  $submitResponse
     */
    private function fakeBridgeUpstream(array $hostIds, array $submitResponse): void
    {
        $data = array_map(static fn (string $id): array => ['hostid' => $id], $hostIds);

        Http::fake(function (Request $request) use ($data, $submitResponse) {
            $action = (string) ($request['action'] ?? '');

            return match ($action) {
                'query' => Http::response(['code' => 200, 'msg' => 'ok', 'data' => $data], 200),
                'submit' => Http::response($submitResponse, 200),
                default => Http::response(['code' => 405, 'msg' => 'unexpected action'], 200),
            };
        });
    }

    /**
     * 造一条能真正跑通「工单投递到上游」的依赖链：
     * 供应商 + 供应商接口绑定 + 商品上游绑定 + 服务上游绑定 + 工单传递规则。
     *
     * 这几张表就是 `TicketDeliveryService` 判定「这条工单该不该发、发给谁」的全部依据，
     * 少一张都会退化成 `rule_not_matched` 静默不发。
     */
    private function makeTuraUpstreamFixture(Service $service, string $upstreamServiceId, bool $withDeliveryRule = true): int
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));

        $supplier = Supplier::query()->create([
            'name' => 'Tura 上游 '.$suffix,
            'code' => 'tura_'.strtolower($suffix),
            'api_url' => 'https://upstream.example.test',
            'api_username' => 'agent@example.test',
            'api_key' => 'upstream-api-key',
            'status' => 1,
            'sort_order' => 0,
        ]);

        // 绑定表的 plugin_id 外键指向 integration_plugins：上游驱动必须先「已安装」
        $upstreamPluginId = (int) DB::table('integration_plugins')->insertGetId([
            'domain' => 'upstream',
            'slug' => 'zjmf_finance_'.$suffix,
            'plugin_key' => 'zjmf_finance_api_'.strtolower($suffix),
            'name' => 'ZJMF 财务上游 '.$suffix,
            'version' => '1.0.0',
            'entry_class' => 'TuraIDC\\Plugins\\Servers\\ZjmfFinance\\ZjmfFinancePlugin',
            'provider_class' => null,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $supplierBindingId = (int) DB::table('supplier_plugin_bindings')->insertGetId([
            'supplier_id' => (int) $supplier->id,
            'plugin_id' => $upstreamPluginId,
            'provider_key' => 'zjmf_finance_api',
            'environment' => 'production',
            'status' => 1,
            'ticket_delivery_enabled' => 1,
            'priority' => 0,
            'base_url' => 'https://upstream.example.test',
            'account_name' => 'agent@example.test',
            'config_json' => json_encode(['source' => 'auto_refund_test']),
            'secret_json' => Crypt::encryptString(json_encode([
                'api_key' => 'upstream-api-key',
                'web_session_cookie' => null,
                'provider_config' => [],
            ])),
            'has_secret_json' => json_encode(['api_key' => true]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productBindingId = (int) DB::table('product_upstream_bindings')->insertGetId([
            'product_id' => (int) $service->product_id,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'plugin_id' => $upstreamPluginId,
            'provider_key' => 'zjmf_finance_api',
            'upstream_product_id' => 'p-'.$suffix,
            'upstream_product_snapshot_json' => json_encode(['name' => '上游商品']),
            'option_schema_json' => json_encode([]),
            'provision_policy_json' => json_encode([]),
            'auto_setup' => 0,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('service_upstream_bindings')->insert([
            'service_id' => (int) $service->id,
            'supplier_plugin_binding_id' => $supplierBindingId,
            'product_upstream_binding_id' => $productBindingId,
            'plugin_id' => $upstreamPluginId,
            'provider_key' => 'zjmf_finance_api',
            'upstream_service_id' => $upstreamServiceId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($withDeliveryRule) {
            TicketDeliveryRule::query()->create([
                'name' => '退款工单规则 '.$suffix,
                'department' => 'billing',
                'supplier_id' => (int) $supplier->id,
                'provider_key' => 'zjmf_finance_api',
                'product_scope_mode' => 'all',
                'upstream_department_id' => '8',
                'enabled' => true,
                'sync_admin_replies' => false,
            ]);
        }

        return (int) $supplier->id;
    }

    /**
     * 造一个结构合法的 JWT（header.payload.signature，payload 带 exp）。
     *
     * 上游真实返回的是真 JWT；这里只要求「能被当令牌传下去、能被解析出过期时间」，
     * 签名是否有效由上游校验，本站不验。
     */
    private function fakeJwt(): string
    {
        $encode = static fn (array $payload): string => rtrim(
            strtr(base64_encode((string) json_encode($payload)), '+/', '-_'),
            '='
        );

        return $encode(['alg' => 'HS256', 'typ' => 'JWT'])
            .'.'.$encode(['iss' => 'upstream.example.test', 'exp' => time() + 3600])
            .'.signature-not-verified';
    }

    /**
     * 造一个只读台账的管理员。
     *
     * @param  array<int, string>  $permissions
     */
    private function makeAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));

        $role = Role::query()->create([
            'name' => 'auto-refund-role-'.$suffix,
            'label' => 'Auto Refund Role',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'auto-refund-admin-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'AR Admin',
            'email' => 'auto-refund-admin-'.$suffix.'@example.test',
            'status' => 1,
        ]);
    }
}
