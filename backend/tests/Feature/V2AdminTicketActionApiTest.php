<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\Ticket\TicketDeliveryService;
use App\Services\Ticket\TicketService;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Concerns\UpstreamDeliveryWhitelist;
use Tests\TestCase;

class V2AdminTicketActionApiTest extends TestCase
{
    use UpstreamDeliveryWhitelist;

    protected function tearDown(): void
    {
        // 恢复 ticket_upstream 上传防护配置。恢复放在 tearDown 中保证任一断言失败后
        // 也会执行，避免残留配置污染同一进程内其他访问 /upload_image 的用例。
        Setting::setValues('ticket_upstream', [
            'allowed_ips' => (string) config('ticket_upstream.upload_allowed_ips', ''),
            'rate_limit' => (string) config('ticket_upstream.upload_rate_limit', 30),
            'block_non_whitelisted' => config('ticket_upstream.upload_block_non_whitelisted', false) ? '1' : '0',
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
        \App\Models\TicketUpstreamDeliveryLog::query()->create([
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
        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_MANAGE]));
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
        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_MANAGE]));

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

    public function test_ticket_upload_guard_config_requires_manage_permission_and_roundtrips(): void
    {
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

        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_MANAGE]));

        $default = $this->getJson('/api/v2/admin/ticket-delivery-upload-guard')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertArrayHasKey('allowed_ips', $default->json('data'));
        $this->assertArrayHasKey('rate_limit', $default->json('data'));
        $this->assertArrayHasKey('block_non_whitelisted', $default->json('data'));

        $this->postJson('/api/v2/admin/ticket-delivery-upload-guard', [
            'allowed_ips' => "203.0.113.10\n198.51.100.0/24",
            'rate_limit' => 5,
            'block_non_whitelisted' => true,
        ])->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.allowed_ips', "203.0.113.10\n198.51.100.0/24")
            ->assertJsonPath('data.rate_limit', 5)
            ->assertJsonPath('data.block_non_whitelisted', true);

        $this->getJson('/api/v2/admin/ticket-delivery-upload-guard')
            ->assertOk()
            ->assertJsonPath('data.allowed_ips', "203.0.113.10\n198.51.100.0/24")
            ->assertJsonPath('data.rate_limit', 5)
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
