<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\Admin\Rbac\PermissionCatalogService;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Services\Notification\UserNotificationService;
use App\Services\System\NotificationService;
use App\Services\System\UploadedAssetReferenceService;
use App\Services\Ticket\TicketDeliveryService;
use App\Services\Ticket\TicketPreReplyService;
use App\Services\Ticket\TicketService;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TicketPreReplyTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        // 恢复 ticket_pre_reply 配置，避免残留启用状态影响同一进程内其他建单用例。
        Setting::setValues(TicketPreReplyService::SETTINGS_GROUP, [
            'enabled' => '0',
            'admin_user_id' => '0',
            'content' => '',
            'upstream_content' => '',
        ]);

        parent::tearDown();
    }

    public function test_client_creating_ticket_triggers_pre_reply_in_staff_name(): void
    {
        $staff = $this->createStaff();
        $user = $this->createClientUser('pre-reply-on');
        $this->enablePreReply((int) $staff->id, '您的工单已收到，请耐心等待管理员回复。');

        $ticket = $this->makeTicketService()->create((int) $user->id, [
            'department' => 'support',
            'subject' => 'Pre-reply ticket',
            'content' => '我需要帮助',
            'priority' => 2,
        ]);

        $replies = TicketReply::query()->where('ticket_id', (int) $ticket->id)->orderBy('id')->get();
        $this->assertCount(2, $replies);
        $this->assertSame(0, (int) $replies[0]->is_staff);
        $this->assertSame('client', (string) $replies[0]->sender_type);

        $preReply = $replies[1];
        $this->assertSame(1, (int) $preReply->is_staff);
        $this->assertSame('admin', (string) $preReply->sender_type);
        $this->assertSame((int) $staff->id, (int) $preReply->user_id);
        $this->assertSame((string) $staff->nickname, (string) $preReply->sender_name);
        $this->assertSame('您的工单已收到，请耐心等待管理员回复。', (string) $preReply->content);
        $this->assertSame([], $preReply->attachments);

        // 预回复视为员工响应：工单状态置为「员工回复」。
        $this->assertSame(TicketService::STATUS_STAFF_REPLY, (int) $ticket->fresh()->status);
    }

    public function test_pre_reply_is_not_created_when_disabled(): void
    {
        $user = $this->createClientUser('pre-reply-off');

        $ticket = $this->makeTicketService()->create((int) $user->id, [
            'department' => 'support',
            'subject' => 'Pre-reply off ticket',
            'content' => '初始消息',
            'priority' => 2,
        ]);

        $this->assertSame(1, TicketReply::query()->where('ticket_id', (int) $ticket->id)->count());
        $this->assertSame(TicketService::STATUS_OPEN, (int) $ticket->fresh()->status);
    }

    public function test_pre_reply_is_not_created_when_admin_is_missing(): void
    {
        $user = $this->createClientUser('pre-reply-missing');
        // 配置了启用与内容，但管理员 ID 指向不存在的账号。
        $this->enablePreReply(999999, '请耐心等待管理员回复。');

        $ticket = $this->makeTicketService()->create((int) $user->id, [
            'department' => 'support',
            'subject' => 'Pre-reply missing admin',
            'content' => '初始消息',
            'priority' => 2,
        ]);

        $this->assertSame(1, TicketReply::query()->where('ticket_id', (int) $ticket->id)->count());
        $this->assertSame(TicketService::STATUS_OPEN, (int) $ticket->fresh()->status);
    }

    public function test_pre_reply_is_not_created_when_content_is_empty(): void
    {
        $staff = $this->createStaff();
        $user = $this->createClientUser('pre-reply-empty');
        $this->enablePreReply((int) $staff->id, '');

        $ticket = $this->makeTicketService()->create((int) $user->id, [
            'department' => 'support',
            'subject' => 'Pre-reply empty content',
            'content' => '初始消息',
            'priority' => 2,
        ]);

        $this->assertSame(1, TicketReply::query()->where('ticket_id', (int) $ticket->id)->count());
        $this->assertSame(TicketService::STATUS_OPEN, (int) $ticket->fresh()->status);
    }

    public function test_pre_reply_uses_upstream_content_when_ticket_matches_delivery_rule(): void
    {
        $staff = $this->createStaff();
        $user = $this->createClientUser('pre-reply-upstream');
        $this->enablePreReply((int) $staff->id, '普通工单回复内容', '上游工单专用回复内容');

        $deliveryService = $this->createMock(TicketDeliveryService::class);
        $deliveryService->method('matchesDeliveryRule')->willReturn(true);

        $ticket = $this->makeTicketService($deliveryService)->create((int) $user->id, [
            'department' => 'support',
            'subject' => 'Pre-reply upstream ticket',
            'content' => '初始消息',
            'priority' => 2,
        ]);

        $preReply = TicketReply::query()->where('ticket_id', (int) $ticket->id)->orderBy('id')->get()->last();
        $this->assertSame('上游工单专用回复内容', (string) $preReply->content);
        $this->assertSame(1, (int) $preReply->is_staff);
    }

    public function test_pre_reply_falls_back_to_content_when_upstream_content_empty(): void
    {
        $staff = $this->createStaff();
        $user = $this->createClientUser('pre-reply-fallback');
        // 命中上游规则但未单独配置上游内容：回退使用普通内容。
        $this->enablePreReply((int) $staff->id, '普通工单回复内容', '');

        $deliveryService = $this->createMock(TicketDeliveryService::class);
        $deliveryService->method('matchesDeliveryRule')->willReturn(true);

        $ticket = $this->makeTicketService($deliveryService)->create((int) $user->id, [
            'department' => 'support',
            'subject' => 'Pre-reply fallback ticket',
            'content' => '初始消息',
            'priority' => 2,
        ]);

        $preReply = TicketReply::query()->where('ticket_id', (int) $ticket->id)->orderBy('id')->get()->last();
        $this->assertSame('普通工单回复内容', (string) $preReply->content);
    }

    public function test_pre_reply_uses_content_when_delivery_rule_not_matched(): void
    {
        $staff = $this->createStaff();
        $user = $this->createClientUser('pre-reply-plain');
        // 配置了上游内容，但工单未命中传递规则：仍使用普通内容。
        $this->enablePreReply((int) $staff->id, '普通工单回复内容', '上游工单专用回复内容');

        $deliveryService = $this->createMock(TicketDeliveryService::class);
        $deliveryService->method('matchesDeliveryRule')->willReturn(false);

        $ticket = $this->makeTicketService($deliveryService)->create((int) $user->id, [
            'department' => 'support',
            'subject' => 'Pre-reply plain ticket',
            'content' => '初始消息',
            'priority' => 2,
        ]);

        $preReply = TicketReply::query()->where('ticket_id', (int) $ticket->id)->orderBy('id')->get()->last();
        $this->assertSame('普通工单回复内容', (string) $preReply->content);
    }

    public function test_settings_show_returns_defaults_and_assignable_admins(): void
    {
        $staff = $this->createStaff();
        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_PRE_REPLY_MANAGE]));

        $response = $this->getJson('/api/v2/admin/ticket-pre-reply-settings')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $settings = $response->json('data.settings');
        $this->assertFalse($settings['enabled']);
        $this->assertSame(0, $settings['admin_user_id']);
        $this->assertSame('', $settings['content']);
        $this->assertSame('', $settings['upstream_content']);

        // 候选人仅包含可回复工单的启用管理员。
        $ids = collect($response->json('data.admin_users'))->pluck('id')->map(fn ($id) => (int) $id);
        $this->assertContains((int) $staff->id, $ids->all());
    }

    public function test_settings_save_persists_configuration(): void
    {
        $staff = $this->createStaff();
        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_PRE_REPLY_MANAGE]));

        $this->postJson('/api/v2/admin/ticket-pre-reply-settings', [
            'enabled' => true,
            'admin_user_id' => $staff->id,
            'content' => "您的工单已收到，请耐心等待。\n管理员会尽快处理。",
            'upstream_content' => '您的工单已提交给上游服务商处理。',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.enabled', '1')
            ->assertJsonPath('data.admin_user_id', (string) $staff->id)
            ->assertJsonPath('data.upstream_content', '您的工单已提交给上游服务商处理。');

        $this->assertSame('1', Setting::getValue(TicketPreReplyService::SETTINGS_GROUP, 'enabled'));
        $this->assertSame((string) $staff->id, Setting::getValue(TicketPreReplyService::SETTINGS_GROUP, 'admin_user_id'));
        $this->assertSame(
            "您的工单已收到，请耐心等待。\n管理员会尽快处理。",
            Setting::getValue(TicketPreReplyService::SETTINGS_GROUP, 'content')
        );
        $this->assertSame(
            '您的工单已提交给上游服务商处理。',
            Setting::getValue(TicketPreReplyService::SETTINGS_GROUP, 'upstream_content')
        );
    }

    public function test_settings_validation_rejects_incomplete_enabled_config(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_PRE_REPLY_MANAGE]));

        // 启用但回复内容为空。
        $this->postJson('/api/v2/admin/ticket-pre-reply-settings', [
            'enabled' => true,
            'admin_user_id' => 1,
            'content' => '',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['content']]]);

        // 启用但所选管理员不存在。
        $this->postJson('/api/v2/admin/ticket-pre-reply-settings', [
            'enabled' => true,
            'admin_user_id' => 999999,
            'content' => '请耐心等待管理员回复。',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['admin_user_id']]]);
    }

    public function test_settings_allow_disabling_without_admin_and_content(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_PRE_REPLY_MANAGE]));

        $this->postJson('/api/v2/admin/ticket-pre-reply-settings', [
            'enabled' => false,
            'admin_user_id' => 0,
            'content' => '',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertSame('0', Setting::getValue(TicketPreReplyService::SETTINGS_GROUP, 'enabled'));
        $this->assertSame('0', Setting::getValue(TicketPreReplyService::SETTINGS_GROUP, 'admin_user_id'));
    }

    public function test_settings_require_pre_reply_manage_permission(): void
    {
        // 仅持 ticket.manage：预回复设置路由被中间件拦截，权限隔离在路由层生效。
        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_MANAGE]));

        $this->getJson('/api/v2/admin/ticket-pre-reply-settings')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        $this->postJson('/api/v2/admin/ticket-pre-reply-settings', [
            'enabled' => false,
            'admin_user_id' => 0,
            'content' => '',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);
    }

    public function test_permission_catalog_lists_pre_reply_manage(): void
    {
        $items = collect(app(PermissionCatalogService::class)->list())->keyBy('key');

        $this->assertTrue($items->has(AdminPermissions::TICKET_PRE_REPLY_MANAGE));
        $this->assertSame('support_ticket', $items[AdminPermissions::TICKET_PRE_REPLY_MANAGE]['group']);
        $this->assertSame('配置工单预回复设置', $items[AdminPermissions::TICKET_PRE_REPLY_MANAGE]['name']);
    }

    private function makeTicketService(?TicketDeliveryService $deliveryService = null): TicketService
    {
        $deliveryService ??= $this->createMock(TicketDeliveryService::class);

        return new TicketService(
            $this->createMock(UploadedAssetReferenceService::class),
            $this->createMock(NotificationService::class),
            $this->createMock(ServiceTransformService::class),
            $this->createMock(UserNotificationService::class),
            $deliveryService,
            new TicketPreReplyService($deliveryService),
        );
    }

    private function enablePreReply(int $adminUserId, string $content, string $upstreamContent = ''): void
    {
        Setting::setValues(TicketPreReplyService::SETTINGS_GROUP, [
            'enabled' => '1',
            'admin_user_id' => (string) $adminUserId,
            'content' => $content,
            'upstream_content' => $upstreamContent,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createStaff(array $permissions = [AdminPermissions::TICKET_REPLY]): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'pre-reply-role-'.$suffix,
            'label' => 'Pre Reply Role',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'pre-reply-admin-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => '预回复管理员',
            'email' => 'pre-reply-admin-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'pre-reply-manage-'.$suffix,
            'label' => 'Pre Reply Manage',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'pre-reply-manage-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Pre Reply Manage',
            'email' => 'pre-reply-manage-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    private function createClientUser(string $prefix): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => $prefix.'-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Pre Reply Client',
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
    }
}
