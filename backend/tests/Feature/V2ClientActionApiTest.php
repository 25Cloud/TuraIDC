<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\InvoiceStatus;
use App\Constants\OrderStatus;
use App\Constants\PaymentGatewayCode;
use App\Constants\PaymentStatus;
use App\Models\ContentArticle;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\ClientServiceConsole\ClientServiceConsoleService;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use Tests\TestCase;

class V2ClientActionApiTest extends TestCase
{
    public function test_client_action_routes_require_auth_and_reject_legacy_per_page_parameter(): void
    {
        $user = $this->createClientUser('validation');

        $this->postJson('/api/v2/client/invoices/1/cancellations')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($user);

        $this->putJson('/api/v2/client/notifications/1/read-state?per_page=10')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->postJson('/api/v2/client/services/1/power-actions?per_page=10', [
            'action' => 'reboot',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->postJson('/api/v2/client/services/1/password-resets?per_page=10', [
            'password' => 'NewPassw0rd!',
            'password_confirmation' => 'NewPassw0rd!',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->postJson('/api/v2/client/services/1/reinstallations?per_page=10', [
            'os_id' => 'centos-9',
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);
    }

    public function test_read_state_actions_are_scoped_to_valid_client_resources(): void
    {
        $user = $this->createClientUser('read-owner');
        $otherUser = $this->createClientUser('read-other');
        $notice = ContentArticle::factory()->create([
            'content_type' => ContentArticle::TYPE_NOTICE,
            'status' => ContentArticle::STATUS_PUBLISHED,
            'publish_at' => now()->subMinute(),
        ]);
        $helpArticle = ContentArticle::factory()->create([
            'content_type' => ContentArticle::TYPE_HELP,
            'status' => ContentArticle::STATUS_PUBLISHED,
            'publish_at' => now()->subMinute(),
        ]);
        $notification = UserNotification::query()->create([
            'user_id' => (int) $user->id,
            'type' => 'system',
            'title' => 'V2 action notification',
            'content' => 'content',
            'data' => ['safe' => true],
        ]);
        $otherNotification = UserNotification::query()->create([
            'user_id' => (int) $otherUser->id,
            'type' => 'system',
            'title' => 'Other notification',
            'content' => 'content',
        ]);

        Sanctum::actingAs($user);

        $noticeResponse = $this->putJson('/api/v2/client/notices/'.$notice->id.'/read-state')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $notice->id)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonMissingPath('data.detail');

        $this->assertActionResponse($noticeResponse->json(), ['id', 'status', 'message']);
        $this->assertDatabaseHas('notice_reads', [
            'user_id' => (int) $user->id,
            'article_id' => (int) $notice->id,
        ]);

        $this->putJson('/api/v2/client/notices/'.$helpArticle->id.'/read-state')
            ->assertNotFound()
            ->assertJsonPath('code', 40400);

        $notificationResponse = $this->putJson('/api/v2/client/notifications/'.$notification->id.'/read-state')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $notification->id)
            ->assertJsonPath('data.status', 'completed');

        $this->assertActionResponse($notificationResponse->json(), ['id', 'status', 'message']);
        $this->assertNotNull($notification->fresh()->read_at);

        $this->putJson('/api/v2/client/notifications/'.$otherNotification->id.'/read-state')
            ->assertNotFound()
            ->assertJsonPath('code', 40400);
        $this->assertNull($otherNotification->fresh()->read_at);
    }

    public function test_invoice_and_order_cancellations_are_owner_scoped_and_keep_payment_records(): void
    {
        $fixture = $this->createInvoiceOrderFixture('owner');
        $otherFixture = $this->createInvoiceOrderFixture('other');
        $paymentCount = Payment::query()->count();

        Sanctum::actingAs($otherFixture['user']);

        $this->postJson('/api/v2/client/invoices/'.$fixture['invoice']->id.'/cancellations')
            ->assertNotFound()
            ->assertJsonPath('code', 40400);

        $this->postJson('/api/v2/client/orders/'.$fixture['order']->id.'/cancellations')
            ->assertNotFound()
            ->assertJsonPath('code', 40400);

        Sanctum::actingAs($fixture['user']);

        $invoiceResponse = $this->postJson('/api/v2/client/invoices/'.$fixture['invoice']->id.'/cancellations')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $fixture['invoice']->id)
            ->assertJsonPath('data.detail.invoice_status', InvoiceStatus::CANCELLED);

        $this->assertActionResponse($invoiceResponse->json(), ['id', 'status', 'message', 'detail']);
        $this->assertSame(InvoiceStatus::CANCELLED, (int) $fixture['invoice']->fresh()->status);

        $orderResponse = $this->postJson('/api/v2/client/orders/'.$fixture['order']->id.'/cancellations')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $fixture['order']->id)
            ->assertJsonPath('data.detail.order_status', OrderStatus::CANCELLED);

        $this->assertActionResponse($orderResponse->json(), ['id', 'status', 'message', 'detail']);
        $this->assertSame(OrderStatus::CANCELLED, (int) $fixture['order']->fresh()->status);
        $this->assertSame($paymentCount, Payment::query()->count());
    }

    public function test_ticket_reply_recall_is_owner_scoped_and_returns_small_action_result(): void
    {
        $user = $this->createClientUser('ticket-owner');
        $otherUser = $this->createClientUser('ticket-other');
        $ticket = Ticket::query()->create([
            'user_id' => (int) $user->id,
            'department' => 'support',
            'subject' => 'V2 action ticket',
            'priority' => 2,
            'status' => 1,
        ]);
        $reply = TicketReply::query()->create([
            'ticket_id' => (int) $ticket->id,
            'user_id' => (int) $user->id,
            'content' => 'need recall',
            'is_staff' => 0,
            'attachments' => [],
            'created_at' => now(),
        ]);

        Sanctum::actingAs($otherUser);

        $this->postJson('/api/v2/client/tickets/'.$ticket->id.'/replies/'.$reply->id.'/recalls')
            ->assertNotFound()
            ->assertJsonPath('code', 40400);

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v2/client/tickets/'.$ticket->id.'/replies/'.$reply->id.'/recalls')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', $reply->id)
            ->assertJsonPath('data.detail.ticket_id', $ticket->id);

        $this->assertActionResponse($response->json(), ['id', 'status', 'message', 'detail']);
        $this->assertNotNull($reply->fresh()->recalled_at);
    }

