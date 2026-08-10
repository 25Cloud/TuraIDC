<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminCouponCampaignSummaryResource extends JsonResource
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
            'disabled' => (int) ($item['disabled'] ?? 0),
            'generated_today' => (int) ($item['generated_today'] ?? 0),
        ];
    }
}
