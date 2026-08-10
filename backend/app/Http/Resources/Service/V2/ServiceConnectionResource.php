<?php

declare(strict_types=1);

namespace App\Http\Resources\Service\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ServiceConnectionResource extends JsonResource
{
    public function __construct($resource, private readonly bool $includePassword = false)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $connection = is_array($this->resource) ? $this->resource : [];

        $payload = [
            'hostname' => (string) ($connection['hostname'] ?? ''),
            'username' => (string) ($connection['username'] ?? ''),
            'has_password' => (bool) (($connection['has_password'] ?? false) || trim((string) ($connection['password'] ?? '')) !== ''),
            'port' => (int) ($connection['port'] ?? 0),
            'dedicated_ip' => (string) ($connection['dedicated_ip'] ?? ''),
            'internal_ip' => (string) ($connection['internal_ip'] ?? ''),
            'assigned_ips' => array_values(array_filter(
                array_map('strval', (array) ($connection['assigned_ips'] ?? [])),
                fn (string $ip): bool => $ip !== ''
            )),
            'nat_remote_address' => (string) ($connection['nat_remote_address'] ?? ''),
            'nat_remote_host' => (string) ($connection['nat_remote_host'] ?? ''),
            'nat_remote_port' => (int) ($connection['nat_remote_port'] ?? 0),
            'nat_remote_checked_at' => $connection['nat_remote_checked_at'] ?? null,
        ];

        if ($this->includePassword) {
            $payload['password'] = (string) ($connection['password'] ?? '');
        }

        return $payload;
    }
}