    public function test_service_action_response_is_compacted_and_does_not_expose_legacy_detail(): void
    {
        $user = $this->createClientUser('service-action');

        $this->mock(ClientServiceConsoleService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('powerActionForUser')
                ->once()
                ->withArgs(function (User $actualUser, int $serviceId, string $action, array $context) use ($user): bool {
                    return (int) $actualUser->id === (int) $user->id
                        && $serviceId === 123
                        && $action === 'reboot'
                        && ($context['actor_type'] ?? null) === 'client';
                })
                ->andReturn([
                    'action' => 'reboot',
                    'action_label' => '重启',
                    'message' => '重启指令已发送',
                    'status' => [
                        'status' => 'running',
                        'message' => '执行中',
                        'password' => 'must-not-leak',
                        'raw_response' => ['must' => 'not leak'],
                    ],
                    'detail' => [
                        'connection' => [
                            'password' => 'must-not-leak',
                        ],
                        'api_key' => 'must-not-leak',
                    ],
                    'secret' => 'must-not-leak',
                ]);
        });

        Sanctum::actingAs($user);

        $response = $this->postJson('/api/v2/client/services/123/power-actions', [
            'action' => 'reboot',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.id', 123)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.detail.action', 'reboot')
            ->assertJsonPath('data.detail.status.status', 'running')
            ->assertJsonMissingPath('data.detail.detail')
            ->assertJsonMissingPath('data.detail.secret')
            ->assertJsonMissingPath('data.detail.status.raw_response')
            ->assertJsonMissingPath('data.detail.status.password');

        $this->assertActionResponse($response->json(), ['id', 'status', 'message', 'detail']);
    }

    /**
     * @return array{user: User, product: Product, order: Order, invoice: Invoice, payment: Payment}
     */
    private function createInvoiceOrderFixture(string $prefix): array
    {
        $suffix = strtoupper(bin2hex(random_bytes(4)));
        $user = $this->createClientUser($prefix.'-'.$suffix);
        $product = Product::query()->create([
            'custom_display_name' => 'V2 Action Product '.$suffix,
            'product_type' => 'vps',
            'service_type_code' => 'vps',
            'pricing' => ['monthly' => '19.90'],
            'setup_fee' => '0.00',
            'config_options' => [
                ['field' => 'cpu', 'name' => 'CPU', 'api_key' => 'must-not-leak'],
            ],
            'purchase_requires' => [],
            'stock' => -1,
            'status' => 1,
            'sort_order' => 1,
            'auto_setup' => 0,
        ]);
        $order = Order::query()->create([
            'order_no' => 'V2ACTORD'.$suffix,
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => 'V2 Action Product '.$suffix,
            'product_type_snapshot' => 'vps',
            'type' => 'new',
            'amount' => '19.90',
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'status' => OrderStatus::PENDING,
        ]);
        $invoice = Invoice::query()->create([
            'invoice_no' => 'V2ACTINV'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'product_id' => (int) $product->id,
            'product_spec_snapshot' => 'V2 Action Product '.$suffix,
            'product_type_snapshot' => 'vps',
            'type' => 'new',
            'amount' => '19.90',
            'discount' => '0.00',
            'paid_amount' => '0.00',
            'status' => InvoiceStatus::UNPAID,
            'billing_cycle' => 'monthly',
            'quantity' => 1,
            'due_date' => now()->addDay(),
            'config_snapshot' => [
                'password' => 'must-not-leak',
            ],
        ]);
        $payment = Payment::query()->create([
            'payment_no' => 'V2ACTPAY'.$suffix,
            'user_id' => (int) $user->id,
            'order_id' => (int) $order->id,
            'invoice_id' => (int) $invoice->id,
            'gateway' => PaymentGatewayCode::ALIPAY,
            'gateway_key' => PaymentGatewayCode::ALIPAY,
            'trade_no' => 'V2ACTTRADE'.$suffix,
            'amount' => '19.90',
            'status' => PaymentStatus::PENDING,
            'callback_raw' => [
                'raw_response' => 'must-not-leak',
            ],
        ]);

        return [
            'user' => $user,
            'product' => $product,
            'order' => $order,
            'invoice' => $invoice,
            'payment' => $payment,
        ];
    }

    private function createClientUser(string $suffix): User
    {
        $unique = $suffix.'-'.bin2hex(random_bytes(4));

        return User::query()->create([
            'email' => 'v2-action-'.$unique.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'V2 Action '.$unique,
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

    /**
     * @param  list<string>  $dataKeys
     */
    private function assertActionResponse(array $payload, array $dataKeys): void
    {
        $this->assertSame(0, $payload['code']);
        $this->assertSame($dataKeys, array_keys($payload['data']));
        $this->assertArrayHasKey('message', $payload);
        $this->assertArrayHasKey('timestamp', $payload);
        $this->assertNoSensitiveKeys($payload);
        $this->assertLessThan(100 * 1024, strlen((string) json_encode($payload)));
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
