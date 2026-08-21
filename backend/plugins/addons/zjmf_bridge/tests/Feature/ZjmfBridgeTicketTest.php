<?php

declare(strict_types=1);

namespace Tests\Feature;

require_once __DIR__.'/../Support/InstallsZjmfBridgeAddon.php';

use App\Models\User;
use App\Services\Ticket\TicketService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Tests\Support\InstallsZjmfBridgeAddon;
use Tests\TestCase;
use TuraIDC\Plugins\Addons\ZjmfBridge\Services\ZjmfTokenService;

class ZjmfBridgeTicketTest extends TestCase
{
    use DatabaseTransactions;
    use InstallsZjmfBridgeAddon;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake();
        Cache::flush();
        config([
            'zjmf_bridge.enabled' => true,
            'zjmf_bridge.secret' => 'zjmf-test-secret',
            'zjmf_bridge.token_ttl' => 7200,
        ]);
        $this->installZjmfBridgeAddon();
    }

    public function test_ticket_core_flow_uses_zjmf_token_scopes(): void
    {
        $user = $this->createClientUser();
        $writeHeaders = ['Authorization' => 'JWT '.$this->jwtFor($user, ['ticket.write'])];
        $readHeaders = ['Authorization' => 'JWT '.$this->jwtFor($user, ['ticket.read'])];

        $createResponse = $this
            ->withHeaders($writeHeaders)
            ->postJson('/zjmf/v1/tickets', [
                'department' => 'support',
                'subject' => 'ZJMF 工单测试',
                'content' => '请协助检查服务。',
                'priority' => 3,
            ]);

        $createResponse
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('msg', '工单提交成功')
            ->assertJsonPath('data.ticket.subject', 'ZJMF 工单测试')
            ->assertJsonPath('data.ticket.status', TicketService::STATUS_OPEN)
            ->assertJsonPath('data.ticket.status_label', TicketService::STATUS_LABELS[TicketService::STATUS_OPEN]);

        $ticketId = (int) $createResponse->json('data.ticket.id');

        $this
            ->withHeaders($readHeaders)
            ->get('/zjmf/v1/tickets/page', ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.departments.0.value', 'sales');

        $this
            ->withHeaders($readHeaders)
            ->get('/zjmf/v1/tickets?limit=10', ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.list.0.id', $ticketId)
            ->assertJsonPath('data.list.0.ticketid', $ticketId);

        $this
            ->withHeaders($writeHeaders)
            ->postJson('/zjmf/v1/tickets/'.$ticketId.'/reply', [
                'content' => '补充一条信息。',
            ])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('msg', '回复成功')
            ->assertJsonPath('data.reply.content', '补充一条信息。')
            ->assertJsonPath('data.ticket.status', TicketService::STATUS_CLIENT_REPLY);

        $this
            ->withHeaders($readHeaders)
            ->get('/zjmf/v1/tickets/'.$ticketId, ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('data.ticket.id', $ticketId)
            ->assertJsonPath('data.ticket.replies.1.content', '补充一条信息。');

        $this
            ->withHeaders($writeHeaders)
            ->postJson('/zjmf/v1/tickets/'.$ticketId.'/close', [])
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('msg', '工单已关闭')
            ->assertJsonPath('data.ticket.status', TicketService::STATUS_CLOSED);
    }

    public function test_ticket_routes_reject_missing_scope(): void
    {
        $user = $this->createClientUser();

        $this
            ->withHeaders(['Authorization' => 'JWT '.$this->jwtFor($user, ['service.read'])])
            ->get('/zjmf/v1/tickets', ['Accept' => 'application/json'])
            ->assertStatus(403)
            ->assertJsonPath('status', 403)
            ->assertJsonPath('msg', '接口 scope 未授权');
    }

    private function createClientUser(): User
    {
        $suffix = bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => 'zjmf-ticket-'.$suffix.'@example.com',
            'phone' => '137'.random_int(10000000, 99999999),
            'password' => 'Secret123!',
            'nickname' => 'ZJMF Ticket',
            'status' => 1,
        ]);
    }

    /**
     * @param  list<string>  $scopes
     */
    private function jwtFor(User $user, array $scopes): string
    {
        return app(ZjmfTokenService::class)->issue([
            'sub' => 'client:'.(int) $user->id,
            'uid' => (int) $user->id,
            'scope' => $scopes,
        ], 7200);
    }
}
