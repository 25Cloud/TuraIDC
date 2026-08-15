<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MediaFile;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MediaOrganizeUploadsCommandTest extends TestCase
{
    private array $pathsToDelete = [];

    private array $mediaFileIds = [];

    protected function tearDown(): void
    {
        foreach ($this->pathsToDelete as $path) {
            if (@is_file($path)) {
                File::delete($path);
            }
        }

        if ($this->mediaFileIds !== []) {
            DB::table('media_files')->whereIn('id', $this->mediaFileIds)->delete();
        }

        DB::table('settings')
            ->whereIn('group_key', ['basic', 'home_hero'])
            ->whereIn('item_key', ['site_logo', 'site_favicon', 'slides'])
            ->delete();

        parent::tearDown();
    }

    public function test_command_syncs_legacy_uploads_into_flat_media_directory_and_updates_references(): void
    {
        $imageOldRelative = '/uploads/content/20260630/cmd-cover.jpg';
        $imageOldAbsolute = public_path(ltrim($imageOldRelative, '/'));
        File::ensureDirectoryExists(dirname($imageOldAbsolute));
        File::put($imageOldAbsolute, 'legacy-image');
        $this->pathsToDelete[] = $imageOldAbsolute;

        $image = MediaFile::query()->create([
            'filename' => 'cmd-cover.jpg',
            'path' => $imageOldRelative,
            'url' => $imageOldRelative,
            'mime_type' => 'image/jpeg',
            'size' => 12,
            'group' => 'content',
            'uploaded_by' => 0,
        ]);
        $this->mediaFileIds[] = (int) $image->id;

        Setting::setValue('basic', 'site_logo', $imageOldRelative);

        $videoOldRelative = '/uploads/hero-videos/cmd-hero.mp4';
        $videoOldAbsolute = public_path(ltrim($videoOldRelative, '/'));
        File::ensureDirectoryExists(dirname($videoOldAbsolute));
        File::put($videoOldAbsolute, 'legacy-video');
        $this->pathsToDelete[] = $videoOldAbsolute;

        $legacyLogoRelative = '/uploads/logo/cmd-logo.svg';
        $legacyLogoAbsolute = public_path(ltrim($legacyLogoRelative, '/'));
        File::ensureDirectoryExists(dirname($legacyLogoAbsolute));
        File::put($legacyLogoAbsolute, '<svg xmlns="http://www.w3.org/2000/svg"></svg>');
        $this->pathsToDelete[] = $legacyLogoAbsolute;

        Setting::setValue('basic', 'site_favicon', $legacyLogoRelative);

        Setting::setValue('home_hero', 'slides', json_encode([
            [
                'key' => 'cmd',
                'rail_title' => '命令测试',
                'title' => '命令测试视频',
                'desc' => '用于验证路径整理命令',
                'primary_text' => '查看',
                'primary_path' => '/products',
                'secondary_text' => '返回',
                'secondary_path' => '/about',
                'shape' => 'computer',
                'video' => $videoOldRelative,
                'ribbon' => '',
                'ribbon_type' => 'new',
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $this->artisan('media:organize-uploads')
            ->assertExitCode(0);

        $image->refresh();
        $this->assertSame('/media/cmd-cover.jpg', $image->path);
        $this->assertFileExists(public_path('media/cmd-cover.jpg'));
        $this->assertFileDoesNotExist($imageOldAbsolute);
        $this->pathsToDelete[] = public_path('media/cmd-cover.jpg');

        $this->assertSame('/media/cmd-cover.jpg', Setting::getValue('basic', 'site_logo'));
        $this->assertSame('/media/cmd-logo.svg', Setting::getValue('basic', 'site_favicon'));
        $this->assertFileExists(public_path('media/cmd-logo.svg'));
        $this->assertFileDoesNotExist($legacyLogoAbsolute);
        $this->pathsToDelete[] = public_path('media/cmd-logo.svg');
        $this->assertFileExists(public_path('media/cmd-cover.jpg'));

        $heroVideo = MediaFile::query()
            ->where('group', 'hero-videos')
            ->where('filename', 'cmd-hero.mp4')
            ->first();

        $this->assertNotNull($heroVideo);
        $this->mediaFileIds[] = (int) $heroVideo->id;
        $this->assertSame('/media/cmd-hero.mp4', $heroVideo->path);
        $this->assertFileExists(public_path('media/cmd-hero.mp4'));
        $this->assertFileDoesNotExist($videoOldAbsolute);
        $this->pathsToDelete[] = public_path('media/cmd-hero.mp4');

        $slides = json_decode((string) Setting::getValue('home_hero', 'slides', '[]'), true);
        $this->assertSame('/media/cmd-hero.mp4', $slides[0]['video'] ?? '');
        $this->assertDirectoryDoesNotExist(public_path('uploads/content/20260630'));
        $this->assertDirectoryDoesNotExist(public_path('uploads/hero-videos'));
        $this->assertDirectoryDoesNotExist(public_path('uploads/logo'));
    }

    public function test_command_renames_conflicting_files_when_flattening_into_media_directory(): void
    {
        $contentRelative = '/uploads/content/20260630/shared.jpg';
        $contentAbsolute = public_path(ltrim($contentRelative, '/'));
        File::ensureDirectoryExists(dirname($contentAbsolute));
        File::put($contentAbsolute, 'content-image');
        $this->pathsToDelete[] = $contentAbsolute;

        $image = MediaFile::query()->create([
            'filename' => 'shared.jpg',
            'path' => $contentRelative,
            'url' => $contentRelative,
            'mime_type' => 'image/jpeg',
            'size' => 12,
            'group' => 'content',
            'uploaded_by' => 0,
        ]);
        $this->mediaFileIds[] = (int) $image->id;

        $logoRelative = '/uploads/logo/shared.jpg';
        $logoAbsolute = public_path(ltrim($logoRelative, '/'));
        File::ensureDirectoryExists(dirname($logoAbsolute));
        File::put($logoAbsolute, 'logo-image');
        $this->pathsToDelete[] = $logoAbsolute;

        Setting::setValue('basic', 'site_logo', $logoRelative);

        $this->artisan('media:organize-uploads')
            ->assertExitCode(0);

        $image->refresh();
        $this->assertSame('/media/shared.jpg', $image->path);
        $this->assertSame('content-image', (string) file_get_contents(public_path('media/shared.jpg')));
        $this->pathsToDelete[] = public_path('media/shared.jpg');

        $siteLogo = (string) Setting::getValue('basic', 'site_logo');
        $this->assertMatchesRegularExpression('#^/media/shared-[a-f0-9]{8}\.jpg$#', $siteLogo);
        $this->assertSame('logo-image', (string) file_get_contents(public_path(ltrim($siteLogo, '/'))));
        $this->pathsToDelete[] = public_path(ltrim($siteLogo, '/'));
    }

    public function test_command_repairs_flat_media_rows_that_point_to_missing_hashed_files(): void
    {
        $actualRelative = '/media/repair-hero.mp4';
        $actualAbsolute = public_path(ltrim($actualRelative, '/'));
        File::ensureDirectoryExists(dirname($actualAbsolute));
        File::put($actualAbsolute, 'hero-video');
        $this->pathsToDelete[] = $actualAbsolute;

        $media = MediaFile::query()->create([
            'filename' => 'repair-hero.mp4',
            'path' => '/media/repair-hero-055b20d3.mp4',
            'url' => '/media/repair-hero-055b20d3.mp4',
            'mime_type' => 'video/mp4',
            'size' => 10,
            'group' => 'hero-videos',
            'uploaded_by' => 0,
        ]);
        $this->mediaFileIds[] = (int) $media->id;

        $this->artisan('media:organize-uploads')
            ->assertExitCode(0);

        $media->refresh();
        $this->assertSame('/media/repair-hero.mp4', $media->path);
    }
}
