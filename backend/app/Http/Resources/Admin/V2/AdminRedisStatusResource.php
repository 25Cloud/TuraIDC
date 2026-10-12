<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminRedisStatusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];

        return [
            'available' => (bool) ($payload['available'] ?? false),
            'client' => (string) ($payload['client'] ?? ''),
            'prefix' => (string) ($payload['prefix'] ?? ''),
            'cache_store' => (string) ($payload['cache_store'] ?? ''),
            'connections' => array_values($payload['connections'] ?? []),
            'server' => $payload['server'] ?? [],
            'keyspace' => array_values($payload['keyspace'] ?? []),
            'memory' => $payload['memory'] ?? [],
            'stats' => $payload['stats'] ?? [],
        ];
    }
}
