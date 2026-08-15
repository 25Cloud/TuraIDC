<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\Role;
use App\Services\Content\MediaFileService;
use App\Services\Ticket\TicketService;
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
}
