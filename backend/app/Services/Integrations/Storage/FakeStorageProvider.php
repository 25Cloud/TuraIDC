<?php

declare(strict_types=1);

namespace App\Services\Integrations\Storage;

use App\Contracts\Integrations\StorageProviderInterface;

class FakeStorageProvider implements StorageProviderInterface
{
    /**
     * @var array<string, string>
     */
    public array $files = [];

    public function put(string $path, string $contents, array $options = []): string
    {
        $this->files[$path] = $contents;

        return $path;
    }

    public function temporaryUrl(string $path, int $ttlSeconds): string
    {
        return '/fake-storage/'.ltrim($path, '/').'?ttl='.$ttlSeconds;
    }
}
