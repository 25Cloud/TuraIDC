<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

class UploadedImage
{
    private const MIME_EXTENSION_MAP = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public static function extension(UploadedFile $file): string
    {
        $mimeType = self::mimeType($file);

        if (! isset(self::MIME_EXTENSION_MAP[$mimeType])) {
            throw new InvalidArgumentException('Unsupported image type.');
        }

        return self::MIME_EXTENSION_MAP[$mimeType];
    }

    public static function mimeType(UploadedFile $file): string
    {
        return strtolower(trim((string) $file->getMimeType()));
    }

    public static function originalName(UploadedFile $file, string $fallback): string
    {
        $name = trim(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = basename($name);
        $name = preg_replace('/[^\P{C}\t]+/u', '', $name) ?? '';

        return trim($name) !== '' ? trim($name) : $fallback;
    }

    public static function group(string $group): string
    {
        $normalized = strtolower(trim(str_replace('\\', '/', $group)));

        if ($normalized === '') {
            return 'content';
        }

        if (! preg_match('/\A[a-z0-9][a-z0-9_-]{0,49}\z/', $normalized)) {
            throw new InvalidArgumentException('Invalid upload group.');
        }

        return $normalized;
    }
}
