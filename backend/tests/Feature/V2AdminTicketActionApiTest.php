<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\IntegrationPlugin;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierPluginBinding;
use App\Models\Ticket;
use App\Models\TicketDeliveryRule;
use App\Models\TicketReply;
use App\Models\TicketUpstreamDeliveryLog;
use App\Models\User;
use App\Services\Ticket\TicketDeliveryService;
use App\Services\Ticket\TicketService;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Concerns\UpstreamDeliveryWhitelist;
use Tests\TestCase;

class V2AdminTicketActionApiTest extends TestCase
{
    use DatabaseTransactions;
    use UpstreamDeliveryWhitelist;

    protected function tearDown(): void
    {
        // 恢复 ticket_upstream 上传防护配置。恢复放在 tearDown 中保证任一断言失败后
        // 也会执行，避免残留配置污染同一进程内其他访问 /upload_image 的用例。
        Setting::setValues('ticket_upstream', [
            'upload_image_enabled' => config('ticket_upstream.upload_image_enabled', false) ? '1' : '0',
            'allowed_ips' => (string) config('ticket_upstream.upload_allowed_ips', ''),
            'rate_limit' => (string) config('ticket_upstream.upload_rate_limit', 30),
            'block_non_whitelisted' => config('ticket_upstream.upload_block_non_whitelisted', true) ? '1' : '0',
        ]);

        parent::tearDown();
    }

