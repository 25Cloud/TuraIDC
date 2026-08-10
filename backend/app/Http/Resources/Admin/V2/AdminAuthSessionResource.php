<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminAuthSessionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $payload = is_array($this->resource) ? $this->resource : [];

        return [
            'token' => (string) ($payload['token'] ?? ''),
            'admin' => (new AdminAuthProfileResource((array) ($payload['admin'] ?? [])))->resolve(),
        ];
    }
}
