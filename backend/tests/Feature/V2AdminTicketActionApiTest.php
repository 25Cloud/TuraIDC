<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\Ticket\TicketService;
use App\Support\AdminPermissions;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class V2AdminTicketActionApiTest extends TestCase
{
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
