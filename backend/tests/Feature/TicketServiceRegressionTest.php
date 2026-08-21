<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ServiceStatus;
use App\Constants\UserNotificationType;
use App\Exceptions\BusinessException;
use App\Jobs\SendTicketNotificationEmailJob;
use App\Models\AdminUser;
use App\Models\Product;
use App\Models\Role;
use App\Models\Service;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\ClientServiceConsole\ServiceTransformService;
use App\Services\Notification\UserNotificationService;
use App\Services\System\NotificationService;
use App\Services\System\UploadedAssetReferenceService;
use App\Services\Ticket\TicketDeliveryService;
use App\Services\Ticket\TicketService;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TicketServiceRegressionTest extends TestCase
{
    public function test_service_status_labels_match_client_semantics(): void
    {
        $this->assertSame('开通中', ServiceStatus::$labels[ServiceStatus::PENDING] ?? null);
        $this->assertSame('已开通', ServiceStatus::$labels[ServiceStatus::ACTIVE] ?? null);
        $this->assertSame('已暂停', ServiceStatus::$labels[ServiceStatus::SUSPENDED] ?? null);
        $this->assertSame('已到期', ServiceStatus::$labels[ServiceStatus::EXPIRED] ?? null);
        $this->assertSame('已取消', ServiceStatus::$labels[ServiceStatus::CANCELLED] ?? null);
    }

    public function test_ticket_create_works_without_ticket_messages_table(): void
    {
        $this->runWithoutTicketMessagesTable(function (): void {
            $service = $this->makeTicketService();
            $user = $this->createClientUser('ticket-create');

            $ticket = $service->create((int) $user->id, [
                'department' => 'support',
                'subject' => 'Ticket Create Regression',
                'content' => 'Initial message',
                'priority' => 2,
            ]);

            $this->assertInstanceOf(Ticket::class, $ticket);
            $this->assertSame(TicketService::STATUS_OPEN, (int) $ticket->status);
            $this->assertSame(1, TicketReply::query()->where('ticket_id', (int) $ticket->id)->count());
        });
    }

    public function test_ticket_replies_work_without_ticket_messages_table(): void
    {
        $this->runWithoutTicketMessagesTable(function (): void {
            $service = $this->makeTicketService();
            $user = $this->createClientUser('ticket-reply');
            $staff = $this->createStaffUser();

            $ticket = $service->create((int) $user->id, [
                'department' => 'support',
                'subject' => 'Ticket Reply Regression',
                'content' => 'Initial message',
                'priority' => 2,
            ]);

            $clientReply = $service->clientReply($ticket->fresh(), (int) $user->id, 'Client follow-up', []);
            $staffReply = $service->staffReply($ticket->fresh(), (int) $staff->id, 'Staff response', []);

            $this->assertSame('Client follow-up', (string) ($clientReply['content'] ?? ''));
            $this->assertSame('Staff response', (string) ($staffReply['content'] ?? ''));
            $this->assertSame(3, TicketReply::query()->where('ticket_id', (int) $ticket->id)->count());
            $this->assertSame(
                TicketService::STATUS_STAFF_REPLY,
                (int) Ticket::query()->findOrFail((int) $ticket->id)->status
            );
        });
    }

    public function test_client_reply_rejects_when_ticket_closed_between_check_and_transaction(): void
    {
        $service = $this->makeTicketService();
        $user = $this->createClientUser('ticket-close-race');

        $ticket = $service->create((int) $user->id, [
            'department' => 'support',
            'subject' => 'Close Race Ticket',
            'content' => 'Initial message',
            'priority' => 2,
        ]);

        // 模拟并发关闭已提交：DB 行已关闭，但传入的 $ticket 对象仍是过期的 OPEN 状态。
        Ticket::query()->whereKey($ticket->id)->update([
            'status' => TicketService::STATUS_CLOSED,
            'close_reason' => 'admin',
        ]);
        $ticket->setAttribute('status', TicketService::STATUS_OPEN);

        try {
            $service->clientReply($ticket, (int) $user->id, 'Client reply after close', []);
            $this->fail('工单已关闭时不允许再回复');
        } catch (BusinessException $exception) {
            $this->assertSame('工单已关闭', $exception->getMessage());
        }

        $this->assertSame(TicketService::STATUS_CLOSED, (int) $ticket->refresh()->status);
        $this->assertSame(1, TicketReply::query()->where('ticket_id', (int) $ticket->id)->count());
    }

    public function test_staff_reply_rejects_when_ticket_closed_between_check_and_transaction(): void
    {
        $service = $this->makeTicketService();
        $user = $this->createClientUser('ticket-staff-close-race');
        $staff = $this->createStaffUser();

        $ticket = $service->create((int) $user->id, [
            'department' => 'support',
            'subject' => 'Staff Close Race Ticket',
            'content' => 'Initial message',
            'priority' => 2,
        ]);

        // 模拟并发关闭已提交：DB 行已关闭，但传入的 $ticket 对象仍是过期的 OPEN 状态。
        Ticket::query()->whereKey($ticket->id)->update([
            'status' => TicketService::STATUS_CLOSED,
            'close_reason' => 'auto',
        ]);
        $ticket->setAttribute('status', TicketService::STATUS_OPEN);

        try {
            $service->staffReply($ticket, (int) $staff->id, 'Staff reply after close', []);
            $this->fail('工单已关闭时不允许再回复');
        } catch (BusinessException $exception) {
            $this->assertSame('工单已关闭', $exception->getMessage());
        }

        $this->assertSame(TicketService::STATUS_CLOSED, (int) $ticket->refresh()->status);
        $this->assertSame(1, TicketReply::query()->where('ticket_id', (int) $ticket->id)->count());
    }

    public function test_close_soft_deletes_attachments_and_reopen_restores_them(): void
    {
        $service = $this->makeTicketService();
        $user = $this->createClientUser('ticket-softdel');
        $ticket = $service->create((int) $user->id, [
            'department' => 'support',
            'subject' => 'Soft Delete Ticket',
            'content' => 'Initial message',
            'priority' => 2,
        ]);
        $reply = TicketReply::query()->create([
            'ticket_id' => (int) $ticket->id,
            'user_id' => (int) $user->id,
            'content' => 'with attachment',
            'is_staff' => 0,
            'attachments' => [[
                'path' => 'private/tickets/temp/soft-del.png',
                'name' => 'soft-del.png',
                'size' => 1,
                'mime_type' => 'image/png',
                'type' => 'image',
                'deleted' => false,
            ]],
        ]);

        // 关闭：附件软删标记，不物理删除文件。
        $service->staffClose($ticket->fresh());
        $reply->refresh();
        $this->assertTrue((bool) data_get($reply->attachments[0] ?? [], 'deleted'));

        // 重开：CLOSED → OPEN，close_reason 清空，附件软删标记恢复。
        $reopened = $service->reopen($ticket->fresh(), ['operator_type' => 'admin']);
        $this->assertSame(TicketService::STATUS_OPEN, (int) $reopened->status);
        $this->assertNull($reopened->close_reason);
        $reply->refresh();
        $this->assertFalse((bool) data_get($reply->attachments[0] ?? [], 'deleted'));
    }

    public function test_reopen_rejects_non_closed_ticket(): void
    {
        $service = $this->makeTicketService();
        $user = $this->createClientUser('ticket-reopen-reject');
        $ticket = $service->create((int) $user->id, [
            'department' => 'support',
            'subject' => 'Reopen Reject Ticket',
            'content' => 'Initial message',
            'priority' => 2,
        ]);

        try {
            $service->reopen($ticket->fresh());
            $this->fail('仅已关闭工单可重开');
        } catch (BusinessException $exception) {
            $this->assertSame('仅已关闭工单可重开', $exception->getMessage());
        }
    }

    public function test_auto_close_sends_client_notification(): void
    {
        $calls = [];
        $userNotification = $this->createMock(UserNotificationService::class);
        $userNotification->method('create')
            ->willReturnCallback(function (...$args) use (&$calls) {
                $calls[] = $args;

                return null;
            });

        $service = new TicketService(
            $this->createMock(UploadedAssetReferenceService::class),
            $this->createMock(NotificationService::class),
            $this->createMock(ServiceTransformService::class),
            $userNotification,
            $this->createMock(TicketDeliveryService::class),
        );
        $user = $this->createClientUser('ticket-autoclose');
        $ticket = $service->create((int) $user->id, [
            'department' => 'support',
            'subject' => 'Auto Close Ticket',
            'content' => 'Initial message',
            'priority' => 2,
        ]);
        $staff = $this->createStaffUser();
        $service->staffReply($ticket->fresh(), (int) $staff->id, 'Staff reply', []);

        $service->autoClose($ticket->fresh());

        $this->assertSame(TicketService::STATUS_CLOSED, (int) $ticket->refresh()->status);
        $this->assertSame(TicketService::CLOSE_REASON_AUTO, $ticket->refresh()->close_reason);

        // 自动关闭接线：站内信类型为工单自动关闭。
        $autoClosedCalls = collect($calls)
            ->filter(fn (array $args): bool => ($args[1] ?? null) === UserNotificationType::TICKET_AUTO_CLOSED)
            ->values();
        $this->assertCount(1, $autoClosedCalls);
    }

    public function test_reopen_sends_dedicated_reopened_notification(): void
    {
        $calls = [];
        $userNotification = $this->createMock(UserNotificationService::class);
        $userNotification->method('create')
            ->willReturnCallback(function (...$args) use (&$calls) {
                $calls[] = $args;

                return null;
            });

        $service = new TicketService(
            $this->createMock(UploadedAssetReferenceService::class),
            $this->createMock(NotificationService::class),
            $this->createMock(ServiceTransformService::class),
            $userNotification,
            $this->createMock(TicketDeliveryService::class),
        );
        $user = $this->createClientUser('ticket-reopen');
        $ticket = $service->create((int) $user->id, [
            'department' => 'support',
            'subject' => 'Reopen Ticket',
            'content' => 'Initial message',
            'priority' => 2,
        ]);
        $staff = $this->createStaffUser();
        $service->staffReply($ticket->fresh(), (int) $staff->id, 'Staff reply', []);
        $service->staffClose($ticket->fresh());
        $this->assertSame(TicketService::STATUS_CLOSED, (int) $ticket->refresh()->status);

        $service->reopen($ticket->fresh());

        // 重开通知使用独立类型，不得复用"工单自动关闭"。
        $reopenedCalls = collect($calls)
            ->filter(fn (array $args): bool => ($args[1] ?? null) === UserNotificationType::TICKET_REOPENED)
            ->values();
        $this->assertCount(1, $reopenedCalls);
        $this->assertSame(TicketService::STATUS_OPEN, (int) $ticket->refresh()->status);
    }

    public function test_admin_list_ongoing_status_only_returns_unclosed_tickets(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $service = $this->makeTicketService();
        $user = $this->createClientUser('ticket-ongoing');

        $openTicket = Ticket::query()->create([
            'user_id' => (int) $user->id,
            'department' => 'support',
            'subject' => 'Open Ticket '.$suffix,
            'priority' => 2,
            'status' => TicketService::STATUS_OPEN,
        ]);

        $clientReplyTicket = Ticket::query()->create([
            'user_id' => (int) $user->id,
            'department' => 'support',
            'subject' => 'Client Reply Ticket '.$suffix,
            'priority' => 2,
            'status' => TicketService::STATUS_CLIENT_REPLY,
        ]);

        $staffReplyTicket = Ticket::query()->create([
            'user_id' => (int) $user->id,
            'department' => 'support',
            'subject' => 'Staff Reply Ticket '.$suffix,
            'priority' => 2,
            'status' => TicketService::STATUS_STAFF_REPLY,
        ]);

        $closedTicket = Ticket::query()->create([
            'user_id' => (int) $user->id,
            'department' => 'support',
            'subject' => 'Closed Ticket '.$suffix,
            'priority' => 2,
            'status' => TicketService::STATUS_CLOSED,
        ]);

        $result = $service->adminList(['status' => 'ongoing', 'keyword' => $suffix], 20);
        $ticketIds = collect($result->items())->pluck('id')->map(fn ($id) => (int) $id)->all();

        $this->assertContains((int) $openTicket->id, $ticketIds);
        $this->assertContains((int) $clientReplyTicket->id, $ticketIds);
        $this->assertContains((int) $staffReplyTicket->id, $ticketIds);
        $this->assertNotContains((int) $closedTicket->id, $ticketIds);
    }

    public function test_ticket_detail_only_depends_on_service_id_when_product_name_column_is_missing(): void
    {
        $service = $this->makeTicketService();
        $user = $this->createClientUser('ticket-detail');

        $product = Product::query()->create([
            'product_type' => 'server',
            'pricing' => ['monthly' => '19.90'],
            'setup_fee' => '0.00',
            'stock' => 10,
            'status' => 1,
            'sort_order' => 0,
            'auto_setup' => 0,
        ]);

        $linkedService = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => '实例 A',
            'domain' => 'example.test',
            'billing_cycle' => 'monthly',
            'amount' => '19.90',
            'status' => 1,
            'provision_data' => [],
        ]);

        $ticket = Ticket::query()->create([
            'user_id' => (int) $user->id,
            'department' => 'support',
            'subject' => 'Ticket Detail Regression',
            'priority' => 2,
            'status' => TicketService::STATUS_OPEN,
            'service_id' => (int) $linkedService->id,
        ]);

        $detail = $service->detail($ticket);

        $this->assertSame((int) $linkedService->id, (int) ($detail['service']['id'] ?? 0));
        $this->assertSame('实例 A', (string) ($detail['service']['name'] ?? ''));
        $this->assertArrayNotHasKey('product_name', $detail['service'] ?? []);
    }

    public function test_client_ticket_detail_endpoint_does_not_require_product_name_column(): void
    {
        $user = $this->createClientUser('ticket-detail-endpoint');

        $product = Product::query()->create([
            'product_type' => 'server',
            'pricing' => ['monthly' => '29.90'],
            'setup_fee' => '0.00',
            'stock' => 10,
            'status' => 1,
            'sort_order' => 0,
            'auto_setup' => 0,
        ]);

        $linkedService = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => '实例 B',
            'domain' => 'detail-endpoint.test',
            'billing_cycle' => 'monthly',
            'amount' => '29.90',
            'status' => 1,
            'provision_data' => [],
        ]);

        $ticket = Ticket::query()->create([
            'user_id' => (int) $user->id,
            'department' => 'support',
            'subject' => 'Ticket Detail Endpoint Regression',
            'priority' => 2,
            'status' => TicketService::STATUS_OPEN,
            'service_id' => (int) $linkedService->id,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v2/client/tickets/'.$ticket->id);

        $response->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.ticket.service.id', (int) $linkedService->id)
            ->assertJsonPath('data.ticket.service.name', '实例 B');

        $this->assertArrayNotHasKey('product_name', $response->json('data.ticket.service') ?? []);
    }

    public function test_ticket_create_schedules_notification_email_without_direct_mail_send(): void
    {
        Bus::fake();

        config()->set('queue.default', 'sync');

        $notificationService = $this->createMock(NotificationService::class);
        $notificationService->expects($this->never())->method('sendTemplateEmail');

        $service = new TicketService(
            $this->createMock(UploadedAssetReferenceService::class),
            $notificationService,
            $this->createMock(ServiceTransformService::class),
            $this->createMock(UserNotificationService::class),
            $this->createMock(TicketDeliveryService::class),
        );

        $user = $this->createClientUser('ticket-notify');
        $admin = $this->createTicketReplyAdmin();

        $ticket = $service->create((int) $user->id, [
            'department' => 'support',
            'subject' => 'Ticket Notification Regression',
            'content' => 'Initial message',
            'priority' => 2,
        ]);

        Bus::assertDispatchedAfterResponse(
            SendTicketNotificationEmailJob::class,
            fn (SendTicketNotificationEmailJob $job): bool => $job->to === (string) $admin->email
                && $job->templateCode === NotificationService::TEMPLATE_TICKET_CREATED
                && (int) ($job->context['ticket_id'] ?? 0) === (int) $ticket->id
        );
    }

    private function makeTicketService(): TicketService
    {
        return new TicketService(
            $this->createMock(UploadedAssetReferenceService::class),
            $this->createMock(NotificationService::class),
            $this->createMock(ServiceTransformService::class),
            $this->createMock(UserNotificationService::class),
            $this->createMock(TicketDeliveryService::class),
        );
    }

    private function createClientUser(string $prefix): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => $prefix.'-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Ticket Regression',
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

    private function createStaffUser(): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));

        $role = Role::query()->create([
            'name' => 'ticket-regression-role-'.$suffix,
            'label' => 'Ticket Regression Role',
            'permissions' => [],
        ]);

        return AdminUser::query()->create([
            'username' => 'ticket-regression-admin-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Ticket Staff',
            'email' => 'ticket-regression-admin-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    private function createTicketReplyAdmin(): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));

        $role = Role::query()->create([
            'name' => 'ticket-notify-role-'.$suffix,
            'label' => 'Ticket Notify Role',
            'permissions' => [AdminPermissions::TICKET_REPLY],
        ]);

        return AdminUser::query()->create([
            'username' => 'ticket-notify-admin-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Ticket Notify Admin',
            'email' => 'ticket-notify-admin-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    private function runWithoutTicketMessagesTable(callable $callback): mixed
    {
        $backupTable = null;

        if (Schema::hasTable('ticket_messages')) {
            $backupTable = 'ticket_messages_backup_'.bin2hex(random_bytes(4));
            Schema::rename('ticket_messages', $backupTable);
        }

        try {
            return $callback();
        } finally {
            if ($backupTable !== null && Schema::hasTable($backupTable)) {
                Schema::rename($backupTable, 'ticket_messages');
            }
        }
    }
}
