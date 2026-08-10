<?php

declare(strict_types=1);

namespace App\Support;

final class UploadUrl
{
    /**
     * 将后端托管的 /uploads 与 /media 路径转换为公开 API 域名。
     * 前端自带资源（例如 /branding）及第三方外链保持原样。
     */
    public static function resolve(?string $path): ?string
    {
        $value = trim((string) $path);

        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            $parsedPath = parse_url($value, PHP_URL_PATH);
            if (is_string($parsedPath) && self::isManagedUploadPath($parsedPath)) {
                if (! self::uploadedFileExists($parsedPath)) {
                    return $value;
                }

                return self::buildApiUrl($parsedPath);
            }

            return $value;
        }

        if (! self::isManagedUploadPath($value)) {
            return $value;
        }

        return self::buildApiUrl($value);
    }

    private static function uploadedFileExists(string $relativePath): bool
    {
        $normalized = '/'.ltrim($relativePath, '/');

        return is_file(public_path(ltrim($normalized, '/')));
    }

    private static function isManagedUploadPath(string $path): bool
    {
        $normalized = '/'.ltrim($path, '/');

        return str_starts_with($normalized, '/media/') || str_starts_with($normalized, '/uploads/');
    }

    private static function buildApiUrl(string $relativePath): string
    {
        return PublicUrl::api($relativePath);
    }
}
