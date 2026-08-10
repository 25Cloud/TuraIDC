<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminLoginAsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];
        $user = is_array($payload['user'] ?? null) ? $payload['user'] : [];

        return [
            'login_code' => (string) ($payload['login_code'] ?? ''),
            'expires_in' => (int) ($payload['expires_in'] ?? 0),
            'target_url' => (string) ($payload['target_url'] ?? ''),
            'user' => [
                'id' => (int) ($user['id'] ?? 0),
                'email' => (string) ($user['email'] ?? ''),
                'nickname' => (string) ($user['nickname'] ?? ''),
            ],
        ];
    }
}
