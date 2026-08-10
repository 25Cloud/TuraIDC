<?php

namespace App\Services\Content;

use App\Models\MediaFile;
use App\Support\UploadedImage;
use App\Support\UploadUrl;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MediaFileService
{
    public const HERO_VIDEO_GROUP = 'hero-videos';

    public const MEDIA_ROOT = 'media';

    public const SITE_SETTINGS_GROUP = 'site-settings';

    public const HERO_VIDEO_DEFAULT_DIRECTORY = self::MEDIA_ROOT;

    private const LEGACY_HERO_VIDEO_DIRECTORY = 'uploads/hero-videos';

    private const LEGACY_MEDIA_LIBRARY_HERO_VIDEO_DIRECTORY = 'uploads/media/videos/hero-videos';

    private const VIDEO_EXTENSIONS = ['mp4', 'webm', 'ogg', 'mov', 'm4v'];

    private const IMAGE_MIME_EXTENSION_MAP = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const VIDEO_MIME_EXTENSION_MAP = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/ogg' => 'ogg',
        'video/quicktime' => 'mov',
        'video/x-m4v' => 'm4v',
    ];

    private const DIRECTORY_EXTENSION_MIME_MAP = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'mp4' => 'video/mp4',
        'webm' => 'video/webm',
        'ogg' => 'video/ogg',
        'mov' => 'video/quicktime',
        'm4v' => 'video/x-m4v',
    ];

    public function list(array $filters, int $perPage = 24): LengthAwarePaginator
    {
        $query = MediaFile::query()->orderByDesc('id');

        if (! empty($filters['group'])) {
            $query->where('group', (string) $filters['group']);
        }

        if (! empty($filters['keyword'])) {
            $query->where('filename', 'like', '%'.trim($filters['keyword']).'%');
        }

        $type = strtolower(trim((string) ($filters['type'] ?? '')));
        if ($type === 'image') {
            $query->where('mime_type', 'like', 'image/%');
        } elseif ($type === 'video') {
            $query->where('mime_type', 'like', 'video/%');
        }

        return $query->paginate($perPage);
    }

    public function upload(UploadedFile $file, int $adminId, string $group = 'content'): MediaFile
    {
        $group = UploadedImage::group($group);
        $media = $this->detectMedia($file);
        $directory = public_path(self::MEDIA_ROOT);
        File::ensureDirectoryExists($directory);

        $this->guardDiskSpace($directory, (int) $file->getSize());

        $filename = sprintf('%s_%s_%s.%s', $media['prefix'], now()->format('His'), Str::random(8), $media['extension']);
        $originalName = UploadedImage::originalName($file, $filename);

        $file->move($directory, $filename);

        $relativePath = self::relativePath($filename);
        $fullPath = $directory.DIRECTORY_SEPARATOR.$filename;

        $width = null;
        $height = null;
        if ($media['type'] === 'image' && function_exists('getimagesize') && @is_file($fullPath)) {
            $info = @getimagesize($fullPath);
            if ($info) {
                $width = $info[0];
                $height = $info[1];
            }
        }

        $mediaFile = MediaFile::query()->create([
            'filename' => $originalName,
            'path' => $relativePath,
            'url' => UploadUrl::resolve($relativePath),
            'mime_type' => $media['mime_type'],
            'size' => @filesize($fullPath) ?: 0,
            'width' => $width,
            'height' => $height,
            'group' => $group,
            'uploaded_by' => $adminId,
        ]);

        $this->bustHeroVideoCache();

        return $mediaFile;
    }

    private const CACHE_KEY_HERO_VIDEOS = 'media:hero-videos:list';

    private const CACHE_TTL_HERO_VIDEOS = 300; // 5分钟

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listHeroVideos(?string $keyword = null): array
    {
        $keyword = strtolower(trim((string) $keyword));

        $allVideos = Cache::remember(
            self::CACHE_KEY_HERO_VIDEOS,
            now()->addSeconds(self::CACHE_TTL_HERO_VIDEOS),
            function (): array {
                $databaseItems = MediaFile::query()
                    ->where('group', self::HERO_VIDEO_GROUP)
                    ->where('mime_type', 'like', 'video/%')
                    ->orderByDesc('id')
                    ->get()
                    ->map(fn (MediaFile $item) => $this->toMediaArray($item))
                    ->all();

                $databasePaths = collect($databaseItems)
                    ->map(fn (array $item) => (string) ($item['path'] ?? ''))
                    ->filter()
                    ->values()
                    ->all();

                return collect([...$databaseItems, ...$this->scanHeroVideoFiles($databasePaths)])
                    ->sortBy([
                        ['created_at', 'desc'],
                        ['filename', 'asc'],
                    ])
                    ->values()
                    ->all();
            }
        );

        if ($keyword === '') {
            return $allVideos;
        }

        return collect($allVideos)
            ->filter(fn (array $item): bool => str_contains(strtolower((string) ($item['filename'] ?? '')), $keyword))
            ->values()
            ->all();
    }

    public function delete(MediaFile $mediaFile): void
    {
        $diskPath = $this->resolvePublicUploadPath((string) $mediaFile->path);

        if ($diskPath !== null && @is_file($diskPath)) {
            @unlink($diskPath);
        }

        $mediaFile->delete();

        $this->bustHeroVideoCache();
    }

    /**
     * 检查媒体文件是否被其他内容引用。
     *
     * @return array<int, string> 引用描述列表，为空表示无引用
     */
    public function checkReferences(MediaFile $mediaFile): array
    {
        $refs = [];
        $path = (string) $mediaFile->path;
        $url = (string) $mediaFile->url;

        // 检查 settings 表
        $settings = DB::table('settings')
            ->where(function ($query) use ($path, $url) {
                $query->where('item_value', 'like', "%{$path}%");
                if ($url !== '') {
                    $query->orWhere('item_value', 'like', "%{$url}%");
                }
            })
            ->get(['group_key', 'item_key']);

        foreach ($settings as $s) {
            $refs[] = "系统设置 {$s->group_key}.{$s->item_key}";
        }

        // 检查内容文章封面
        $articleCount = DB::table('content_articles')
            ->where(function ($query) use ($path, $url) {
                $query->where('cover_image', 'like', "%{$path}%");
                if ($url !== '') {
                    $query->orWhere('cover_image', 'like', "%{$url}%");
                }
            })
            ->count();

        if ($articleCount > 0) {
            $refs[] = "{$articleCount} 篇文章封面";
        }

        return $refs;
    }

    /**
     * @return array{created: int, skipped: int, total: int, unrecognized: array<int, string>}
     */
    public function reindexMediaDirectory(int $adminId): array
    {
        $directory = public_path(self::MEDIA_ROOT);
        if (! @is_dir($directory)) {
            return ['created' => 0, 'skipped' => 0, 'total' => 0, 'unrecognized' => []];
        }

        $existingPaths = MediaFile::query()
            ->where('path', 'like', '/'.self::MEDIA_ROOT.'/%')
            ->pluck('path')
            ->flip()
            ->all();

        $created = 0;
        $skipped = 0;
        $unrecognized = [];

        foreach (File::files($directory) as $file) {
            $metadata = $this->buildDirectoryMediaMetadata($file->getPathname());
            if ($metadata === null) {
                $unrecognized[] = $file->getFilename();

                continue;
            }

            $relativePath = self::relativePath($file->getFilename());
            if (isset($existingPaths[$relativePath])) {
                $skipped++;

                continue;
            }

            MediaFile::query()->create([
                'filename' => $file->getFilename(),
                'path' => $relativePath,
                'url' => UploadUrl::resolve($relativePath),
                'mime_type' => $metadata['mime_type'],
                'size' => $metadata['size'],
                'width' => $metadata['width'],
                'height' => $metadata['height'],
                'group' => $metadata['group'],
                'uploaded_by' => $adminId,
            ]);

            $existingPaths[$relativePath] = true;
            $created++;
        }

        $result = [
            'created' => $created,
            'skipped' => $skipped,
            'total' => $created + $skipped,
            'unrecognized' => $unrecognized,
        ];

        if ($created > 0) {
            $this->bustHeroVideoCache();
        }

        return $result;
    }

    /**
     * @return array{type: string, directory: string, extension: string, mime_type: string, prefix: string}
     */
    private function detectMedia(UploadedFile $file): array
    {
        $mimeType = strtolower(trim((string) $file->getMimeType()));

        if (isset(self::IMAGE_MIME_EXTENSION_MAP[$mimeType])) {
            return [
                'type' => 'image',
                'directory' => 'images',
                'extension' => self::IMAGE_MIME_EXTENSION_MAP[$mimeType],
                'mime_type' => $mimeType,
                'prefix' => 'img',
            ];
        }

        if (isset(self::VIDEO_MIME_EXTENSION_MAP[$mimeType])) {
            return [
                'type' => 'video',
                'directory' => 'videos',
                'extension' => self::VIDEO_MIME_EXTENSION_MAP[$mimeType],
                'mime_type' => $mimeType,
                'prefix' => 'vid',
            ];
        }

        throw new InvalidArgumentException('Unsupported media type.');
    }

    /**
     * @return array<string, mixed>
     */
    private function toMediaArray(MediaFile $item): array
    {
        return [
            'id' => $item->id,
            'filename' => $item->filename,
            'path' => $item->path,
            'url' => UploadUrl::resolve($item->path),
            'mime_type' => $item->mime_type,
            'size' => $item->size,
            'width' => $item->width,
            'height' => $item->height,
            'group' => $item->group,
            'type' => $this->normalizeMediaType((string) $item->mime_type),
            'created_at' => optional($item->created_at)?->toDateTimeString(),
        ];
    }

    /**
     * @param  array<int, string>  $knownPaths
     * @return array<int, array<string, mixed>>
     */
    private function scanHeroVideoFiles(array $knownPaths = []): array
    {
        $items = collect();
        $sequence = 0;

        foreach ([self::LEGACY_HERO_VIDEO_DIRECTORY, self::LEGACY_MEDIA_LIBRARY_HERO_VIDEO_DIRECTORY] as $directory) {
            $absoluteDirectory = public_path($directory);
            if (! @is_dir($absoluteDirectory)) {
                continue;
            }

            foreach (File::allFiles($absoluteDirectory) as $file) {
                $extension = strtolower($file->getExtension());
                if (! in_array($extension, self::VIDEO_EXTENSIONS, true)) {
                    continue;
                }

                $relativePath = $this->toRelativeUploadPath($file->getPathname());
                if ($relativePath === null || in_array($relativePath, $knownPaths, true)) {
                    continue;
                }

                $sequence++;
                $items->push([
                    'id' => 'hero-video-legacy-'.$sequence.'-'.substr(md5($relativePath), 0, 8),
                    'filename' => $file->getFilename(),
                    'path' => $relativePath,
                    'url' => UploadUrl::resolve($relativePath),
                    'mime_type' => $this->guessVideoMimeType($extension),
                    'size' => $file->getSize(),
                    'width' => null,
                    'height' => null,
                    'group' => self::HERO_VIDEO_GROUP,
                    'type' => 'video',
                    'created_at' => date('Y-m-d H:i:s', $file->getMTime()),
                ]);
            }
        }

        return $items->all();
    }

    private function guessVideoMimeType(string $extension): string
    {
        return match (strtolower($extension)) {
            'webm' => 'video/webm',
            'ogg' => 'video/ogg',
            'mov' => 'video/quicktime',
            'm4v' => 'video/x-m4v',
            default => 'video/mp4',
        };
    }

    private function normalizeMediaType(string $mimeType): string
    {
        return str_starts_with($mimeType, 'video/') ? 'video' : 'image';
    }

    /**
     * @return array{mime_type: string, size: int, width: int|null, height: int|null, group: string}|null
     */
    private function buildDirectoryMediaMetadata(string $absolutePath): ?array
    {
        if (! @is_file($absolutePath)) {
            return null;
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
        $mimeType = self::DIRECTORY_EXTENSION_MIME_MAP[$extension] ?? null;
        if ($mimeType === null) {
            return null;
        }

        $width = null;
        $height = null;
        if (str_starts_with($mimeType, 'image/') && function_exists('getimagesize')) {
            $info = @getimagesize($absolutePath);
            if ($info) {
                $width = $info[0];
                $height = $info[1];
            }
        }

        return [
            'mime_type' => $mimeType,
            'size' => @filesize($absolutePath) ?: 0,
            'width' => $width,
            'height' => $height,
            'group' => str_starts_with($mimeType, 'video/') ? self::HERO_VIDEO_GROUP : 'content',
        ];
    }

    public static function relativePath(string $filename): string
    {
        return '/'.self::MEDIA_ROOT.'/'.ltrim($filename, '/');
    }

    private function toRelativeUploadPath(string $absolutePath): ?string
    {
        $publicRoot = str_replace('\\', '/', public_path());
        $normalized = str_replace('\\', '/', $absolutePath);

        if (! str_starts_with($normalized, $publicRoot.'/')) {
            return null;
        }

        return '/'.ltrim(substr($normalized, strlen($publicRoot) + 1), '/');
    }

    private function resolvePublicUploadPath(string $path): ?string
    {
        $normalized = ltrim(str_replace('\\', '/', trim($path)), '/');
        $segments = explode('/', $normalized);

        if (in_array('..', $segments, true) || in_array('.', $segments, true)) {
            return null;
        }

        $root = match (true) {
            str_starts_with($normalized, self::MEDIA_ROOT.'/') => public_path(self::MEDIA_ROOT),
            str_starts_with($normalized, 'uploads/') => public_path('uploads'),
            default => null,
        };

        if ($root === null) {
            return null;
        }

        $absolutePath = public_path(str_replace('/', DIRECTORY_SEPARATOR, $normalized));
        $realPath = realpath($absolutePath);
        $realRoot = realpath($root);

        if ($realRoot === false || $realPath === false) {
            return null;
        }

        $realRoot = str_replace('\\', '/', $realRoot);
        $realPath = str_replace('\\', '/', $realPath);

        if ($realPath !== $realRoot && ! str_starts_with($realPath, $realRoot.'/')) {
            return null;
        }

        return $realPath;
    }

    private function guardDiskSpace(string $directory, int $fileSize): void
    {
        $freeSpace = @disk_free_space($directory);

        if ($freeSpace === false) {
            return; // 无法获取磁盘信息时放行
        }

        $minFree = max($fileSize * 2, 50 * 1024 * 1024); // 至少预留 50MB

        if ($freeSpace < $minFree) {
            throw new \RuntimeException('服务器磁盘空间不足，请联系管理员');
        }
    }

    private function bustHeroVideoCache(): void
    {
        Cache::forget(self::CACHE_KEY_HERO_VIDEOS);
    }
}
