<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminAuthProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if (is_array($this->resource)) {
            return [
                'id' => (int) ($this->resource['id'] ?? 0),
                'username' => (string) ($this->resource['username'] ?? ''),
                'nickname' => (string) ($this->resource['nickname'] ?? ''),
                'email' => (string) ($this->resource['email'] ?? ''),
                'role' => (string) ($this->resource['role'] ?? 'unknown'),
                'permissions' => array_values((array) ($this->resource['permissions'] ?? [])),
            ];
        }

        return [
            'id' => (int) $this->resource->id,
            'username' => (string) $this->resource->username,
            'nickname' => (string) ($this->resource->nickname ?? ''),
            'email' => (string) ($this->resource->email ?? ''),
            'role' => (string) ($this->resource->resolvedRoleLabel() ?: 'unknown'),
            'permissions' => $this->resource->resolvedPermissions(),
        ];
    }
}
