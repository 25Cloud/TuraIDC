<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/../Support/InstallsAutoRefundAddon.php';

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Support\InstallsAutoRefundAddon;
use Tests\TestCase;
use TuraIDC\Plugins\Addons\AutoRefund\Models\AutoRefundApplication;

/**
 * 管理端自助退款台账：结算走既有账务链路、超上限被拒、越权被拒、驳回/作废、上游连通性。
 */
class AdminAutoRefundTest extends TestCase
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

    public function test_settle_uses_the_existing_refund_chain_and_records_settled_amount(): void
    {
        $fixture = $this->makeRefundService($this->user, ['amount' => '100.00']);
        $application = $this->makePendingApplication($fixture['service']->id, '100.00');

        Sanctum::actingAs($this->makeAdmin([
            AdminPermissions::INVOICE_LIST,
            AdminPermissions::INVOICE_MANAGE,
        ]));

        $this->postJson('/api/v2/admin/auto-refund/applications/'.$application->id.'/settle', [
            'amount' => '88.50',
            'remark' => '按管理员核算金额结算',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', AutoRefundApplication::STATUS_SETTLED)
            ->assertJsonPath('data.settled_amount', '88.50');

        $fresh = AutoRefundApplication::query()->findOrFail($application->id);
        $this->assertSame(AutoRefundApplication::STATUS_SETTLED, (string) $fresh->status);
        $this->assertSame('88.50', (string) $fresh->settled_amount);
        $this->assertNotNull($fresh->settled_at);
        $this->assertNotNull($fresh->operator_id);
        $this->assertNull($fresh->active_service_id);

        // 钱走的是既有链路：账单出现退款单，用户余额增加
        $this->assertSame(
            1,
            (int) DB::table('refunds')->where('invoice_id', (int) $fixture['invoice']->id)->count(),
        );
        $this->assertSame(
            '88.50',
            number_format((float) DB::table('user_accounts')->where('user_id', (int) $this->user->id)->value('cash_balance'), 2, '.', ''),
        );
        $this->assertSame('88.50', number_format((float) $this->user->fresh()->balance, 2, '.', ''));
    }

    public function test_settle_above_existing_refundable_limit_is_rejected(): void
    {
        $fixture = $this->makeRefundService($this->user, ['amount' => '100.00']);
        $application = $this->makePendingApplication($fixture['service']->id, '100.00');

        Sanctum::actingAs($this->makeAdmin([
            AdminPermissions::INVOICE_LIST,
            AdminPermissions::INVOICE_MANAGE,
        ]));

        $this->postJson('/api/v2/admin/auto-refund/applications/'.$application->id.'/settle', [
            'amount' => '150.00',
            'remark' => '超额结算测试',
        ])
            ->assertStatus(422)
            ->assertJsonPath('code', 42200);

        // 账务失败 → 状态留在待审核，闸门不动
        $this->assertDatabaseHas('auto_refund_applications', [
            'id' => (int) $application->id,
            'status' => AutoRefundApplication::STATUS_PENDING_REVIEW,
            'settled_amount' => null,
            'active_service_id' => (int) $fixture['service']->id,
        ]);
    }

    public function test_admin_without_permission_cannot_list_or_settle(): void
    {
        $fixture = $this->makeRefundService($this->user);
        $application = $this->makePendingApplication($fixture['service']->id, '100.00');

        Sanctum::actingAs($this->makeAdmin([AdminPermissions::DASHBOARD_VIEW]));

        $this->getJson('/api/v2/admin/auto-refund/applications')
            ->assertStatus(403)
            ->assertJsonPath('code', 40300);

        $this->postJson('/api/v2/admin/auto-refund/applications/'.$application->id.'/settle', [
            'amount' => '10.00',
        ])->assertStatus(403);

        $this->assertDatabaseHas('auto_refund_applications', [
            'id' => (int) $application->id,
            'status' => AutoRefundApplication::STATUS_PENDING_REVIEW,
        ]);
    }

    public function test_reject_and_close_release_the_gate(): void
    {
        $fixture = $this->makeRefundService($this->user);
        $pending = $this->makePendingApplication($fixture['service']->id, '10.00');

        Sanctum::actingAs($this->makeAdmin([
            AdminPermissions::INVOICE_LIST,
            AdminPermissions::INVOICE_MANAGE,
        ]));

        $this->postJson('/api/v2/admin/auto-refund/applications/'.$pending->id.'/reject', [
            'remark' => '上游已驳回',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', AutoRefundApplication::STATUS_REJECTED);

        $this->assertDatabaseHas('auto_refund_applications', [
            'id' => (int) $pending->id,
            'active_service_id' => null,
        ]);

        $secondService = $this->makeRefundService($this->user);
        $uncertain = AutoRefundApplication::query()->create([
            'application_no' => 'ARUNCERT01',
            'user_id' => (int) $this->user->id,
            'service_id' => (int) $secondService['service']->id,
            'upstream_product_id' => '9999',
            'status' => AutoRefundApplication::STATUS_UNCERTAIN,
            'active_service_id' => (int) $secondService['service']->id,
            'reason' => '超时留下的结果未知',
            'estimated_amount' => '10.00',
        ]);

        $this->postJson('/api/v2/admin/auto-refund/applications/'.$uncertain->id.'/close', [
            'remark' => '上游核实未建单，作废',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', AutoRefundApplication::STATUS_CLOSED);

        $this->assertDatabaseHas('auto_refund_applications', [
            'id' => (int) $uncertain->id,
            'active_service_id' => null,
        ]);
    }

    public function test_upstream_connectivity_test_reports_host_count_and_never_leaks_api_key(): void
    {
        Http::fake(function () {
            return Http::response(
                ['code' => 200, 'msg' => 'ok', 'trace_id' => 'ADMIN-TRACE', 'data' => [['hostid' => '1'], ['hostid' => '2']]],
                200,
                ['Date' => gmdate('D, d M Y H:i:s').' GMT'],
            );
        });

        Sanctum::actingAs($this->makeAdmin([AdminPermissions::INTEGRATION_PLUGIN_TEST]));

        $response = $this->postJson('/api/v2/admin/auto-refund/upstream/test')
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.host_count', 2);

        $this->assertIsInt($response->json('data.skew_seconds'));
        $this->assertStringNotContainsString(self::TEST_API_KEY, (string) $response->getContent());
    }

    public function test_admin_list_filters_by_status(): void
    {
        $fixture = $this->makeRefundService($this->user);
        $pending = $this->makePendingApplication($fixture['service']->id, '10.00');

        Sanctum::actingAs($this->makeAdmin([AdminPermissions::INVOICE_LIST]));

        $response = $this->getJson('/api/v2/admin/auto-refund/applications?status=pending_review')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        $this->assertSame((int) $pending->id, $response->json('data.list.0.id'));
        $this->assertSame($this->user->email, $response->json('data.list.0.user_email'));

        // 第二阶段新增字段：类型/通道的中文标签、工单号、策略快照都要出现在台账里
        $this->assertSame('插件间对接', $response->json('data.list.0.refund_type_label'));
        $this->assertSame('上游接口', $response->json('data.list.0.channel_label'));
        $this->assertNull($response->json('data.list.0.ticket_id'));
        $this->assertNull($response->json('data.list.0.upstream_ticket_id'));
        $this->assertIsArray($response->json('data.list.0.policy_snapshot'));
    }

    /**
     * 造一条「上游已受理、等待审核」的台账行（不必真的调上游）。
     */
    private function makePendingApplication(int $serviceId, string $estimatedAmount): AutoRefundApplication
    {
        return AutoRefundApplication::query()->create([
            'application_no' => 'AR'.strtoupper(bin2hex(random_bytes(4))),
            'user_id' => (int) $this->user->id,
            'service_id' => $serviceId,
            'upstream_product_id' => '9100',
            'status' => AutoRefundApplication::STATUS_PENDING_REVIEW,
            'refund_type' => 'bridge',
            'channel' => AutoRefundApplication::CHANNEL_BRIDGE,
            'active_service_id' => $serviceId,
            'reason' => '管理端测试',
            'estimated_amount' => $estimatedAmount,
            'submitted_at' => now(),
            'trace_id' => 'TRACE-PENDING',
        ]);
    }

    /**
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
            'email' => 'auto-refund-admin-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }
}
