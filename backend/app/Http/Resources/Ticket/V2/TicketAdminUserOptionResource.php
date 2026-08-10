<?php

declare(strict_types=1);

namespace App\Http\Resources\Ticket\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketAdminUserOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $admin = is_array($this->resource) ? $this->resource : [];

        return [
            'id' => (int) ($admin['id'] ?? 0),
            'username' => (string) ($admin['username'] ?? ''),
            'nickname' => (string) ($admin['nickname'] ?? ''),
            'email' => (string) ($admin['email'] ?? ''),
        ];
    }
}
