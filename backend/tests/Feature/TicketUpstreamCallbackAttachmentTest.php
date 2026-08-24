<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Constants\ProductType;
use App\Models\AdminUser;
use App\Models\FirstProductGroup;
use App\Models\IntegrationPlugin;
use App\Models\Product;
use App\Models\Role;
use App\Models\SecondProductGroup;
use App\Models\ThirdProductGroup;
use App\Models\Supplier;
use App\Models\SupplierPluginBinding;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\TicketUpstreamBinding;
use App\Models\User;
use App\Services\Ticket\TicketUpstreamCallbackToken;
use App\Services\Upstream\ProviderKey;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TicketUpstreamCallbackAttachmentTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (File::exists($path)) {
                File::delete($path);
            }
        }
        parent::tearDown();
    }

    public function test_upstream_callback_local_attachment_is_visible_in_client_replies(): void
    {
        [$ticket, $serviceId, $supplier] = $this->createBindingFixture();
        $filename = 'callback-'.$this->suffix().'.png';
        $absolutePath = storage_path('app/private/tickets/upstream/'.$filename);
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));
        $this->paths[] = $absolutePath;

        $payload = [
            'id' => (string) $serviceId,
            'tid' => (string) $ticket->upstreamBinding->upstream_ticket_id,
            'rid' => 'remote-reply-'.Str::lower(Str::random(8)),
            'rand_str' => Str::lower(Str::random(12)),
            'content' => '',
            'attachment' => [['savename' => $filename, 'name' => '上游图片.png']],
            'admin_name' => '上游客服',
        ];
        $payload['signature'] = $this->legacySignature($payload, $serviceId);

        $response = $this->postJson('/api/ticket_reply/sync', $payload)
            ->assertOk()
            ->assertJsonPath('status', 200);

        $reply = TicketReply::query()->findOrFail($response->json('data.reply_id'));
        $this->assertSame('private/tickets/upstream/'.$filename, $reply->attachments[0]['path']);

        Sanctum::actingAs($ticket->user);
        $clientReplies = $this->getJson('/api/v2/client/tickets/'.$ticket->id.'/replies?page=1&page_size=100')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.attachments.0.name', '上游图片.png')
            ->assertJsonPath('data.list.0.attachments.0.type', 'image')
            ->assertJsonPath('data.list.0.attachments.0.deleted', false);
        $clientUrl = (string) $clientReplies->json('data.list.0.attachments.0.url');
        $this->assertStringContainsString('/api/secure-assets/view', $clientUrl);

        Sanctum::actingAs($this->createAdmin());
        $adminReplies = $this->getJson('/api/v2/admin/tickets/'.$ticket->id.'/replies?page=1&page_size=100')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.attachments.0.name', '上游图片.png');
        $adminUrl = (string) $adminReplies->json('data.list.0.attachments.0.url');
        $this->assertStringContainsString('/api/secure-assets/view', $adminUrl);
    }

    public function test_upstream_callback_single_attachment_object_is_visible_in_admin_replies(): void
    {
        [$ticket, $serviceId] = $this->createBindingFixture();
        $filename = 'callback-object-'.$this->suffix().'.png';
        $absolutePath = storage_path('app/private/tickets/upstream/'.$filename);
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));
        $this->paths[] = $absolutePath;

        $payload = [
            'id' => (string) $serviceId,
            'tid' => (string) $ticket->upstreamBinding->upstream_ticket_id,
            'rid' => 'remote-object-'.$this->suffix(),
            'rand_str' => Str::lower(Str::random(12)),
            'content' => '图片回调',
            'attachment' => ['savename' => $filename, 'name' => '对象图片.png'],
            'admin_name' => '上游客服',
        ];
        $payload['signature'] = $this->legacySignature($payload, $serviceId);

        $response = $this->postJson('/api/ticket_reply/sync', $payload)
            ->assertOk()
            ->assertJsonPath('status', 200);

        $reply = TicketReply::query()->findOrFail($response->json('data.reply_id'));
        $this->assertCount(1, $reply->attachments);

        Sanctum::actingAs($this->createAdmin());
        $adminReplies = $this->getJson('/api/v2/admin/tickets/'.$ticket->id.'/replies?page=1&page_size=100')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.attachments.0.name', '对象图片.png');
        $this->assertStringContainsString('/api/secure-assets/view', (string) $adminReplies->json('data.list.0.attachments.0.url'));
    }

    public function test_upstream_callback_legacy_savename_list_is_persisted(): void
    {
        [$ticket, $serviceId] = $this->createBindingFixture();
        $filename = 'callback-list-'.$this->suffix().'.png';
        $absolutePath = storage_path('app/private/tickets/upstream/'.$filename);
        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII='));
        $this->paths[] = $absolutePath;

        $payload = [
            'id' => (string) $serviceId,
            'tid' => (string) $ticket->upstreamBinding->upstream_ticket_id,
            'rid' => 'remote-list-'.$this->suffix(),
            'rand_str' => Str::lower(Str::random(12)),
            'content' => '列表附件',
            'attachment' => [$filename],
            'admin_name' => '上游客服',
        ];
        $payload['signature'] = $this->legacySignature($payload, $serviceId);

        $response = $this->postJson('/api/ticket_reply/sync', $payload)
            ->assertOk()
            ->assertJsonPath('status', 200);

        $reply = TicketReply::query()->findOrFail($response->json('data.reply_id'));
        $this->assertSame('private/tickets/upstream/'.$filename, $reply->attachments[0]['path']);

        Sanctum::actingAs($ticket->user);
        $this->getJson('/api/v2/client/tickets/'.$ticket->id.'/replies?page=1&page_size=100')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.list.0.attachments.0.name', $filename);
    }

    /** @return array{0: Ticket, 1: int, 2: Supplier} */
    private function createBindingFixture(): array
    {
        $suffix = $this->suffix();
        $user = User::query()->create([
            'email' => 'callback-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'nickname' => '回调测试用户',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
        ]);
        $firstGroup = FirstProductGroup::query()->create([
            'code' => 'callback_'.$suffix,
            'name' => '回调一级分组 '.$suffix,
            'slug' => 'callback-'.$suffix,
            'description' => '',
            'sort_order' => 1,
            'is_visible' => 1,
            'is_system' => 0,
            'legacy_product_type' => ProductType::VPS,
        ]);
        $secondGroup = SecondProductGroup::query()->create([
            'first_product_group_id' => $firstGroup->id,
            'name' => '回调二级分组 '.$suffix,
            'slug' => 'callback-child-'.$suffix,
            'description' => '',
            'sort_order' => 1,
            'is_visible' => 1,
        ]);
        $thirdGroup = ThirdProductGroup::query()->create([
            'second_product_group_id' => $secondGroup->id,
            'name' => '回调三级分组 '.$suffix,
            'slug' => 'callback-leaf-'.$suffix,
            'description' => '',
            'sort_order' => 1,
            'is_visible' => 1,
        ]);
        $product = Product::query()->create([
            'product_group_id' => $thirdGroup->id,
            'service_type_code' => ProductType::VPS,
            'name' => '回调测试产品 '.$suffix,
            'custom_display_name' => '回调测试产品 '.$suffix,
            'product_type' => ProductType::VPS,
            'description' => '',
            'pricing' => ['monthly' => '1.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => 1,
            'status' => 1,
            'sort_order' => 1,
            'auto_setup' => 0,
        ]);
        $service = \App\Models\Service::query()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
            'name' => '回调测试服务 '.$suffix,
            'domain' => 'callback-'.$suffix.'.example.test',
            'billing_cycle' => 'monthly',
            'amount' => '1.00',
            'locked_pricing' => ['monthly' => '1.00'],
            'status' => 1,
            'provision_data' => [],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 0,
        ]);
        $ticket = Ticket::query()->create([
            'user_id' => $user->id,
            'department' => 'support',
            'subject' => '回调测试工单 '.$suffix,
            'priority' => 2,
            'status' => 1,
            'service_id' => $service->id,
        ]);
        $supplier = Supplier::query()->create([
            'name' => '回调测试供应商 '.$suffix,
            'code' => 'callback-'.$suffix,
            'status' => 1,
        ]);
        $plugin = IntegrationPlugin::query()->create([
            'domain' => 'upstream',
            'slug' => 'zjmf-finance-'.$suffix,
            'plugin_key' => 'zjmf-finance-'.$suffix,
            'name' => '回调测试插件',
            'version' => '1.0.0',
            'entry_class' => 'Tests\\FakePlugin',
            'status' => 1,
            'installed_at' => now(),
        ]);
        SupplierPluginBinding::query()->create([
            'supplier_id' => $supplier->id,
            'plugin_id' => $plugin->id,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'environment' => 'production',
            'status' => 1,
            'base_url' => 'https://zjmf.example.test/api',
            'account_name' => 'demo',
            'config_json' => [],
            'has_secret_json' => ['api_key' => true],
        ]);
        TicketUpstreamBinding::query()->create([
            'ticket_id' => $ticket->id,
            'provider_key' => ProviderKey::ZJMF_FINANCE_API,
            'supplier_id' => $supplier->id,
            'upstream_department_id' => '1',
            'upstream_service_id' => '1',
            'upstream_ticket_id' => 'upstream-'.$suffix,
            'status' => 'delivered',
        ]);

        return [$ticket->fresh('upstreamBinding'), (int) $service->id, $supplier];
    }

    private function createAdmin(): AdminUser
    {
        $suffix = $this->suffix();
        $role = Role::query()->create([
            'name' => 'callback-admin-'.$suffix,
            'label' => '回调测试管理员',
            'permissions' => [AdminPermissions::TICKET_LIST],
        ]);

        return AdminUser::query()->create([
            'username' => 'callback-admin-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => $role->id,
            'nickname' => '回调管理员',
            'email' => 'callback-admin-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    private function legacySignature(array $payload, int $serviceId): string
    {
        $signed = [
            'id' => (string) $serviceId,
            'token' => TicketUpstreamCallbackToken::forServiceId($serviceId),
            'rand_str' => (string) $payload['rand_str'],
        ];
        ksort($signed, SORT_STRING);

        return strtoupper(md5((string) json_encode($signed)));
    }

    private function suffix(): string
    {
        return strtolower(bin2hex(random_bytes(5)));
    }
}
