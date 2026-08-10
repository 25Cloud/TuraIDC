<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminCouponSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = is_array($this->resource) ? $this->resource : [];

        return [
            'total' => (int) ($item['total'] ?? 0),
            'active' => (int) ($item['active'] ?? 0),
            'expired' => (int) ($item['expired'] ?? 0),
            'disabled' => (int) ($item['disabled'] ?? 0),
            'public_total' => (int) ($item['public_total'] ?? 0),
            'private_total' => (int) ($item['private_total'] ?? 0),
            'total_used' => (int) ($item['total_used'] ?? 0),
            'enabled' => (bool) ($item['enabled'] ?? false),
        ];
    }
}
