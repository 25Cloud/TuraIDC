<?php

declare(strict_types=1);

namespace App\Services\Content;

use App\Models\MediaFile;
use App\Models\Setting;
use App\Support\UploadUrl;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class MediaLibraryOrganizer
{
    private const LEGACY_SCAN_ROOTS = [
        'uploads/media',
        'uploads/content',
        'uploads/logo',
        'uploads/site-settings',
        'uploads/hero-videos',
    ];

    private const CLEANUP_ROOTS = [
        'uploads/media',
        'uploads/content',
        'uploads/logo',
        'uploads/site-settings',
        'uploads/hero-videos',
    ];

    private const MEDIA_EXTENSIONS = [
        'jpg',
        'jpeg',
        'png',
        'webp',
        'svg',
        'mp4',
        'webm',
        'ogg',
        'mov',
        'm4v',
    ];

    private const HERO_VIDEO_LEGACY_PREFIXES = [
        '/uploads/hero-videos/',
        '/uploads/media/videos/hero-videos/',
    ];

    /**
     * @return array<string, int>
     */
    public function organize(bool $dryRun = false): array
    {
        $pathMap = [];
        $claimedTargets = [];
        $mediaRowTargets = [];

        foreach (MediaFile::query()->orderBy('id')->get() as $mediaFile) {
            $sourcePath = $this->normalizeRelativePath((string) $mediaFile->path);
            $targetPath = $this->assignTargetPath($sourcePath, $pathMap, $claimedTargets);
            if ($targetPath === null) {
                continue;
            }

            $mediaRowTargets[(int) $mediaFile->id] = [
                'source' => $sourcePath,
                'target' => $targetPath,
            ];
        }

        foreach ($this->collectLegacyManagedFiles() as $sourcePath) {
            $this->assignTargetPath($sourcePath, $pathMap, $claimedTargets);
        }

        foreach ($this->collectLegacySettingAssetPaths() as $sourcePath) {
            $this->assignTargetPath($sourcePath, $pathMap, $claimedTargets);
        }

        foreach ($this->collectLegacyArticlePaths() as $sourcePath) {
            $this->assignTargetPath($sourcePath, $pathMap, $claimedTargets);
        }

        $movedFiles = 0;
        $readyPathMap = [];
        foreach ($pathMap as $sourcePath => $targetPath) {
            $syncResult = $this->syncFile($sourcePath, $targetPath, $dryRun);
            if ($syncResult['ready'] && $syncResult['moved']) {
                $movedFiles++;
            }
            if ($syncResult['ready']) {
                $readyPathMap[$sourcePath] = $targetPath;
            }
        }

        $updatedMediaRows = 0;
        $createdHeroVideoRows = 0;
        $updatedSettingRows = 0;
        $updatedArticleRows = 0;
        $removedEmptyDirectories = 0;

        if (! $dryRun) {
            foreach ($mediaRowTargets as $mediaId => $plan) {
                $sourcePath = $plan['source'] ?? null;
                $targetPath = $plan['target'] ?? null;
                if (! is_string($sourcePath) || ! is_string($targetPath) || ($readyPathMap[$sourcePath] ?? null) !== $targetPath) {
                    continue;
                }

                MediaFile::query()
                    ->whereKey($mediaId)
                    ->update([
                        'path' => $targetPath,
                        'url' => UploadUrl::resolve($targetPath),
                    ]);
                $updatedMediaRows++;
            }

            $createdHeroVideoRows = $this->createMissingHeroVideoRows($readyPathMap);
            $updatedSettingRows = $this->updateSettingReferences($readyPathMap);
            $updatedArticleRows = $this->updateContentArticleReferences($readyPathMap);

            $repairMap = $this->buildFlatMediaRepairMap();
            if ($repairMap !== []) {
                $updatedMediaRows += $this->repairMediaFileRows($repairMap);
                $updatedSettingRows += $this->updateSettingReferences($repairMap);
                $updatedArticleRows += $this->updateContentArticleReferences($repairMap);
            }

            $removedEmptyDirectories = $this->removeEmptyLegacyDirectories();

            Cache::forget('site:home:hero');
            Cache::forget('site:home:4:50:4');
        }

        return [
            'copied_files' => $movedFiles,
            'updated_media_rows' => $updatedMediaRows,
            'created_hero_video_rows' => $createdHeroVideoRows,
            'updated_setting_rows' => $updatedSettingRows,
            'updated_article_rows' => $updatedArticleRows,
            'removed_empty_directories' => $removedEmptyDirectories,
        ];
    }

    /**
     * @param  array<string, string>  $pathMap
     * @param  array<string, string>  $claimedTargets
     */
    private function assignTargetPath(?string $sourcePath, array &$pathMap, array &$claimedTargets): ?string
    {
        if ($sourcePath === null || ! $this->needsMigration($sourcePath)) {
            return null;
        }

        if (isset($pathMap[$sourcePath])) {
            return $pathMap[$sourcePath];
        }

        $basename = basename($sourcePath);
        if (! $this->isSupportedMediaFilename($basename)) {
            return null;
        }

        $candidate = MediaFileService::relativePath($basename);
        $attempt = 0;

        while ($this->targetClaimedByAnotherSource($candidate, $sourcePath, $claimedTargets)) {
            $candidate = MediaFileService::relativePath($this->appendCollisionSuffix($basename, $sourcePath, $attempt));
            $attempt++;
        }

        $pathMap[$sourcePath] = $candidate;
        $claimedTargets[$candidate] = $sourcePath;

        return $candidate;
    }

    /**
     * @return array{ready: bool, moved: bool}
     */
    private function syncFile(string $sourceRelativePath, string $targetRelativePath, bool $dryRun): array
    {
        $sourceAbsolutePath = public_path(ltrim($sourceRelativePath, '/'));
        $targetAbsolutePath = public_path(ltrim($targetRelativePath, '/'));

        if ($sourceAbsolutePath === $targetAbsolutePath) {
            return ['ready' => true, 'moved' => false];
        }

        if (@is_file($targetAbsolutePath)) {
            if (! $dryRun && @is_file($sourceAbsolutePath)) {
                File::delete($sourceAbsolutePath);
            }

            return ['ready' => true, 'moved' => false];
        }

        if (! @is_file($sourceAbsolutePath)) {
            return ['ready' => false, 'moved' => false];
        }

        if ($dryRun) {
            return ['ready' => true, 'moved' => true];
        }

        File::ensureDirectoryExists(dirname($targetAbsolutePath));
        File::copy($sourceAbsolutePath, $targetAbsolutePath);

        if (! @is_file($targetAbsolutePath)) {
            return ['ready' => false, 'moved' => false];
        }

        File::delete($sourceAbsolutePath);

        return ['ready' => true, 'moved' => true];
    }

    /**
     * @return array<int, string>
     */
    private function collectLegacyManagedFiles(): array
    {
        $files = [];

        foreach (self::LEGACY_SCAN_ROOTS as $root) {
            $absoluteRoot = public_path($root);
            if (! @is_dir($absoluteRoot)) {
                continue;
            }

            foreach (File::allFiles($absoluteRoot) as $file) {
                $relativePath = $this->toRelativePublicPath($file->getPathname());
                if ($relativePath === null || ! $this->isSupportedMediaFilename($file->getFilename())) {
                    continue;
                }

                $files[$relativePath] = $relativePath;
            }
        }

        return array_values($files);
    }

    /**
     * @return array<int, string>
     */
    private function collectLegacySettingAssetPaths(): array
    {
        $paths = [];

        foreach (DB::table('settings')->select(['item_value'])->get() as $row) {
            $currentValue = trim((string) ($row->item_value ?? ''));
            if ($currentValue === '' || ! str_contains($currentValue, '/')) {
                continue;
            }

            foreach ($this->extractManagedPathsFromText($currentValue) as $normalized) {
                if ($this->needsMigration($normalized) && $this->isSupportedMediaFilename(basename($normalized))) {
                    $paths[$normalized] = $normalized;
                }
            }
        }

        return array_values($paths);
    }

    /**
     * @return array<int, string>
     */
    private function collectLegacyArticlePaths(): array
    {
        $paths = [];

        foreach (DB::table('content_articles')->whereNotNull('cover_image')->pluck('cover_image') as $coverImage) {
            $normalized = $this->normalizeRelativePath((string) (parse_url((string) $coverImage, PHP_URL_PATH) ?: $coverImage));
            if ($normalized !== null && $this->needsMigration($normalized) && $this->isSupportedMediaFilename(basename($normalized))) {
                $paths[$normalized] = $normalized;
            }
        }

        return array_values($paths);
    }

    /**
     * @param  array<string, string>  $pathMap
     */
    private function updateSettingReferences(array $pathMap): int
    {
        if ($pathMap === []) {
            return 0;
        }

        $updatedRows = 0;
        $rows = DB::table('settings')
            ->select(['group_key', 'item_key', 'item_value'])
            ->get();

        foreach ($rows as $row) {
            $currentValue = $row->item_value;
            if (! is_string($currentValue) || ! str_contains($currentValue, '/')) {
                continue;
            }

            $updatedValue = str_replace(array_keys($pathMap), array_values($pathMap), $currentValue);
            if ($updatedValue === $currentValue) {
                continue;
            }

            Setting::setValue((string) $row->group_key, (string) $row->item_key, $updatedValue);
            $updatedRows++;
        }

        return $updatedRows;
    }

    /**
     * @param  array<string, string>  $pathMap
     */
    private function updateContentArticleReferences(array $pathMap): int
    {
        if ($pathMap === []) {
            return 0;
        }

        $updatedRows = 0;
        $rows = DB::table('content_articles')
            ->whereNotNull('cover_image')
            ->get(['id', 'cover_image']);

        foreach ($rows as $row) {
            $currentValue = (string) ($row->cover_image ?? '');
            if ($currentValue === '' || ! str_contains($currentValue, '/')) {
                continue;
            }

            $updatedValue = str_replace(array_keys($pathMap), array_values($pathMap), $currentValue);
            if ($updatedValue === $currentValue) {
                continue;
            }

            DB::table('content_articles')
                ->where('id', $row->id)
                ->update(['cover_image' => $updatedValue]);
            $updatedRows++;
        }

        return $updatedRows;
    }

    /**
     * @param  array<string, string>  $pathMap
     */
    private function createMissingHeroVideoRows(array $pathMap): int
    {
        $createdRows = 0;

        foreach ($pathMap as $sourcePath => $targetPath) {
            if (! $this->isLegacyHeroVideoPath($sourcePath)) {
                continue;
            }

            $existing = MediaFile::query()
                ->where('group', MediaFileService::HERO_VIDEO_GROUP)
                ->where('path', $targetPath)
                ->first();

            if ($existing !== null) {
                continue;
            }

            MediaFile::query()->create([
                'filename' => basename($targetPath),
                'path' => $targetPath,
                'url' => UploadUrl::resolve($targetPath),
                'mime_type' => $this->guessVideoMimeType(pathinfo($targetPath, PATHINFO_EXTENSION)),
                'size' => @filesize(public_path(ltrim($targetPath, '/'))) ?: 0,
                'width' => null,
                'height' => null,
                'group' => MediaFileService::HERO_VIDEO_GROUP,
                'uploaded_by' => 0,
            ]);
            $createdRows++;
        }

        return $createdRows;
    }

    /**
     * @return array<string, string>
     */
    private function buildFlatMediaRepairMap(): array
    {
        $repairMap = [];

        foreach (MediaFile::query()->pluck('path') as $path) {
            $this->appendFlatMediaRepairCandidate((string) $path, $repairMap);
        }

        foreach (DB::table('settings')->pluck('item_value') as $value) {
            $this->appendFlatMediaRepairCandidate((string) (parse_url((string) $value, PHP_URL_PATH) ?: $value), $repairMap);
        }

        foreach (DB::table('content_articles')->whereNotNull('cover_image')->pluck('cover_image') as $value) {
            $this->appendFlatMediaRepairCandidate((string) (parse_url((string) $value, PHP_URL_PATH) ?: $value), $repairMap);
        }

        return $repairMap;
    }

    /**
     * @param  array<string, string>  $repairMap
     */
    private function appendFlatMediaRepairCandidate(string $path, array &$repairMap): void
    {
        $normalized = $this->normalizeRelativePath($path);
        if ($normalized === null || ! str_starts_with($normalized, '/media/')) {
            return;
        }

        $repaired = $this->resolveExistingFlatMediaPath($normalized);
        if ($repaired !== null && $repaired !== $normalized) {
            $repairMap[$normalized] = $repaired;
        }
    }

    /**
     * @param  array<string, string>  $repairMap
     */
    private function repairMediaFileRows(array $repairMap): int
    {
        $updatedRows = 0;

        foreach ($repairMap as $sourcePath => $targetPath) {
            $updatedRows += MediaFile::query()
                ->where('path', $sourcePath)
                ->update([
                    'path' => $targetPath,
                    'url' => UploadUrl::resolve($targetPath),
                ]);
        }

        return $updatedRows;
    }

    private function removeEmptyLegacyDirectories(): int
    {
        $removed = 0;

        foreach (self::CLEANUP_ROOTS as $root) {
            $absoluteRoot = public_path($root);
            if (! @is_dir($absoluteRoot)) {
                continue;
            }

            $directories = collect(File::directories($absoluteRoot))
                ->flatMap(function (string $directory): array {
                    return [$directory, ...$this->collectChildDirectories($directory)];
                })
                ->sortByDesc(fn (string $directory) => substr_count(str_replace('\\', '/', $directory), '/'))
                ->values()
                ->all();

            foreach ([...$directories, $absoluteRoot] as $directory) {
                if (@is_dir($directory) && count(File::allFiles($directory)) === 0 && count(File::directories($directory)) === 0) {
                    File::deleteDirectory($directory);
                    $removed++;
                }
            }
        }

        return $removed;
    }

    /**
     * @return array<int, string>
     */
    private function collectChildDirectories(string $root): array
    {
        $directories = [];

        foreach (File::directories($root) as $directory) {
            $directories[] = $directory;
            $directories = [...$directories, ...$this->collectChildDirectories($directory)];
        }

        return $directories;
    }

    /**
     * @param  array<string, string>  $claimedTargets
     */
    private function targetClaimedByAnotherSource(string $targetPath, string $sourcePath, array $claimedTargets): bool
    {
        return isset($claimedTargets[$targetPath]) && $claimedTargets[$targetPath] !== $sourcePath;
    }

    private function appendCollisionSuffix(string $basename, string $sourcePath, int $attempt): string
    {
        $extension = pathinfo($basename, PATHINFO_EXTENSION);
        $name = pathinfo($basename, PATHINFO_FILENAME);
        $suffix = substr(md5($sourcePath.'#'.$attempt), 0, 8);

        return $extension === ''
            ? $name.'-'.$suffix
            : $name.'-'.$suffix.'.'.$extension;
    }

    private function isLegacyHeroVideoPath(string $path): bool
    {
        foreach (self::HERO_VIDEO_LEGACY_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return true;
            }
        }

        return false;
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

    private function needsMigration(string $path): bool
    {
        return str_starts_with($path, '/uploads/') || str_starts_with($path, '/uploads/media/');
    }

    private function resolveExistingFlatMediaPath(string $path): ?string
    {
        if (@is_file(public_path(ltrim($path, '/')))) {
            return $path;
        }

        $basename = basename($path);
        if (! preg_match('/^(.*)-[a-f0-9]{8}(\.[^.]+)$/i', $basename, $matches)) {
            return null;
        }

        $candidate = MediaFileService::relativePath(($matches[1] ?? '').($matches[2] ?? ''));

        return @is_file(public_path(ltrim($candidate, '/'))) ? $candidate : null;
    }

    private function normalizeRelativePath(string $path): ?string
    {
        $normalized = '/'.ltrim(str_replace('\\', '/', trim($path)), '/');

        if ($normalized === '/' || ! str_contains($normalized, '.')) {
            return null;
        }

        return $normalized;
    }

    private function isSupportedMediaFilename(string $filename): bool
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return $extension !== '' && in_array($extension, self::MEDIA_EXTENSIONS, true);
    }

    private function toRelativePublicPath(string $absolutePath): ?string
    {
        $publicRoot = str_replace('\\', '/', public_path());
        $normalized = str_replace('\\', '/', $absolutePath);

        if (! str_starts_with($normalized, $publicRoot.'/')) {
            return null;
        }

        return '/'.ltrim(substr($normalized, strlen($publicRoot) + 1), '/');
    }

    /**
     * @return array<int, string>
     */
    private function extractManagedPathsFromText(string $text): array
    {
        preg_match_all(
            '#/(?:uploads|media)/[^"\'\s]+?\.(?:jpg|jpeg|png|webp|svg|mp4|webm|ogg|mov|m4v)#i',
            $text,
            $matches
        );

        return array_values(array_unique(array_map(
            fn (string $path) => '/'.ltrim(str_replace('\\', '/', $path), '/'),
            $matches[0] ?? []
        )));
    }
}
