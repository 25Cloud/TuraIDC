<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ProductType;
use App\Constants\ServiceStatus;
use App\Models\AdminUser;
use App\Models\FirstProductGroup;
use App\Models\Product;
use App\Models\Role;
use App\Models\SecondProductGroup;
use App\Models\Service;
use App\Models\ThirdProductGroup;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\TicketUpstreamBinding;
use App\Models\User;
use App\Services\Ticket\TicketService;
use App\Support\AdminPermissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\Support\Concerns\UpstreamDeliveryWhitelist;
use Tests\TestCase;

class V2TicketApiTest extends TestCase
{
    use UpstreamDeliveryWhitelist;

    /**
     * @var list<string>
     */
    private array $attachmentPaths = [];

    protected function tearDown(): void
    {
        foreach ($this->attachmentPaths as $path) {
            $absolutePath = public_path($path);
            if (File::exists($absolutePath)) {
                File::delete($absolutePath);
            }
        }

        parent::tearDown();
    }

    public function test_client_ticket_detail_requires_login_rejects_per_page_and_enforces_ownership(): void
    {
        ['user' => $user, 'other_user' => $otherUser, 'ticket' => $ticket] = $this->createTicketFixture();

        $this->getJson('/api/v2/client/tickets/'.$ticket->id)
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($otherUser);

        $this->getJson('/api/v2/client/tickets/'.$ticket->id)
            ->assertNotFound()
            ->assertJsonPath('code', 40400);

        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/tickets/'.$ticket->id.'?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/client/tickets/'.$ticket->id.'?page=1')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['page']]]);

        $this->getJson('/api/v2/client/tickets/'.$ticket->id.'?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);
    }

    public function test_client_ticket_detail_excludes_replies_service_connection_and_sensitive_keys(): void
    {
        ['user' => $user, 'ticket' => $ticket] = $this->createTicketFixture(replyCount: 4);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v2/client/tickets/'.$ticket->id)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.ticket.id', $ticket->id)
            ->assertJsonPath('data.ticket.replies_summary.total', 4)
            ->assertJsonMissingPath('data.ticket.replies')
            ->assertJsonMissingPath('data.ticket.service.connection');

        $this->assertSame($this->ticketDetailWhitelist(), array_keys($response->json('data.ticket')));
        $this->assertSame($this->linkedServiceWhitelist(), array_keys($response->json('data.ticket.service')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    public function test_client_ticket_replies_are_paginated_and_attachment_whitelisted(): void
    {
        ['user' => $user, 'ticket' => $ticket] = $this->createTicketFixture(replyCount: 25, withAttachment: true);

        Sanctum::actingAs($user);

        $this->getJson('/api/v2/client/tickets/'.$ticket->id.'/replies?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/client/tickets/'.$ticket->id.'/replies?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $firstPage = $this->getJson('/api/v2/client/tickets/'.$ticket->id.'/replies?'.http_build_query([
            'page' => 1,
            'page_size' => 20,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.total', 25)
            ->assertJsonPath('data.page', 1)
            ->assertJsonPath('data.page_size', 20)
            ->assertJsonCount(20, 'data.list');

        $this->assertSame($this->ticketReplyWhitelist(), array_keys($firstPage->json('data.list.0')));
        $this->assertSame($this->attachmentWhitelist(), array_keys($firstPage->json('data.list.0.attachments.0')));
        $this->assertNoSensitiveKeys($firstPage->json());
        $this->assertLessThan(100 * 1024, strlen((string) $firstPage->getContent()));

        $this->getJson('/api/v2/client/tickets/'.$ticket->id.'/replies?'.http_build_query([
            'page' => 2,
            'page_size' => 20,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.page', 2)
            ->assertJsonCount(5, 'data.list');
    }

    public function test_admin_ticket_detail_and_replies_require_ticket_list_permission(): void
    {
        ['ticket' => $ticket] = $this->createTicketFixture();
        // 构造带上游绑定的真实数据，确保 upstream_delivery 白名单与 callback_token
        // 缺失断言在“已配置上游投递”的路径上被验证，而非空数据下的必然通过。
        TicketUpstreamBinding::query()->create([
            'ticket_id' => (int) $ticket->id,
            'provider_key' => 'zjmf_finance_api',
            'upstream_department_id' => '10',
            'upstream_service_id' => '100',
            'upstream_ticket_id' => 'T'.now()->format('YmdHis'),
            'status' => 'delivered',
            'attempts' => 1,
            'delivered_at' => now(),
        ]);
        $ticket->unsetRelation('upstreamBinding');

        $this->getJson('/api/v2/admin/tickets/'.$ticket->id)
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([]));

        $this->getJson('/api/v2/admin/tickets/'.$ticket->id)
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_LIST]));

        $this->getJson('/api/v2/admin/tickets/'.$ticket->id.'?page=1')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['page']]]);

        $this->getJson('/api/v2/admin/tickets/'.$ticket->id.'?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $detailResponse = $this->getJson('/api/v2/admin/tickets/'.$ticket->id)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.ticket.id', $ticket->id)
            ->assertJsonMissingPath('data.ticket.replies')
            ->assertJsonMissingPath('data.ticket.service.connection');

        $this->assertSame(
            [...$this->ticketDetailWhitelist(), 'upstream_delivery'],
            array_keys($detailResponse->json('data.ticket'))
        );
        $this->assertSame(
            $this->upstreamDeliveryWhitelist(),
            array_keys($detailResponse->json('data.ticket.upstream_delivery'))
        );
        $detailResponse->assertJsonMissingPath('data.ticket.upstream_delivery.callback_token');
        $this->assertNoSensitiveKeys($detailResponse->json());

        $this->getJson('/api/v2/admin/tickets/'.$ticket->id.'/replies?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/admin/tickets/'.$ticket->id.'/replies?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $repliesResponse = $this->getJson('/api/v2/admin/tickets/'.$ticket->id.'/replies')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.page_size', 20);

        $this->assertSame($this->ticketReplyWhitelist(), array_keys($repliesResponse->json('data.list.0')));
        $this->assertNoSensitiveKeys($repliesResponse->json());
    }

