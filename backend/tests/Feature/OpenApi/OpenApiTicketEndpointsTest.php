<?php

declare(strict_types=1);

namespace Tests\Feature\OpenApi;

use App\Models\ApiKey;
use App\Models\Setting;
use App\Models\Ticket;
use App\Models\User;
use App\Services\OpenApi\ApiKeyService;
use App\Services\Ticket\TicketService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * 开放接口 - 工单与重置密码。
 *
 * 用的都是旧「AI 助手」工具面依赖的那批能力，迁到开放接口后必须证明：
 * 归属隔离、作用域隔离、写端点受读/写分级约束。
 */
class OpenApiTicketEndpointsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::setValues('open_api', ['enabled' => '1']);
    }

    public function test_tickets_scope_is_an_accepted_key_domain(): void
    {
        $this->assertContains('tickets', ApiKeyService::SCOPE_DOMAINS);
    }

    public function test_read_key_lists_only_own_tickets(): void
    {
        $owner = $this->createClientUser();
        $other = $this->createClientUser();
        $mine = $this->createTicket($owner, '我的工单');
        $this->createTicket($other, '别人的工单');

        [, $plain] = $this->createKey($owner, ['tickets' => 'read']);

        $response = $this->withToken($plain)->getJson('/api/v2/open/tickets')->assertOk()->assertJsonPath('code', 0);

        $ids = array_column((array) $response->json('data.list'), 'id');
        $this->assertSame([(int) $mine->id], $ids);
        $this->assertSame(1, (int) $response->json('data.total'));
    }

    public function test_read_key_cannot_create_ticket(): void
    {
        $user = $this->createClientUser();
        [, $plain] = $this->createKey($user, ['tickets' => 'read']);

        $this->withToken($plain)->postJson('/api/v2/open/tickets', [
            'department' => 'support',
            'subject' => '读密钥不该能建单',
            'content' => '正文',
        ])->assertStatus(403);
    }

    public function test_write_key_creates_ticket_then_reads_detail(): void
    {
        $user = $this->createClientUser();
        [, $plain] = $this->createKey($user, ['tickets' => 'write']);

        $created = $this->withToken($plain)->postJson('/api/v2/open/tickets', [
            'department' => 'support',
            'subject' => '开放接口建单',
            'content' => '从 agent 提交的工单',
            'priority' => 2,
        ])->assertOk()->assertJsonPath('code', 0);

        $ticketId = (int) $created->json('data.id');
        $this->assertGreaterThan(0, $ticketId);
        $this->assertSame('开放接口建单', $created->json('data.subject'));

        $this->withToken($plain)->getJson('/api/v2/open/tickets/'.$ticketId)
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->withToken($plain)->getJson('/api/v2/open/tickets/'.$ticketId.'/replies')
            ->assertOk()
            ->assertJsonPath('code', 0);
    }

    public function test_write_key_can_reply_to_own_ticket(): void
    {
        $user = $this->createClientUser();
        $ticket = $this->createTicket($user, '待回复工单');
        [, $plain] = $this->createKey($user, ['tickets' => 'write']);

        $this->withToken($plain)->postJson('/api/v2/open/tickets/'.(int) $ticket->id.'/replies', [
            'content' => '补充一下现象',
        ])->assertOk()->assertJsonPath('code', 0);
    }

    public function test_other_users_ticket_is_not_visible(): void
    {
        $owner = $this->createClientUser();
        $stranger = $this->createClientUser();
        $ticket = $this->createTicket($owner, '别人的工单');
        [, $plain] = $this->createKey($stranger, ['tickets' => 'write']);

        $this->withToken($plain)->getJson('/api/v2/open/tickets/'.(int) $ticket->id)->assertNotFound();
    }

    public function test_password_reset_requires_min_length(): void
    {
        $user = $this->createClientUser();
        [, $plain] = $this->createKey($user, ['services' => 'write']);

        $this->withToken($plain)->postJson('/api/v2/open/services/1/password-resets', [
            'password' => 'short',
        ])->assertStatus(422);
    }

    public function test_read_only_services_key_cannot_reset_password(): void
    {
        $user = $this->createClientUser();
        [, $plain] = $this->createKey($user, ['services' => 'read']);

        $this->withToken($plain)->postJson('/api/v2/open/services/1/password-resets', [
            'password' => 'LongEnough123',
        ])->assertStatus(403);
    }

    private function createClientUser(): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => "open-ticket-{$suffix}@example.com",
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => '开放接口工单测试用户',
            'real_name' => '',
            'id_card' => '',
            'verification_status' => 0,
            'verification_message' => '',
            'verification_certify_id' => null,
            'member_level_id' => null,
            'total_sales_amount' => '0.00',
            'referrer_user_id' => null,
            'verified_at' => null,
            'login_email_alert' => 0,
            'login_notify' => 0,
            'login_location_alert' => 0,
            'phone_change_alert' => 0,
            'email_change_alert' => 0,
        ]);
    }

    private function createTicket(User $user, string $subject): Ticket
    {
        return Ticket::query()->create([
            'user_id' => (int) $user->id,
            'department' => 'support',
            'subject' => $subject,
            'priority' => 2,
            'status' => TicketService::STATUS_OPEN,
        ]);
    }

    /**
     * @param  array<string, string>  $scopes
     * @return array{0: ApiKey, 1: string}
     */
    private function createKey(User $user, array $scopes): array
    {
        return app(ApiKeyService::class)->createForUser($user, '工单接口测试', $scopes);
    }
}