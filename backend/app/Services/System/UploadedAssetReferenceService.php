<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Exceptions\BusinessException;
use App\Support\SecureAsset;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class UploadedAssetReferenceService
{
    private const TOKEN_TTL_SECONDS = 7200;

    public const CATEGORY_TICKET_ATTACHMENT = 'ticket_attachment';

    public function issue(string $category, string $path, string $ownerType, int $ownerId): array
    {
        $normalizedPath = SecureAsset::normalizePath($path);
        $token = Str::lower(Str::random(64));
        $expiresAt = CarbonImmutable::now()->addSeconds(self::TOKEN_TTL_SECONDS);

        Cache::put($this->cacheKey($token), [
            'category' => $category,
            'path' => $normalizedPath,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'expires_at' => $expiresAt->toIso8601String(),
        ], $expiresAt);

        return [
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function resolve(string $token, string $category, string $ownerType, int $ownerId): string
    {
        $payload = Cache::get($this->cacheKey($token));

        throw_if(! is_array($payload), new BusinessException('上传凭证已失效，请重新上传'));
        throw_if(
            (string) ($payload['category'] ?? '') !== $category
            || (string) ($payload['owner_type'] ?? '') !== $ownerType
            || (int) ($payload['owner_id'] ?? 0) !== $ownerId,
            new BusinessException('上传凭证校验失败，请重新上传')
        );

        return SecureAsset::normalizePath((string) ($payload['path'] ?? ''));
    }

    public function publicId(string $path): string
    {
        try {
            return hash('sha256', SecureAsset::normalizePath($path));
        } catch (\Throwable) {
            return hash('sha256', trim($path));
        }
    }

    private function cacheKey(string $token): string
    {
        return 'uploaded_asset_reference:'.hash('sha256', $token);
    }
}