    public function test_admin_ticket_list_summary_and_admin_users_use_v2_projection(): void
    {
        ['ticket' => $ticket] = $this->createTicketFixture();
        $assignee = $this->createAdmin([AdminPermissions::TICKET_REPLY]);
        $ticket->update(['assignee_id' => (int) $assignee->id]);

        $this->getJson('/api/v2/admin/tickets')
            ->assertUnauthorized()
            ->assertJsonPath('code', 40100);

        Sanctum::actingAs($this->createAdmin([]));

        $this->getJson('/api/v2/admin/tickets')
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_LIST]));

        $this->getJson('/api/v2/admin/tickets?per_page=20&pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page', 'pageSize']]]);

        $listResponse = $this->getJson('/api/v2/admin/tickets?'.http_build_query([
            'keyword' => $ticket->subject,
            'status' => 'ongoing',
            'page' => 1,
            'page_size' => 5,
        ]))
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.id', $ticket->id)
            ->assertJsonPath('data.list.0.assignee.id', $assignee->id)
            ->assertJsonMissingPath('data.list.0.replies')
            ->assertJsonMissingPath('data.list.0.service')
            ->assertJsonMissingPath('data.list.0.content');

        $this->assertSame($this->ticketListWhitelist(), array_keys($listResponse->json('data.list.0')));
        $this->assertSame($this->ticketUserWhitelist(), array_keys($listResponse->json('data.list.0.user')));
        $this->assertSame($this->ticketAssigneeWhitelist(), array_keys($listResponse->json('data.list.0.assignee')));
        $this->assertNoSensitiveKeys($listResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $listResponse->getContent()));

        $this->getJson('/api/v2/admin/tickets/summary?per_page=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page']]]);

        $this->getJson('/api/v2/admin/tickets/summary?page=1')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['page']]]);

        $summaryResponse = $this->getJson('/api/v2/admin/tickets/summary')
            ->assertOk()
            ->assertJsonPath('code', 0);

        $this->assertSame(['open', 'client_reply', 'closed_today', 'total'], array_keys($summaryResponse->json('data')));
        $this->assertNoSensitiveKeys($summaryResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $summaryResponse->getContent()));

        $this->getJson('/api/v2/admin/tickets/admin-users?pageSize=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['pageSize']]]);

        $this->getJson('/api/v2/admin/tickets/admin-users?page_size=20')
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['page_size']]]);

        $adminUsersResponse = $this->getJson('/api/v2/admin/tickets/admin-users')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonFragment([
                'id' => $assignee->id,
                'username' => $assignee->username,
            ]);

        $this->assertSame(['list'], array_keys($adminUsersResponse->json('data')));
        $this->assertNoSensitiveKeys($adminUsersResponse->json());
        $this->assertLessThan(100 * 1024, strlen((string) $adminUsersResponse->getContent()));
    }

    public function test_admin_ticket_reply_uses_reply_permission_and_returns_reply_resource(): void
    {
        ['ticket' => $ticket] = $this->createTicketFixture();
        Queue::fake();
        config(['queue.default' => 'database']);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_LIST]));

        $this->postJson('/api/v2/admin/tickets/'.$ticket->id.'/replies', [
            'content' => 'staff reply',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 40300);

        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_REPLY]));

        $this->postJson('/api/v2/admin/tickets/'.$ticket->id.'/replies', [
            'content' => 'staff reply',
            'per_page' => 20,
            'pageSize' => 20,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['per_page', 'pageSize']]]);

        $response = $this->postJson('/api/v2/admin/tickets/'.$ticket->id.'/replies', [
            'content' => 'staff reply',
        ])
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('message', '回复成功')
            ->assertJsonPath('data.reply.ticket_id', $ticket->id)
            ->assertJsonPath('data.reply.content', 'staff reply')
            ->assertJsonPath('data.reply.is_staff', 1);

        $this->assertSame($this->ticketReplyWhitelist(), array_keys($response->json('data.reply')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
        $this->assertSame(TicketService::STATUS_STAFF_REPLY, (int) $ticket->refresh()->status);
    }

    public function test_admin_ticket_upload_image_uses_small_attachment_projection(): void
    {
        Sanctum::actingAs($this->createAdmin([AdminPermissions::TICKET_REPLY]));

        $this->postJson('/api/v2/admin/tickets/upload-images', ['pageSize' => 20])
            ->assertUnprocessable()
            ->assertJsonPath('code', 42200)
            ->assertJsonStructure(['data' => ['errors' => ['file', 'pageSize']]]);

        $before = $this->ticketTempFiles();

        try {
            $response = $this->post('/api/v2/admin/tickets/upload-images', [
                'file' => UploadedFile::fake()->image('ticket.png', 1, 1)->size(8),
            ], [
                'Accept' => 'application/json',
            ])
                ->assertOk()
                ->assertJsonPath('code', 0)
                ->assertJsonPath('message', '图片上传成功')
                ->assertJsonMissingPath('data.attachment.raw_response');
        } finally {
            foreach (array_diff($this->ticketTempFiles(), $before) as $path) {
                if (File::exists($path)) {
                    File::delete($path);
                }
            }
        }

        $this->assertNotSame('', (string) $response->json('data.attachment.id'));
        $this->assertNotSame('', (string) $response->json('data.attachment.path'));
        $this->assertSame($this->ticketUploadAttachmentWhitelist(), array_keys($response->json('data.attachment')));
        $this->assertNoSensitiveKeys($response->json());
        $this->assertLessThan(100 * 1024, strlen((string) $response->getContent()));
    }

    /**
     * @return array{user: User, other_user: User, service: Service, ticket: Ticket}
     */
    private function createTicketFixture(int $replyCount = 3, bool $withAttachment = false): array
    {
        $suffix = bin2hex(random_bytes(4));
        $user = $this->createClientUser('owner-'.$suffix);
        $otherUser = $this->createClientUser('other-'.$suffix);
        $product = $this->createProduct($suffix);
        $service = Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Ticket Service '.$suffix,
            'domain' => 'ticket-'.$suffix.'.example.test',
            'billing_cycle' => 'monthly',
            'amount' => '66.00',
            'locked_pricing' => ['monthly' => '66.00'],
            'status' => ServiceStatus::ACTIVE,
            'provision_data' => [
                'username' => 'root',
                'password' => 'should-not-leak',
                'dedicated_ip' => '203.0.113.10',
                'requested_config' => ['cpu' => 2],
            ],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 1,
        ]);

        $ticket = Ticket::query()->create([
            'user_id' => (int) $user->id,
            'department' => 'support',
            'subject' => 'V2 ticket '.$suffix,
            'priority' => 2,
            'status' => 1,
            'service_id' => (int) $service->id,
        ]);

        foreach (range(1, $replyCount) as $index) {
            TicketReply::query()->create([
                'ticket_id' => (int) $ticket->id,
                'user_id' => (int) ($index % 3 === 0 ? $this->createAdmin([AdminPermissions::TICKET_REPLY])->id : $user->id),
                'content' => 'reply '.$index,
                'is_staff' => $index % 3 === 0 ? 1 : 0,
                'attachments' => $withAttachment && $index === 1 ? [$this->createAttachment($suffix)] : [],
                'created_at' => now()->addSeconds($index),
            ]);
        }

        return [
            'user' => $user,
            'other_user' => $otherUser,
            'service' => $service,
            'ticket' => $ticket->refresh(),
        ];
    }

    private function createClientUser(string $suffix): User
    {
        return User::query()->create([
            'email' => 'v2-ticket-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'V2 Ticket '.$suffix,
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

    private function createAdmin(array $permissions): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));
        $role = Role::query()->create([
            'name' => 'v2-ticket-'.$suffix,
            'label' => 'V2 Ticket',
            'permissions' => $permissions,
        ]);

        return AdminUser::query()->create([
            'username' => 'v2-ticket-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'V2 Ticket',
            'email' => 'v2-ticket-admin-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    private function createProduct(string $suffix): Product
    {
        $firstGroup = FirstProductGroup::query()->create([
            'code' => 'v2_ticket_'.$suffix,
            'name' => '工单分组 '.$suffix,
            'slug' => 'v2-ticket-'.$suffix,
            'description' => '工单分组说明',
            'sort_order' => 1,
            'is_visible' => 1,
            'is_system' => 0,
            'legacy_product_type' => ProductType::VPS,
        ]);

        $secondGroup = SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => '工单二级 '.$suffix,
            'slug' => 'v2-ticket-child-'.$suffix,
            'description' => '工单二级说明',
            'sort_order' => 1,
            'is_visible' => 1,
        ]);
        $thirdGroup = ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $secondGroup->id,
            'name' => '工单三级 '.$suffix,
            'slug' => 'v2-ticket-leaf-'.$suffix,
            'description' => '工单三级说明',
            'sort_order' => 1,
            'is_visible' => 1,
        ]);

        return Product::query()->create([
            'product_group_id' => (int) $thirdGroup->id,
            'service_type_code' => ProductType::VPS,
            'name' => 'V2 Ticket Product '.$suffix,
            'custom_display_name' => 'V2 Ticket Product '.$suffix,
            'product_type' => ProductType::VPS,
            'description' => '',
            'pricing' => ['monthly' => '66.00'],
            'setup_fee' => '0.00',
            'config_options' => [['field' => 'cpu', 'name' => 'CPU']],
            'purchase_requires' => ['secret' => 'must-not-leak'],
            'stock' => 10,
            'status' => 1,
            'sort_order' => 1,
            'auto_setup' => 0,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function createAttachment(string $suffix): array
    {
        $path = 'uploads/tickets/temp/v2-ticket-'.$suffix.'.png';
        File::ensureDirectoryExists(public_path('uploads/tickets/temp'));
        File::put(public_path($path), base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));
        $this->attachmentPaths[] = $path;

        return [
            'name' => 'v2-ticket-'.$suffix.'.png',
            'path' => $path,
            'size' => (int) File::size(public_path($path)),
            'mime_type' => 'image/png',
            'type' => 'image',
        ];
    }

    /**
     * @return list<string>
     */
    private function ticketTempFiles(): array
    {
        // 新上传落在 storage/app/private/tickets/temp，历史文件仍可能在 public/uploads/tickets/temp
        return collect(File::glob(storage_path('app/private/tickets/temp/*')) ?: [])
            ->merge(File::glob(public_path('uploads/tickets/temp/*')) ?: [])
            ->map(fn (string $path): string => str_replace('\\', '/', $path))
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function ticketDetailWhitelist(): array
    {
        return [
            'id',
            'user_id',
            'department',
            'department_label',
            'subject',
            'priority',
            'priority_label',
            'status',
            'status_label',
            'service_id',
            'assignee_id',
            'close_reason',
            'close_reason_label',
            'created_at',
            'updated_at',
            'user',
            'service',
            'assignee',
            'replies_summary',
        ];
    }

    /**
     * @return list<string>
     */
    private function linkedServiceWhitelist(): array
    {
        return [
            'id',
            'name',
            'display_name',
            'domain',
            'status',
            'status_label',
            'billing_cycle',
            'billing_cycle_label',
            'amount',
            'expires_at',
            'specs',
        ];
    }

    /**
     * @return list<string>
     */
    private function ticketReplyWhitelist(): array
    {
        return [
            'id',
            'ticket_id',
            'user_id',
            'content',
            'is_staff',
            'sender_name',
            'attachments',
            'recalled',
            'recalled_at',
            'quote',
            'created_at',
        ];
    }

    /**
     * @return list<string>
     */
    private function ticketListWhitelist(): array
    {
        return [
            'id',
            'user_id',
            'department',
            'department_label',
            'subject',
            'priority',
            'priority_label',
            'status',
            'status_label',
            'service_id',
            'assignee_id',
            'close_reason',
            'close_reason_label',
            'created_at',
            'updated_at',
            'user',
            'assignee',
        ];
    }

    /**
     * @return list<string>
     */
    private function ticketUserWhitelist(): array
    {
        return [
            'id',
            'email',
            'nickname',
            'display_name',
        ];
    }

    /**
     * @return list<string>
     */
    private function ticketAssigneeWhitelist(): array
    {
        return [
            'id',
            'username',
            'nickname',
        ];
    }

    /**
     * @return list<string>
     */
    private function ticketUploadAttachmentWhitelist(): array
    {
        return [
            'id',
            'name',
            'path',
            'url',
            'size',
            'mime_type',
            'type',
        ];
    }

    /**
     * @return list<string>
     */
    private function attachmentWhitelist(): array
    {
        return [
            'id',
            'name',
            'type',
            'url',
            'deleted',
        ];
    }

    private function assertNoSensitiveKeys(mixed $payload): void
    {
        if (! is_array($payload)) {
            return;
        }

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $normalized = strtolower($key);

                if ($normalized !== 'has_password') {
                    $this->assertStringNotContainsString('password', $normalized);
                }

                foreach (['secret', 'api_key', 'raw_response', 'third_party_response', 'connection'] as $needle) {
                    $this->assertStringNotContainsString($needle, $normalized);
                }
            }

            $this->assertNoSensitiveKeys($value);
        }
    }
}
