<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Ticket */
class AdminUserTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $service = $this->resource->relationLoaded('service') ? $this->resource->getRelation('service') : null;

        return [
            'id' => (int) $this->id,
            'subject' => (string) ($this->subject ?? ''),
            'department' => (string) ($this->department ?? ''),
            'priority' => (int) ($this->priority ?? 0),
            'status' => (int) ($this->status ?? 0),
            'service_id' => $this->service_id !== null ? (int) $this->service_id : null,
            'service' => $service ? [
                'id' => (int) $service->id,
                'name' => (string) ($service->name ?? ''),
            ] : null,
            'assignee_id' => $this->assignee_id !== null ? (int) $this->assignee_id : null,
            'created_at' => $this->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $this->updated_at?->format('Y-m-d H:i:s'),
        ];
    }
}
