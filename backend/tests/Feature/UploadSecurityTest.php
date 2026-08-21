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
use App\Models\Setting;
use App\Models\ThirdProductGroup;
use App\Models\Ticket;
use App\Models\TicketReply;
use App\Models\User;
use App\Services\Content\MediaFileService;
use App\Services\Ticket\TicketService;
use App\Services\Ticket\TicketUpstreamCallbackService;
use App\Services\Ticket\TicketUpstreamCallbackToken;
use App\Support\AdminPermissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UploadSecurityTest extends TestCase
{
    private array $mediaFileIds = [];

    private array $uploadedFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->uploadedFiles as $path) {
            File::delete($path);
        }

        if ($this->mediaFileIds !== []) {
            DB::table('media_files')->whereIn('id', $this->mediaFileIds)->delete();
        }

        parent::tearDown();
    }

    public function test_media_upload_uses_detected_image_extension_instead_of_client_filename(): void
    {
        $admin = $this->createAdmin();
        $fake = UploadedFile::fake()->image('safe.jpg', 16, 16)->size(8);
        $file = new UploadedFile($fake->getPathname(), 'payload.php', 'image/jpeg', null, true);

        $mediaFile = app(MediaFileService::class)->upload($file, (int) $admin->id, 'security_test');
        $this->mediaFileIds[] = (int) $mediaFile->id;

        $path = (string) $mediaFile->path;
        $this->uploadedFiles[] = public_path(ltrim($path, '/'));

        $this->assertMatchesRegularExpression('#^/media/[^/]+\.jpg$#', $path);
        $this->assertStringEndsWith('.jpg', $path);
        $this->assertStringNotContainsString('.php', $path);
        $this->assertFileExists(public_path(ltrim($path, '/')));
    }

    public function test_media_upload_endpoint_still_accepts_normal_content_image(): void
    {
        Sanctum::actingAs($this->createAdmin());

        $response = $this->post('/api/v2/admin/media-files', [
            'file' => UploadedFile::fake()->image('normal.jpg', 16, 16)->size(8),
            'group' => 'content',
        ]);

        $response->assertOk()->assertJsonPath('code', 0);

        $path = (string) $response->json('data.path');
        $this->mediaFileIds[] = (int) $response->json('data.id');
        $this->uploadedFiles[] = public_path(ltrim($path, '/'));

        $this->assertMatchesRegularExpression('#^/media/[^/]+\.jpg$#', $path);
        $this->assertStringEndsWith('.jpg', $path);
        $this->assertFileExists(public_path(ltrim($path, '/')));
    }

    public function test_media_upload_endpoint_accepts_video_and_stores_it_under_unified_media_directory(): void
    {
        Sanctum::actingAs($this->createAdmin());
        $sourceVideo = public_path('media/3.mp4');
        $this->assertFileExists($sourceVideo);
        $videoContent = file_get_contents($sourceVideo);
        $this->assertIsString($videoContent);

        $response = $this->post('/api/v2/admin/media-files', [
            'file' => UploadedFile::fake()
                ->createWithContent('hero.mp4', $videoContent)
                ->mimeType('video/mp4'),
            'group' => MediaFileService::HERO_VIDEO_GROUP,
        ]);

        $response->assertOk()->assertJsonPath('code', 0);

        $path = (string) $response->json('data.path');
        $this->mediaFileIds[] = (int) $response->json('data.id');
        $this->uploadedFiles[] = public_path(ltrim($path, '/'));

        $this->assertMatchesRegularExpression('#^/media/[^/]+\.mp4$#', $path);
        $this->assertStringEndsWith('.mp4', $path);
        $this->assertFileExists(public_path(ltrim($path, '/')));
    }

    public function test_media_upload_rejects_path_traversal_group(): void
    {
        Sanctum::actingAs($this->createAdmin());

        $this->post('/api/v2/admin/media-files', [
            'file' => UploadedFile::fake()->image('safe.jpg', 16, 16)->size(8),
            'group' => '../escape',
        ])->assertStatus(422);
    }

    public function test_upstream_ticket_upload_returns_legacy_savename_contract(): void
    {
        // 默认兼容模式：旧上游（不携带凭证）上传应成功，保证回调附件可用
        $response = $this->post('/upload_image', [
            'file' => UploadedFile::fake()->image('logo.png', 16, 16)->size(8),
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('code', 0)
            ->assertJsonPath('msg', '上传成功')
            ->assertJsonPath('data.savename', $response->json('savename'));

        $filename = (string) $response->json('savename');
        $absolutePath = storage_path('app/private/tickets/upstream/'.$filename);
        $this->uploadedFiles[] = $absolutePath;
        $this->assertMatchesRegularExpression('/\\.png$/', $filename);
        $this->assertFileExists($absolutePath);
        $this->assertNotEmpty($filename);
    }

    public function test_upstream_ticket_upload_does_not_call_get_size_after_move(): void
    {
        $service = $this->createUpstreamUploadService();

        $response = $this->post('/upload_image', [
            'file' => UploadedFile::fake()->image('after-move.png', 32, 32)->size(16),
            'id' => (string) $service->id,
            'token' => TicketUpstreamCallbackToken::forServiceId((int) $service->id),
        ]);

        $response->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('code', 0);

        $filename = (string) $response->json('savename');
        $this->uploadedFiles[] = storage_path('app/private/tickets/upstream/'.$filename);
        $this->assertSame($filename, (string) $response->json('data.savename'));
        $this->assertFileExists(storage_path('app/private/tickets/upstream/'.$filename));
    }

    public function test_upstream_ticket_upload_credential_checks_are_enforced_when_required(): void
    {
        // 强制校验开启时，无凭证/伪造凭证一律拒绝（fail-closed）
        config()->set('ticket_upstream.upload_token_required', true);
        $service = $this->createUpstreamUploadService();

        $this->post('/upload_image', [
            'file' => UploadedFile::fake()->image('anon.png', 16, 16)->size(8),
        ])->assertJsonPath('status', 400);

        $this->post('/upload_image', [
            'file' => UploadedFile::fake()->image('bad-token.png', 16, 16)->size(8),
            'id' => (string) $service->id,
            'token' => 'forged-token',
        ])->assertJsonPath('status', 400);

        $this->post('/upload_image', [
            'file' => UploadedFile::fake()->image('bad-id.png', 16, 16)->size(8),
            'id' => '99999999',
            'token' => TicketUpstreamCallbackToken::forServiceId((int) $service->id),
        ])->assertJsonPath('status', 400);

        // 兼容模式下带凭证上传仍强制匹配，伪造 token 不被放行
        config()->set('ticket_upstream.upload_token_required', false);
        $this->post('/upload_image', [
            'file' => UploadedFile::fake()->image('bad-token-2.png', 16, 16)->size(8),
            'id' => (string) $service->id,
            'token' => 'forged-token',
        ])->assertJsonPath('status', 400);

        // 兼容模式下有效凭证正常通过
        $this->post('/upload_image', [
            'file' => UploadedFile::fake()->image('good-token.png', 16, 16)->size(8),
            'id' => (string) $service->id,
            'token' => TicketUpstreamCallbackToken::forServiceId((int) $service->id),
        ])->assertJsonPath('status', 200);
    }

    public function test_upstream_orphan_upload_cleanup_removes_only_unreferenced_files(): void
    {
        $directory = storage_path('app/private/tickets/upstream');
        // 测试环境目录可能残留其他用例的上传文件，先清空保证删除计数精确
        File::ensureDirectoryExists($directory);
        File::cleanDirectory($directory);

        $orphanName = 'upstream-'.now()->subDays(30)->format('YmdHis').'-'.bin2hex(random_bytes(6)).'.png';
        $referencedName = 'upstream-'.now()->subDays(30)->format('YmdHis').'-'.bin2hex(random_bytes(6)).'.png';
        $orphanPath = $directory.'/'.$orphanName;
        $referencedPath = $directory.'/'.$referencedName;
        File::put($orphanPath, 'orphan');
        File::put($referencedPath, 'referenced');
        touch($orphanPath, now()->subDays(30)->timestamp);
        touch($referencedPath, now()->subDays(30)->timestamp);
        $this->uploadedFiles[] = $orphanPath;
        $this->uploadedFiles[] = $referencedPath;

        $service = $this->createUpstreamUploadService();
        $ticket = Ticket::query()->create([
            'user_id' => (int) $service->user_id,
            'department' => 'support',
            'subject' => 'orphan cleanup',
            'priority' => 2,
            'status' => 1,
            'service_id' => (int) $service->id,
        ]);
        TicketReply::query()->create([
            'ticket_id' => (int) $ticket->id,
            'user_id' => (int) $service->user_id,
            'content' => 'referenced attachment',
            'is_staff' => 0,
            'attachments' => [[
                'name' => $referencedName,
                'path' => 'private/tickets/upstream/'.$referencedName,
                'size' => 10,
                'mime_type' => 'image/png',
                'type' => 'image',
            ]],
        ]);

        $result = app(TicketUpstreamCallbackService::class)->cleanupOrphanUploads(retentionMinutes: 7, limit: 100);

        $this->assertSame(1, $result['deleted']);
        $this->assertSame(1, $result['referenced']);
        $this->assertFileDoesNotExist($orphanPath);
        $this->assertFileExists($referencedPath);
    }

    public function test_upstream_upload_throttle_respects_whitelist_and_rate_limit(): void
    {
        Setting::forgetCachedGroup('ticket_upstream');

        // 白名单 IP 不限速：即使非白名单速率仅为 1 次/分钟，白名单 IP 连续上传也成功
        Setting::setValues('ticket_upstream', [
            'allowed_ips' => '203.0.113.10',
            'rate_limit' => '1',
        ]);
        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
                ->post('/upload_image', [
                    'file' => UploadedFile::fake()->image('whitelist-'.$i.'.png', 8, 8)->size(4),
                ])
                ->assertJsonPath('status', 200);
        }

        // 非白名单 IP 限速：第 1 次成功，第 2 次被拒（429）
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post('/upload_image', [
                'file' => UploadedFile::fake()->image('limited-1.png', 8, 8)->size(4),
            ])
            ->assertJsonPath('status', 200);
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.20'])
            ->post('/upload_image', [
                'file' => UploadedFile::fake()->image('limited-2.png', 8, 8)->size(4),
            ])
            ->assertJsonPath('status', 429);

        // rate_limit=0 表示不限速
        Setting::setValues('ticket_upstream', [
            'allowed_ips' => '',
            'rate_limit' => '0',
        ]);
        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.30'])
                ->post('/upload_image', [
                    'file' => UploadedFile::fake()->image('unlimited-'.$i.'.png', 8, 8)->size(4),
                ])
                ->assertJsonPath('status', 200);
        }

        // 恢复默认配置，避免影响其他测试
        Setting::setValues('ticket_upstream', [
            'allowed_ips' => '',
            'rate_limit' => (string) config('ticket_upstream.upload_rate_limit', 30),
        ]);
    }

    public function test_ticket_image_upload_still_accepts_normal_image(): void
    {
        $image = app(TicketService::class)->uploadImage(
            123,
            'client',
            UploadedFile::fake()->image('ticket.jpg', 16, 16)->size(8)
        );

        parse_str((string) parse_url((string) $image['url'], PHP_URL_QUERY), $query);
        $path = (string) ($query['path'] ?? '');
        $absolutePath = storage_path('app/'.str_replace('/', DIRECTORY_SEPARATOR, ltrim($path, '/')));
        $this->uploadedFiles[] = $absolutePath;

        $this->assertSame('ticket.jpg', $image['name']);
        $this->assertSame('image/jpeg', $image['mime_type']);
        // 工单附件必须落在 storage/app/private 下，不能落到 Web 根，否则签名短链可被绕过
        $this->assertStringStartsWith('private/tickets/temp/', $path);
        $this->assertStringEndsWith('.jpg', $path);
        $this->assertFileExists($absolutePath);
        $this->assertFileDoesNotExist(public_path(str_replace('/', DIRECTORY_SEPARATOR, ltrim($path, '/'))));
    }

    public function test_secure_asset_signed_route_rejects_path_traversal(): void
    {
        $url = URL::temporarySignedRoute(
            'secure-assets.show',
            now()->addMinutes(5),
            ['path' => 'private/tickets/../secrets.png'],
            absolute: false
        );

        $this->get($url)->assertNotFound();
    }

    private function createAdmin(): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));

        $role = Role::query()->create([
            'name' => 'upload-security-'.$suffix,
            'label' => 'Upload Security',
            'permissions' => [AdminPermissions::ALL],
        ]);

        return AdminUser::query()->create([
            'username' => 'upload-security-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Upload Security',
            'email' => 'upload-security-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }

    private function createUpstreamUploadService(): Service
    {
        $suffix = bin2hex(random_bytes(4));
        $user = User::query()->create([
            'email' => 'upload-'.$suffix.'@example.com',
            'password' => 'Temp@123456',
            'phone' => '13'.str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT),
            'status' => 1,
            'nickname' => 'Upload '.$suffix,
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

        $firstGroup = FirstProductGroup::query()->create([
            'code' => 'upload_security_'.$suffix,
            'name' => '上传安全分组 '.$suffix,
            'slug' => 'upload-security-'.$suffix,
            'description' => '上传安全分组说明',
            'sort_order' => 1,
            'is_visible' => 1,
            'is_system' => 0,
            'legacy_product_type' => ProductType::VPS,
        ]);
        $secondGroup = SecondProductGroup::query()->create([
            'first_product_group_id' => (int) $firstGroup->id,
            'name' => '上传安全二级 '.$suffix,
            'slug' => 'upload-security-child-'.$suffix,
            'description' => '上传安全二级说明',
            'sort_order' => 1,
            'is_visible' => 1,
        ]);
        $thirdGroup = ThirdProductGroup::query()->create([
            'second_product_group_id' => (int) $secondGroup->id,
            'name' => '上传安全三级 '.$suffix,
            'slug' => 'upload-security-leaf-'.$suffix,
            'description' => '上传安全三级说明',
            'sort_order' => 1,
            'is_visible' => 1,
        ]);
        $product = Product::query()->create([
            'product_group_id' => (int) $thirdGroup->id,
            'service_type_code' => ProductType::VPS,
            'name' => 'Upload Security Product '.$suffix,
            'custom_display_name' => 'Upload Security Product '.$suffix,
            'product_type' => ProductType::VPS,
            'description' => '',
            'pricing' => ['monthly' => '10.00'],
            'setup_fee' => '0.00',
            'config_options' => [],
            'purchase_requires' => [],
            'stock' => 10,
            'status' => 1,
            'sort_order' => 1,
            'auto_setup' => 0,
        ]);

        return Service::query()->create([
            'user_id' => (int) $user->id,
            'product_id' => (int) $product->id,
            'name' => 'Upload Security Service '.$suffix,
            'domain' => 'upload-'.$suffix.'.example.test',
            'billing_cycle' => 'monthly',
            'amount' => '10.00',
            'status' => ServiceStatus::ACTIVE,
            'provision_data' => [],
            'expires_at' => now()->addMonth(),
            'auto_renew' => 0,
        ]);
    }
}