    public function test_ticket_upstream_delivery_status_and_logs_require_list_permission(): void
    {
        $ticket = $this->createTicket();

        $this->getJson('/api/v2/admin/tickets/'.$ticket->id.'/upstream-delivery')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_LIST]));

        $status = $this->getJson('/api/v2/admin/tickets/'.$ticket->id.'/upstream-delivery')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', 'not_configured')
            ->assertJsonPath('data.status_label', '未配置')
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.last_error', null);

        $this->assertSame($this->upstreamDeliveryWhitelist(), array_keys($status->json('data')));

        $this->getJson('/api/v2/admin/tickets/'.$ticket->id.'/upstream-delivery/logs')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.status', 'skipped')
            ->assertJsonPath('data.list.0.reason_code', 'service_missing')
            ->assertJsonStructure(['data' => ['list', 'total', 'page', 'page_size']]);
    }

    public function test_ticket_callback_registration_requires_manage_permission_and_binding(): void
    {
        $ticket = $this->createTicket();

        $this->postJson('/api/v2/admin/tickets/'.$ticket->id.'/upstream-delivery/callback-registration')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_LIST]));

        $this->postJson('/api/v2/admin/tickets/'.$ticket->id.'/upstream-delivery/callback-registration')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_MANAGE]));

        $this->postJson('/api/v2/admin/tickets/'.$ticket->id.'/upstream-delivery/callback-registration')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200);
    }

    public function test_ticket_upstream_delivery_logs_are_whitelisted_and_paginated(): void
    {
        $ticket = $this->createTicket();
        TicketUpstreamDeliveryLog::query()->create([
            'ticket_id' => $ticket->id,
            'operation' => 'ticket.create',
            'event' => 'failed',
            'status' => 'failed',
            'reason_code' => 'upstream_rejected',
            'provider_key' => 'zjmf_finance_api',
            'supplier_id' => 93,
            'attempt' => 2,
            'message' => '上游工单创建失败',
            'occurred_at' => now(),
        ]);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_LIST]));

        $response = $this->getJson('/api/v2/admin/tickets/'.$ticket->id.'/upstream-delivery/logs?page=1&page_size=10')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.status', 'failed')
            ->assertJsonPath('data.list.0.status_label', '转发失败')
            ->assertJsonPath('data.list.0.message', '上游工单创建失败')
            ->assertJsonMissingPath('data.list.0.raw_response');

        $this->assertSame([
            'id',
            'ticket_id',
            'ticket_reply_id',
            'direction',
            'operation',
            'event',
            'status',
            'status_label',
            'reason_code',
            'provider_key',
            'supplier_id',
            'supplier_name',
            'attempt',
            'http_status',
            'duration_ms',
            'message',
            'occurred_at',
        ], array_keys($response->json('data.list.0')));
    }

    public function test_ticket_delivery_departments_require_manage_permission(): void
    {
        $this->getJson('/api/v2/admin/ticket-delivery-departments')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_LIST]));

        $this->getJson('/api/v2/admin/ticket-delivery-departments?supplier_id=1')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);
    }

    public function test_ticket_delivery_departments_returns_whitelisted_data(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_MANAGE, AdminPermissions::TICKET_DELIVERY_MANAGE]));
        $delivery = $this->createMock(TicketDeliveryService::class);
        $delivery->expects($this->once())
            ->method('upstreamDepartments')
            ->with(7)
            ->willReturn([
                ['id' => 'tech-01', 'name' => '技术支持', 'description' => '技术部门'],
            ]);
        app()->instance(TicketDeliveryService::class, $delivery);

        $response = $this->getJson('/api/v2/admin/ticket-delivery-departments?supplier_id=7')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', 'tech-01')
            ->assertJsonPath('data.list.0.name', '技术支持')
            ->assertJsonMissingPath('data.list.0.jwt');

        $this->assertSame(['list'], array_keys($response->json('data')));
    }

    public function test_ticket_delivery_departments_require_supplier_id(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_MANAGE, AdminPermissions::TICKET_DELIVERY_MANAGE]));

        $this->getJson('/api/v2/admin/ticket-delivery-departments')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['supplier_id']]]);
    }

    public function test_ticket_actions_require_login_and_manage_permission(): void
    {
        $ticket = $this->createTicket();

        $this->postJson('/api/v2/admin/tickets/'.$ticket->id.'/closures')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_LIST]));

        $this->postJson('/api/v2/admin/tickets/'.$ticket->id.'/closures')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);
    }

    public function test_ticket_assignment_action_validates_payload_and_returns_compact_result(): void
    {
        $ticket = $this->createTicket();
        $assignee = $this->createAdmin([AdminPermissions::TICKET_REPLY]);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_MANAGE]));

        $this->putJson('/api/v2/admin/tickets/'.$ticket->id.'/assignment', ['per_page' => 20])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['assignee_id', 'per_page']]]);

        $response = $this->putJson('/api/v2/admin/tickets/'.$ticket->id.'/assignment', [
            'assignee_id' => $assignee->id,
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $ticket->id)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.ticket.assignee_id', $assignee->id)
            ->assertJsonPath('data.detail.assignee.id', $assignee->id);

        $this->assertSame($this->actionResultWhitelist(), array_keys($response->json('data')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
        $this->assertSame((int) $assignee->id, (int) $ticket->refresh()->assignee_id);
    }

    public function test_ticket_close_action_returns_small_projection(): void
    {
        $ticket = $this->createTicket(['status' => TicketService::STATUS_CLIENT_REPLY]);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_MANAGE]));

        $response = $this->postJson('/api/v2/admin/tickets/'.$ticket->id.'/closures')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $ticket->id)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.ticket.status', TicketService::STATUS_CLOSED)
            ->assertJsonPath('data.detail.ticket.close_reason', TicketService::CLOSE_REASON_ADMIN);

        $this->assertSame($this->actionResultWhitelist(), array_keys($response->json('data')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
        $this->assertSame(TicketService::STATUS_CLOSED, (int) $ticket->refresh()->status);
    }

    public function test_ticket_reply_recall_action_uses_reply_permission_and_owner_rule(): void
    {
        $staff = $this->createAdmin([AdminPermissions::TICKET_REPLY]);
        $ticket = $this->createTicket();
        $reply = TicketReply::query()->create([
            'ticket_id' => (int) $ticket->id,
            'user_id' => (int) $staff->id,
            'content' => 'staff reply',
            'is_staff' => 1,
            'attachments' => [],
            'created_at' => now(),
        ]);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_MANAGE]));

        $this->postJson('/api/v2/admin/tickets/'.$ticket->id.'/replies/'.$reply->id.'/recalls')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200);

        Sanctum::actingAs($staff);

        $this->postJson('/api/v2/admin/tickets/'.$ticket->id.'/replies/'.$reply->id.'/recalls', ['per_page' => 20])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $response = $this->postJson('/api/v2/admin/tickets/'.$ticket->id.'/replies/'.$reply->id.'/recalls')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $reply->id)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.ticket_id', $ticket->id)
            ->assertJsonPath('data.detail.reply.recalled', true);

        $this->assertSame($this->actionResultWhitelist(), array_keys($response->json('data')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
        $this->assertNotNull($reply->refresh()->recalled_at);
        $this->assertSame('', (string) $reply->content);
    }

    public function test_ticket_delivery_rules_require_enabled_upload_image_endpoint(): void
    {
        [, $supplier, $supplierBinding] = $this->createZjmfSupplierBinding('ticket-rule');

        Setting::setValues('ticket_upstream', [
            'upload_image_enabled' => '0',
        ]);
        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_MANAGE, AdminPermissions::TICKET_DELIVERY_MANAGE]));
        $ruleName = '接口关闭时不可配置 '.bin2hex(random_bytes(4));
        $payload = [
            'name' => $ruleName,
            'department' => 'support',
            'supplier_id' => $supplier->id,
            'provider_key' => 'zjmf_finance_api',
            'product_scope_mode' => 'all',
            'product_ids' => [],
            'upstream_department_id' => 'support-01',
            'enabled' => true,
            'sync_admin_replies' => false,
        ];

        $this->postJson('/api/v2/admin/ticket-delivery-rules', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonPath('data.errors.upload_image_enabled.0', '请先启用 /upload_image 接口');
        $this->assertDatabaseMissing('ticket_delivery_rules', ['name' => $payload['name']]);

        Setting::setValues('ticket_upstream', ['upload_image_enabled' => '1']);
        $supplierBinding->update(['status' => 0]);
        $this->postJson('/api/v2/admin/ticket-delivery-rules', array_merge($payload, [
            'name' => $ruleName.' disabled-binding',
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('data.errors.supplier_id.0', '供应商必须启用并配置启用的 ZJMF 财务接口绑定');
        $supplierBinding->update(['status' => 1]);
        $created = $this->postJson('/api/v2/admin/ticket-delivery-rules', $payload)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.provider_key', 'zjmf_finance_api')
            ->assertJsonPath('data.product_scope_mode', 'all');
        $ruleId = (int) $created->json('data.id');

        Setting::setValues('ticket_upstream', ['upload_image_enabled' => '0']);
        $this->putJson('/api/v2/admin/ticket-delivery-rules/'.$ruleId, array_merge($payload, [
            'name' => '接口关闭时不可更新',
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('data.errors.upload_image_enabled.0', '请先启用 /upload_image 接口');
        $this->assertSame($ruleName, (string) TicketDeliveryRule::query()->findOrFail($ruleId)->name);
    }

    public function test_ticket_upload_guard_config_requires_manage_permission_and_roundtrips(): void
    {
        DB::table('settings')
            ->where('group_key', 'ticket_upstream')
            ->whereIn('item_key', ['upload_image_enabled', 'block_non_whitelisted'])
            ->delete();
        Setting::forgetCachedGroup('ticket_upstream');

        $this->getJson('/api/v2/admin/ticket-delivery-upload-guard')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([]));

        $this->getJson('/api/v2/admin/ticket-delivery-upload-guard')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);
        $this->postJson('/api/v2/admin/ticket-delivery-upload-guard', [
            'allowed_ips' => '203.0.113.10',
            'rate_limit' => 5,
        ])->assertForbidden()
            ->assertJsonPath('code', 40300);

        // 权限隔离：仅持 ticket.manage（未显式授予 delivery_manage）不能访问上传防护配置。
        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_MANAGE]));
        $this->getJson('/api/v2/admin/ticket-delivery-upload-guard')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_MANAGE, AdminPermissions::TICKET_DELIVERY_MANAGE]));

        $default = $this->getJson('/api/v2/admin/ticket-delivery-upload-guard')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertSame(false, $default->json('data.upload_image_enabled'));
        $this->assertSame(true, $default->json('data.block_non_whitelisted'));
        $this->assertArrayHasKey('upload_image_enabled', $default->json('data'));
        $this->assertArrayHasKey('allowed_ips', $default->json('data'));
        $this->assertArrayHasKey('rate_limit', $default->json('data'));
        $this->assertArrayHasKey('block_non_whitelisted', $default->json('data'));

        $this->postJson('/api/v2/admin/ticket-delivery-upload-guard', [
            'upload_image_enabled' => true,
            'allowed_ips' => "203.0.113.10\n198.51.100.0/24",
            'rate_limit' => 5,
            'block_non_whitelisted' => true,
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.upload_image_enabled', true)
            ->assertJsonPath('data.allowed_ips', "203.0.113.10\n198.51.100.0/24")
            ->assertJsonPath('data.rate_limit', 5)
            ->assertJsonPath('data.block_non_whitelisted', true);

        $this->getJson('/api/v2/admin/ticket-delivery-upload-guard')
            ->assertOk()
            ->assertJsonPath('data.upload_image_enabled', true)
            ->assertJsonPath('data.allowed_ips', "203.0.113.10\n198.51.100.0/24")
            ->assertJsonPath('data.rate_limit', 5)
            ->assertJsonPath('data.block_non_whitelisted', true);

        // 部分更新缺少开关字段时，必须保留已保存的拦截状态。
        $this->postJson('/api/v2/admin/ticket-delivery-upload-guard', [
            'allowed_ips' => '198.51.100.0/24',
            'rate_limit' => 10,
        ])->assertOk()
            ->assertJsonPath('data.upload_image_enabled', true)
            ->assertJsonPath('data.block_non_whitelisted', true);

        // 非法 IP / CIDR 被拒绝
        $this->postJson('/api/v2/admin/ticket-delivery-upload-guard', [
            'allowed_ips' => 'not-an-ip',
            'rate_limit' => 5,
        ])->assertUnprocessable()
            ->assertJsonStructure(['data' => ['errors' => ['allowed_ips']]]);

        $this->postJson('/api/v2/admin/ticket-delivery-upload-guard', [
            'allowed_ips' => '203.0.113.10/99',
            'rate_limit' => 5,
        ])->assertUnprocessable()
            ->assertJsonStructure(['data' => ['errors' => ['allowed_ips']]]);
    }

    public function test_upload_image_cannot_be_disabled_while_delivery_rules_exist(): void
    {
        // 测试库可能残留历史规则数据，事务内清空保证断言可复现（回滚后不影响共享库其他测试）。
        DB::table('ticket_delivery_rules')->delete();

        [, $supplier] = $this->createZjmfSupplierBinding('guard-rule');

        Setting::setValues('ticket_upstream', [
            'upload_image_enabled' => '1',
            'block_non_whitelisted' => '1',
        ]);
        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_MANAGE, AdminPermissions::TICKET_DELIVERY_MANAGE]));

        $rule = TicketDeliveryRule::query()->create([
            'name' => '上传开关保护规则 '.bin2hex(random_bytes(4)),
            'supplier_id' => (int) $supplier->id,
            'provider_key' => 'zjmf_finance_api',
            'department' => 'support',
            'upstream_department_id' => 'support-01',
            'product_scope_mode' => 'all',
            'enabled' => true,
            'sync_admin_replies' => true,
        ]);

        // 存在传递规则时，关闭 /upload_image 接口必须被拒绝，且配置不被改写。
        $this->postJson('/api/v2/admin/ticket-delivery-upload-guard', [
            'upload_image_enabled' => false,
            'allowed_ips' => '203.0.113.10',
            'rate_limit' => 5,
            'block_non_whitelisted' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonPath('data.errors.upload_image_enabled.0', '存在工单传递规则时不能关闭 /upload_image 接口');
        $this->assertSame('1', (string) Setting::getValue('ticket_upstream', 'upload_image_enabled', '0'));

        // 部分更新（不带 upload_image_enabled 字段）只改白名单/限流，不应被误拦。
        $this->postJson('/api/v2/admin/ticket-delivery-upload-guard', [
            'allowed_ips' => '198.51.100.0/24',
            'rate_limit' => 10,
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.upload_image_enabled', true);

        // 构造「有规则 + 已保存关闭」的遗留状态（修复上线前可先建规则再关接口产生），
        // 此时部分更新只改白名单/限流仍应放行，不得因回退值为 false 误拦。
        Setting::setValues('ticket_upstream', ['upload_image_enabled' => '0']);
        $this->postJson('/api/v2/admin/ticket-delivery-upload-guard', [
            'allowed_ips' => '203.0.113.10',
            'rate_limit' => 5,
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.upload_image_enabled', false);

        // 规则全部删除后，允许正常关闭并写入配置。
        $rule->delete();
        $this->assertFalse(TicketDeliveryRule::query()->exists());
        $this->postJson('/api/v2/admin/ticket-delivery-upload-guard', [
            'upload_image_enabled' => false,
            'allowed_ips' => '203.0.113.10',
            'rate_limit' => 5,
            'block_non_whitelisted' => true,
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.upload_image_enabled', false);
        $this->assertSame('0', (string) Setting::getValue('ticket_upstream', 'upload_image_enabled', '1'));
    }

    public function test_upload_guard_disable_is_serialized_by_shared_mutation_lock(): void
    {
        Setting::setValues('ticket_upstream', [
            'upload_image_enabled' => '1',
            'block_non_whitelisted' => '1',
        ]);
        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_MANAGE, AdminPermissions::TICKET_DELIVERY_MANAGE]));

        // 模拟另一请求正持有「上传开关与规则变更」的串行化锁：关闭请求必须快速失败，
        // 避免绕过规则存在性检查产生「保留规则但接口已关闭」的无效终态。
        // 键与 TicketDeliveryController::UPLOAD_GUARD_MUTATION_LOCK 保持一致。
        $lock = Cache::lock('ticket-upstream:upload-guard-mutation', 10);
        $this->assertTrue($lock->get());
        try {
            $this->postJson('/api/v2/admin/ticket-delivery-upload-guard', [
                'upload_image_enabled' => false,
                'rate_limit' => 5,
            ])
                ->assertUnprocessable()
                ->assertJsonPath('code', 42200)
                ->assertJsonPath('data.errors.upload_image_enabled.0', '上传防护配置正在变更，请稍后重试');
        } finally {
            $lock->release();
        }
    }

    /**
     * @return list<string>
     */
    private function actionResultWhitelist(): array
    {
        return [
            'id',
            'status',
            'message',
            'detail',
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTicket(array $overrides = []): Ticket
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'v2-ticket-action-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'V2 Ticket Action '.$suffix,
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
        ]);

        return Ticket::query()->create(array_replace([
            'user_id' => (int) $user->id,
            'department' => 'support',
            'subject' => 'V2 ticket action '.$suffix,
            'priority' => 2,
            'status' => TicketService::STATUS_CLIENT_REPLY,
            'service_id' => null,
        ], $overrides));
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-ticket-action-'.$suffix,
            'label' => 'V2 Ticket Action',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-ticket-action-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Ticket Action',
            'email' => 'v2-ticket-action-admin-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    /**
     * 创建 ZJMF 财务供应商绑定三元组（插件、供应商、绑定），供工单传递规则相关用例复用。
     *
     * @return array{0: IntegrationPlugin, 1: Supplier, 2: SupplierPluginBinding}
     */
    private function createZjmfSupplierBinding(string $prefix): array
    {
        $suffix = bin2hex(random_bytes(4));
        $plugin = IntegrationPlugin::query()->create([
            'domain' => 'servers',
            'slug' => $prefix.'-'.$suffix,
            'plugin_key' => $prefix.'-'.$suffix,
            'name' => $prefix.' 测试插件 '.$suffix,
            'version' => '1.0.0',
            'entry_class' => 'Tests\\FakePlugin',
            'capabilities_json' => [],
            'config_schema_json' => [],
            'status' => 1,
            'installed_at' => now(),
        ]);
        $supplier = Supplier::query()->create([
            'name' => $prefix.' 测试供应商 '.$suffix,
            'code' => $prefix.'-'.$suffix,
            'status' => 1,
        ]);
        $binding = SupplierPluginBinding::query()->create([
            'supplier_id' => (int) $supplier->id,
            'plugin_id' => (int) $plugin->id,
            'provider_key' => 'zjmf_finance_api',
            'environment' => 'production',
            'status' => 1,
            'priority' => 1,
            'config_json' => [],
            'has_secret_json' => [],
        ]);

        return [$plugin, $supplier, $binding];
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                foreach (['password', 'secret', 'api_key', 'raw_response', 'third_party_response'] as $needle) {
                    $this->assertStringNotContainsString($needle, strtolower($key));
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
