<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/../Support/InstallsAutoRefundAddon.php';

use App\Models\User;
use App\Support\OptionalFeature;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Support\InstallsAutoRefundAddon;
use Tests\TestCase;
use TuraIDC\Plugins\Addons\AutoRefund\Models\AutoRefundApplication;

/**
 * 客户端自助退款接口。
 *
 * 上游一律 Http::fake()，**绝不发真实请求**（夹具里的地址是 .test 域，也不该被解析）。
 * 覆盖计划「范围与验收」里的客户端条目：
 * 未配置时降级、只返回本人服务、hostid 交叉过滤、重复提交被唯一索引挡住、
 * 上游非 200 落 failed、超时落 uncertain 且占住闸门、cancel 成功 / 400 不假装成功、
 * 插件停用后接口 404。
 */
class ClientAutoRefundTest extends TestCase
{
    use DatabaseTransactions;
    use InstallsAutoRefundAddon;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->user = $this->makeRefundUser();
    }

    public function test_bootstrap_reports_missing_upstream_config_and_degrades_bridge_candidates(): void
    {
        $this->installAutoRefundAddon();
        // 夹具默认策略是 `bridge`（要出站），所以没配上游凭据时这台必须被降级拦住
        $this->makeRefundService($this->user, ['host_id' => '9000']);
        $this->wipeAutoRefundConfig();

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v2/client/auto-refund/bootstrap')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.configured', false)
            // 出站通道没配但商品策略在：页面仍然算「开着」，具体机器由 blocked_reasons 说明
            ->assertJsonPath('data.open', true)
            ->assertJsonPath('data.table_ready', true);

        // 未配置时列表接口也不能 500：返回带 upstream_error 的降级结果
        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v2/client/auto-refund/candidates')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertFalse($response->json('data.upstream_available'));
        $this->assertNotEmpty($response->json('data.upstream_error'));

        $item = $response->json('data.items.0');
        $this->assertFalse($item['refundable']);
        $this->assertContains('upstream_unavailable', $item['blocked_reasons']);
    }

    public function test_candidates_only_returns_services_of_the_current_user(): void
    {
        $this->installAutoRefundAddon();

        $mine = $this->makeRefundService($this->user, ['host_id' => '9001']);
        $otherUser = $this->makeRefundUser();
        $others = $this->makeRefundService($otherUser, ['host_id' => '9002']);

        $this->fakeUpstreamQuery(['9001', '9002']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v2/client/auto-refund/candidates')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $serviceIds = array_column($response->json('data.items'), 'service_id');

        $this->assertContains((int) $mine['service']->id, $serviceIds);
        $this->assertNotContains((int) $others['service']->id, $serviceIds);

        $first = $response->json('data.items.0');
        $this->assertSame('香港 CN2', $first['product_name']);
        $this->assertSame('192.0.2.10', $first['dedicatedip']);
    }

    public function test_candidates_block_services_missing_from_upstream_host_list(): void
    {
        $this->installAutoRefundAddon();

        $visible = $this->makeRefundService($this->user, ['host_id' => '9101']);
        $invisible = $this->makeRefundService($this->user, ['host_id' => '9199']);

        $this->fakeUpstreamQuery(['9101']);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v2/client/auto-refund/candidates')
            ->assertOk();

        $byServiceId = [];
        foreach ($response->json('data.items') as $item) {
            $byServiceId[(int) $item['service_id']] = $item;
        }

        $this->assertTrue($byServiceId[(int) $visible['service']->id]['refundable']);
        $this->assertSame([], $byServiceId[(int) $visible['service']->id]['blocked_reasons']);

        $invisibleRow = $byServiceId[(int) $invisible['service']->id];
        $this->assertFalse($invisibleRow['refundable']);
        $this->assertContains('upstream_missing', $invisibleRow['blocked_reasons']);
    }

    public function test_candidates_degrade_instead_of_failing_when_upstream_is_down(): void
    {
        $this->installAutoRefundAddon();
        $this->makeRefundService($this->user, ['host_id' => '9201']);

        Http::fake(function (): never {
            throw new ConnectionException('upstream unreachable');
        });

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v2/client/auto-refund/candidates')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertFalse($response->json('data.upstream_available'));
        $this->assertNotEmpty($response->json('data.upstream_error'));

        $item = $response->json('data.items.0');
        $this->assertFalse($item['refundable']);
        $this->assertContains('upstream_unavailable', $item['blocked_reasons']);
    }

    public function test_submit_lands_pending_review_and_records_upstream_trace_id(): void
    {
        $this->installAutoRefundAddon();
        $fixture = $this->makeRefundService($this->user, ['host_id' => '9301']);

        $this->fakeUpstream([
            'query' => ['code' => 200, 'msg' => 'ok', 'data' => [['hostid' => '9301']]],
            'submit' => ['code' => 200, 'msg' => '申请已创建', 'trace_id' => 'TRACE-OK-1', 'data' => []],
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/auto-refund/applications', [
                'service_id' => (int) $fixture['service']->id,
                'reason' => '不再需要这台机器',
            ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', AutoRefundApplication::STATUS_PENDING_REVIEW)
            ->assertJsonPath('data.trace_id', 'TRACE-OK-1');

        $this->assertSame('9301', $response->json('data.upstream_product_id'));

        $this->assertDatabaseHas('auto_refund_applications', [
            'service_id' => (int) $fixture['service']->id,
            'user_id' => (int) $this->user->id,
            'status' => AutoRefundApplication::STATUS_PENDING_REVIEW,
            'upstream_product_id' => '9301',
            'trace_id' => 'TRACE-OK-1',
            // 闸门占住
            'active_service_id' => (int) $fixture['service']->id,
        ]);

        // 契约硬要求：表单提交（绝不发 JSON body）、显式 action、秒级 timestamp、
        // 且 api_key 只出现在表单里，不能进台账摘要
        Http::assertSent(function (Request $request): bool {
            if (! str_contains($request->url(), 'auto_refund/api.php')) {
                return false;
            }

            $timestamp = (string) ($request['timestamp'] ?? '');

            return $request->isForm()
                && $request['action'] === 'submit'
                && $request['upstream_product_id'] === '9301'
                && ctype_digit($timestamp)
                && abs(time() - (int) $timestamp) <= 60;
        });

        $meta = (string) json_encode(
            AutoRefundApplication::query()->where('service_id', (int) $fixture['service']->id)->value('response_meta'),
            JSON_UNESCAPED_UNICODE,
        );
        $this->assertStringNotContainsString(self::TEST_API_KEY, $meta);
    }

    public function test_second_submit_is_blocked_while_an_application_is_active(): void
    {
        $this->installAutoRefundAddon();
        $fixture = $this->makeRefundService($this->user, ['host_id' => '9302']);

        $this->fakeUpstream([
            'query' => ['code' => 200, 'msg' => 'ok', 'data' => [['hostid' => '9302']]],
            'submit' => ['code' => 200, 'msg' => '申请已创建', 'trace_id' => 'TRACE-OK-2', 'data' => []],
        ]);

        $payload = ['service_id' => (int) $fixture['service']->id, 'reason' => '重复提交测试'];

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/auto-refund/applications', $payload)
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/auto-refund/applications', $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);

        $this->assertSame(
            1,
            AutoRefundApplication::query()->where('service_id', (int) $fixture['service']->id)->count(),
        );
    }

    public function test_unique_index_on_active_service_id_blocks_concurrent_duplicates(): void
    {
        $this->installAutoRefundAddon();
        $fixture = $this->makeRefundService($this->user, ['host_id' => '9303']);

        $make = fn (string $no): AutoRefundApplication => AutoRefundApplication::query()->create([
            'application_no' => $no,
            'user_id' => (int) $this->user->id,
            'service_id' => (int) $fixture['service']->id,
            'upstream_product_id' => '9303',
            'status' => AutoRefundApplication::STATUS_PENDING_REVIEW,
            'active_service_id' => (int) $fixture['service']->id,
            'reason' => '并发闸门测试',
            'estimated_amount' => '10.00',
        ]);

        $make('ARTEST0001');

        $this->assertThrows(
            fn () => $make('ARTEST0002'),
            UniqueConstraintViolationException::class,
        );

        // 收口后闸门置 NULL，同一服务可以再申请
        AutoRefundApplication::query()->where('application_no', 'ARTEST0001')->update([
            'status' => AutoRefundApplication::STATUS_CLOSED,
            'active_service_id' => null,
        ]);

        $this->assertSame('ARTEST0002', $make('ARTEST0002')->application_no);
    }

    public function test_upstream_business_error_lands_failed_and_releases_the_gate(): void
    {
        $this->installAutoRefundAddon();
        $fixture = $this->makeRefundService($this->user, ['host_id' => '9304']);

        $this->fakeUpstream([
            'query' => ['code' => 200, 'msg' => 'ok', 'data' => [['hostid' => '9304']]],
            'submit' => ['code' => 400, 'msg' => '该产品未配置退款规则'],
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/auto-refund/applications', [
                'service_id' => (int) $fixture['service']->id,
                'reason' => '业务拒绝测试',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200)
            ->assertJsonPath('message', '该产品未配置退款规则');

        $this->assertDatabaseHas('auto_refund_applications', [
            'service_id' => (int) $fixture['service']->id,
            'status' => AutoRefundApplication::STATUS_FAILED,
            'upstream_code' => 400,
            'upstream_msg' => '该产品未配置退款规则',
            'active_service_id' => null,
        ]);
    }

    public function test_network_timeout_lands_uncertain_and_keeps_the_gate(): void
    {
        $this->installAutoRefundAddon();
        $fixture = $this->makeRefundService($this->user, ['host_id' => '9305']);

        Http::fake(function (Request $request) {
            if (($request['action'] ?? '') === 'query') {
                return Http::response(['code' => 200, 'msg' => 'ok', 'data' => [['hostid' => '9305']]], 200);
            }

            throw new ConnectionException('Operation timed out');
        });

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/auto-refund/applications', [
                'service_id' => (int) $fixture['service']->id,
                'reason' => '超时测试',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);

        $this->assertDatabaseHas('auto_refund_applications', [
            'service_id' => (int) $fixture['service']->id,
            'status' => AutoRefundApplication::STATUS_UNCERTAIN,
            // 结果未知必须继续占住幂等闸门，否则重提会造出第二笔上游申请
            'active_service_id' => (int) $fixture['service']->id,
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/auto-refund/applications', [
                'service_id' => (int) $fixture['service']->id,
                'reason' => '超时后重提',
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);
    }

    public function test_cancel_marks_application_cancelled_and_releases_the_gate(): void
    {
        $this->installAutoRefundAddon();
        $fixture = $this->makeRefundService($this->user, ['host_id' => '9306']);

        $this->fakeUpstream([
            'query' => ['code' => 200, 'msg' => 'ok', 'data' => [['hostid' => '9306']]],
            'submit' => ['code' => 200, 'msg' => '申请已创建', 'trace_id' => 'TRACE-OK-6', 'data' => []],
            'cancel' => ['code' => 200, 'msg' => '已取消', 'trace_id' => 'TRACE-CANCEL-6'],
        ]);

        $applicationId = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/auto-refund/applications', [
                'service_id' => (int) $fixture['service']->id,
                'reason' => '取消测试',
            ])
            ->assertOk()
            ->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/auto-refund/applications/'.$applicationId.'/cancel')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', AutoRefundApplication::STATUS_CANCELLED);

        $application = AutoRefundApplication::query()->findOrFail($applicationId);
        $this->assertSame(AutoRefundApplication::STATUS_CANCELLED, (string) $application->status);
        $this->assertNotNull($application->cancelled_at);
        $this->assertNull($application->active_service_id);
    }

    public function test_cancel_business_error_does_not_pretend_success(): void
    {
        $this->installAutoRefundAddon();
        $fixture = $this->makeRefundService($this->user, ['host_id' => '9307']);

        $this->fakeUpstream([
            'query' => ['code' => 200, 'msg' => 'ok', 'data' => [['hostid' => '9307']]],
            'submit' => ['code' => 200, 'msg' => '申请已创建', 'trace_id' => 'TRACE-OK-7', 'data' => []],
            'cancel' => ['code' => 400, 'msg' => '没有可取消的申请'],
        ]);

        $applicationId = $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/auto-refund/applications', [
                'service_id' => (int) $fixture['service']->id,
                'reason' => '取消失败测试',
            ])
            ->assertOk()
            ->json('data.id');

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/auto-refund/applications/'.$applicationId.'/cancel')
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);

        $this->assertDatabaseHas('auto_refund_applications', [
            'id' => (int) $applicationId,
            'status' => AutoRefundApplication::STATUS_PENDING_REVIEW,
            'upstream_code' => 400,
            'active_service_id' => (int) $fixture['service']->id,
        ]);
    }

    public function test_client_cannot_cancel_another_users_application(): void
    {
        $this->installAutoRefundAddon();

        $otherUser = $this->makeRefundUser();
        $fixture = $this->makeRefundService($otherUser, ['host_id' => '9308']);
        $application = AutoRefundApplication::query()->create([
            'application_no' => 'AROTHER001',
            'user_id' => (int) $otherUser->id,
            'service_id' => (int) $fixture['service']->id,
            'upstream_product_id' => '9308',
            'status' => AutoRefundApplication::STATUS_PENDING_REVIEW,
            'active_service_id' => (int) $fixture['service']->id,
            'reason' => '别人的申请',
            'estimated_amount' => '10.00',
        ]);

        $this->actingAs($this->user, 'sanctum')
            ->postJson('/api/v2/client/auto-refund/applications/'.$application->id.'/cancel')
            ->assertStatus(404);

        $this->assertDatabaseHas('auto_refund_applications', [
            'id' => (int) $application->id,
            'status' => AutoRefundApplication::STATUS_PENDING_REVIEW,
        ]);
    }

    public function test_my_applications_list_is_scoped_to_current_user(): void
    {
        $this->installAutoRefundAddon();

        $fixture = $this->makeRefundService($this->user, ['host_id' => '9309']);
        AutoRefundApplication::query()->create([
            'application_no' => 'ARMINE001',
            'user_id' => (int) $this->user->id,
            'service_id' => (int) $fixture['service']->id,
            'upstream_product_id' => '9309',
            'status' => AutoRefundApplication::STATUS_PENDING_REVIEW,
            'active_service_id' => (int) $fixture['service']->id,
            'reason' => '我的申请',
            'estimated_amount' => '10.00',
        ]);

        $otherUser = $this->makeRefundUser();
        $otherFixture = $this->makeRefundService($otherUser, ['host_id' => '9310']);
        AutoRefundApplication::query()->create([
            'application_no' => 'AROTHER002',
            'user_id' => (int) $otherUser->id,
            'service_id' => (int) $otherFixture['service']->id,
            'upstream_product_id' => '9310',
            'status' => AutoRefundApplication::STATUS_PENDING_REVIEW,
            'active_service_id' => (int) $otherFixture['service']->id,
            'reason' => '别人的申请',
            'estimated_amount' => '10.00',
        ]);

        $response = $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v2/client/auto-refund/applications')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1);

        $this->assertSame('ARMINE001', $response->json('data.list.0.application_no'));
        $this->assertSame(AutoRefundApplication::STATUS_PENDING_REVIEW, $response->json('data.list.0.status'));
    }

    public function test_endpoints_disappear_when_the_plugin_is_disabled(): void
    {
        // 插件装了但没启用：Provider 不会注册路由，接口必须 404（而不是 500）
        $this->installAutoRefundAddon(enable: false);

        $this->assertFalse(OptionalFeature::enabled(OptionalFeature::AUTO_REFUND));

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v2/client/auto-refund/bootstrap')
            ->assertStatus(404);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v2/admin/auto-refund/applications')
            ->assertStatus(404);

        $this->actingAs($this->user, 'sanctum')
            ->getJson('/api/v2/admin/auto-refund/product-rules')
            ->assertStatus(404);
    }

    /**
     * 固定「query 返回指定 hostid 列表」的上游行为。
     *
     * @param  array<int, string>  $hostIds
     */
    private function fakeUpstreamQuery(array $hostIds): void
    {
        $data = array_map(static fn (string $id): array => ['hostid' => $id], $hostIds);

        Http::fake(function (Request $request) use ($data) {
            $action = (string) ($request['action'] ?? '');

            if ($action === 'query') {
                return Http::response(['code' => 200, 'msg' => 'ok', 'data' => $data], 200, [
                    'Date' => gmdate('D, d M Y H:i:s').' GMT',
                ]);
            }

            return Http::response(['code' => 400, 'msg' => 'unexpected action'], 200);
        });
    }

    /**
     * 按 action 定制上游响应。
     *
     * @param  array<string, array<string, mixed>>  $responses
     */
    private function fakeUpstream(array $responses): void
    {
        Http::fake(function (Request $request) use ($responses) {
            $action = (string) ($request['action'] ?? '');
            $body = $responses[$action] ?? ['code' => 405, 'msg' => 'method not allowed'];

            return Http::response($body, 200);
        });
    }
}
