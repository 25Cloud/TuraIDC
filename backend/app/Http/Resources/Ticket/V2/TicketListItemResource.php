<?php

declare(strict_types=1);

namespace App\Http\Resources\Ticket\V2;

use App\Models\Ticket;
use App\Services\Ticket\TicketService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Ticket $ticket */
        $ticket = $this->resource;

        return [
            'id' => (int) $ticket->id,
            'user_id' => (int) $ticket->user_id,
            'department' => (string) $ticket->department,
            'department_label' => TicketService::DEPT_LABELS[$ticket->department] ?? (string) $ticket->department,
            'subject' => (string) $ticket->subject,
            'priority' => (int) $ticket->priority,
            'priority_label' => TicketService::PRIORITIES[(int) $ticket->priority] ?? (string) $ticket->priority,
            'status' => (int) $ticket->status,
            'status_label' => TicketService::STATUS_LABELS[(int) $ticket->status] ?? (string) $ticket->status,
            'service_id' => $ticket->service_id ? (int) $ticket->service_id : null,
            'assignee_id' => $ticket->assignee_id ? (int) $ticket->assignee_id : null,
            'close_reason' => $ticket->close_reason,
            'close_reason_label' => TicketService::CLOSE_REASON_LABELS[$ticket->close_reason ?? ''] ?? null,
            'created_at' => $ticket->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $ticket->updated_at?->format('Y-m-d H:i:s'),
            'user' => $this->user(),
            'assignee' => $this->assignee(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function user(): ?array
    {
        /** @var Ticket $ticket */
        $ticket = $this->resource;
        $user = $ticket->relationLoaded('user') ? $ticket->user : null;

        if (! $user) {
            return null;
        }

        return [
            'id' => (int) $user->id,
            'email' => (string) ($user->email ?? ''),
            'nickname' => (string) ($user->nickname ?? ''),
            'display_name' => (string) ($user->display_name ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function assignee(): ?array
    {
        /** @var Ticket $ticket */
        $ticket = $this->resource;
        $assignee = $ticket->relationLoaded('assignee') ? $ticket->assignee : null;

        if (! $assignee) {
            return null;
        }

        return [
            'id' => (int) $assignee->id,
            'username' => (string) ($assignee->username ?? ''),
            'nickname' => (string) (($assignee->nickname ?? '') ?: ($assignee->username ?? '')),
        ];
    }
}
