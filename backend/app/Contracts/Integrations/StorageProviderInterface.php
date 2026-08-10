<?php

declare(strict_types=1);

namespace App\Contracts\Integrations;

interface StorageProviderInterface
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function put(string $path, string $contents, array $options = []): string;

    public function temporaryUrl(string $path, int $ttlSeconds): string;
}
