<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminUser;
use App\Models\MediaFile;
use App\Models\Role;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminMediaLibraryControllerTest extends TestCase
{
    private const REINDEX_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'svg', 'mp4', 'webm', 'ogg', 'mov', 'm4v'];

    private array $mediaFileIds = [];

    private array $diskPaths = [];

    protected function tearDown(): void
    {
        if ($this->mediaFileIds !== []) {
            DB::table('media_files')->whereIn('id', $this->mediaFileIds)->delete();
        }

        foreach ($this->diskPaths as $path) {
            if (is_string($path) && @is_file($path)) {
                @unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_media_index_can_filter_by_type(): void
    {
        Sanctum::actingAs($this->createAdmin());

        $suffix = bin2hex(random_bytes(4));

        $image = MediaFile::query()->create([
            'filename' => 'filter-'.$suffix.'-cover.jpg',
            'path' => '/media/filter-'.$suffix.'-cover.jpg',
            'url' => '/media/filter-'.$suffix.'-cover.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 1234,
            'group' => 'content',
            'uploaded_by' => 1,
        ]);
        $video = MediaFile::query()->create([
            'filename' => 'filter-'.$suffix.'-hero.mp4',
            'path' => '/media/filter-'.$suffix.'-hero.mp4',
            'url' => '/media/filter-'.$suffix.'-hero.mp4',
            'mime_type' => 'video/mp4',
            'size' => 2048,
            'group' => 'hero-videos',
            'uploaded_by' => 1,
        ]);

        $this->mediaFileIds = [(int) $image->id, (int) $video->id];

        $this->getJson('/api/v2/admin/media-files?type=video&page_size=50&keyword=filter-'.$suffix)
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonCount(1, 'data.list')
            ->assertJsonPath('data.list.0.mime_type', 'video/mp4')
            ->assertJsonPath('data.list.0.group', 'hero-videos');
    }

    public function test_media_reindex_imports_files_from_flat_media_directory(): void
    {
        Sanctum::actingAs($this->createAdmin());

        $suffix = bin2hex(random_bytes(4));
        $filename = 'manual-'.$suffix.'.png';
        $absolutePath = public_path('media/'.$filename);
        $relativePath = '/media/'.$filename;
        $existingPaths = array_flip(MediaFile::query()->pluck('path')->all());

        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+nmvsAAAAASUVORK5CYII=', true));
        $this->diskPaths[] = $absolutePath;

        $supportedFiles = collect(File::files(public_path('media')))
            ->filter(fn ($file) => in_array(strtolower($file->getExtension()), self::REINDEX_EXTENSIONS, true))
            ->values();
        $expectedCreated = $supportedFiles
            ->filter(fn ($file) => ! isset($existingPaths['/media/'.$file->getFilename()]))
            ->count();
        $expectedTotal = $supportedFiles->count();
        $beforeIds = MediaFile::query()->pluck('id')->all();

        $this->postJson('/api/v2/admin/media-file-reindexes')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.media.created', $expectedCreated)
            ->assertJsonPath('data.detail.media.skipped', $expectedTotal - $expectedCreated)
            ->assertJsonPath('data.detail.media.total', $expectedTotal);

        $newIds = MediaFile::query()
            ->whereNotIn('id', $beforeIds)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $this->mediaFileIds = [...$this->mediaFileIds, ...$newIds];

        $mediaFile = MediaFile::query()->where('path', $relativePath)->first();

        $this->assertNotNull($mediaFile);
        $this->assertSame($filename, $mediaFile->filename);
        $this->assertSame('image/png', $mediaFile->mime_type);
        $this->assertSame('content', $mediaFile->group);
        $this->assertSame(1, (int) $mediaFile->width);
        $this->assertSame(1, (int) $mediaFile->height);

        $this->postJson('/api/v2/admin/media-file-reindexes')
            ->assertOk()
            ->assertJsonPath('code', 0)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.detail.media.created', 0)
            ->assertJsonPath('data.detail.media.skipped', $expectedTotal)
            ->assertJsonPath('data.detail.media.total', $expectedTotal);
    }

    private function createAdmin(): AdminUser
    {
        $suffix = bin2hex(random_bytes(4));

        $role = Role::query()->create([
            'name' => 'admin-media-library-'.$suffix,
            'label' => 'Admin Media Library',
            'permissions' => [AdminPermissions::ALL],
        ]);

        return AdminUser::query()->create([
            'username' => 'admin-media-library-'.$suffix,
            'password' => 'Temp@123456',
            'role_id' => (int) $role->id,
            'nickname' => 'Admin Media Library',
            'email' => 'admin-media-library-'.$suffix.'@example.com',
            'status' => 1,
        ]);
    }
}
