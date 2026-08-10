<?php

namespace App\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;

class SecureAsset
{
    private const ALLOWED_PREFIXES = [
        'private/tickets/',
        'private/referral/alipay/',
        'uploads/tickets/',
        'uploads/referral/alipay/',
    ];

    public static function isAllowedPath(string $path): bool
    {
        $normalized = ltrim(str_replace('\\', '/', trim($path)), '/');

        foreach (self::ALLOWED_PREFIXES as $prefix) {
            if (str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        return false;
    }

    public static function normalizePath(string $path): string
    {
        $normalized = trim(str_replace('\\', '/', $path));

        if ($normalized === '' || str_contains($normalized, "\0")) {
            throw new InvalidArgumentException('Invalid asset path.');
        }

        $normalized = ltrim($normalized, '/');
        $segments = explode('/', $normalized);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Invalid asset path.');
            }
        }

        $normalized = implode('/', $segments);

        if (! self::isAllowedPath($normalized)) {
            throw new InvalidArgumentException('Invalid asset path.');
        }

        return $normalized;
    }

    public static function absolutePath(string $path): string
    {
        $normalized = self::normalizePath($path);

        if (str_starts_with($normalized, 'private/')) {
            return self::assertWithinRoot(
                storage_path('app/'.str_replace('/', DIRECTORY_SEPARATOR, $normalized)),
                storage_path('app')
            );
        }

        return self::assertWithinRoot(
            public_path(str_replace('/', DIRECTORY_SEPARATOR, $normalized)),
            public_path()
        );
    }

    public static function exists(string $path): bool
    {
        return File::exists(self::absolutePath($path));
    }

    public static function temporaryUrl(string $path, int $minutes = 10): string
    {
        $normalized = self::normalizePath($path);

        $relativeUrl = URL::temporarySignedRoute(
            'secure-assets.show',
            now()->addMinutes(max($minutes, 1)),
            ['path' => $normalized],
            absolute: false
        );

        return PublicUrl::api($relativeUrl);
    }

    private static function assertWithinRoot(string $path, string $root): string
    {
        $realRoot = realpath($root);
        $realPath = realpath($path);

        if ($realRoot === false) {
            throw new InvalidArgumentException('Invalid asset root.');
        }

        if ($realPath === false) {
            // 文件不存在或路径无法解析——拒绝访问，不回退到未经 realpath 验证的原始路径
            throw new InvalidArgumentException('Invalid asset path.');
        }

        $realRoot = str_replace('\\', '/', $realRoot);
        $realPath = str_replace('\\', '/', $realPath);

        if ($realPath !== $realRoot && ! str_starts_with($realPath, $realRoot.'/')) {
            throw new InvalidArgumentException('Invalid asset path.');
        }

        return $realPath;
    }
}
