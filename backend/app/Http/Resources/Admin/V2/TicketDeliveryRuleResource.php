<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TicketDeliveryRuleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => (string) $this->name,
            'department' => (string) $this->department,
            'supplier_id' => (int) $this->supplier_id,
            'provider_key' => (string) $this->provider_key,
            'product_scope_mode' => (string) $this->product_scope_mode,
            'product_ids' => $this->whenLoaded('products', fn () => $this->products->pluck('id')->map(fn ($id) => (int) $id)->values()->all(), []),
            'upstream_department_id' => (string) $this->upstream_department_id,
            'enabled' => (bool) $this->enabled,
            'sync_admin_replies' => (bool) $this->sync_admin_replies,
            'mask_keywords' => $this->mask_keywords,
            'created_at' => optional($this->created_at)?->format('Y-m-d H:i:s'),
            'updated_at' => optional($this->updated_at)?->format('Y-m-d H:i:s'),
        ];
    }
}
