<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminVerificationUnbindResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $result = is_array($this->resource) ? $this->resource : [];

        return [
            'user_id' => (int) ($result['user_id'] ?? 0),
            'real_name' => (string) ($result['real_name'] ?? ''),
            'unbound_at' => $result['unbound_at'] ?? null,
            'operator' => (string) ($result['operator'] ?? ''),
            'reject_reason' => (string) ($result['reject_reason'] ?? ''),
        ];
    }
}
