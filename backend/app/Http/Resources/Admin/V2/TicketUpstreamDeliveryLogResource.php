<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin\V2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TicketUpstreamDeliveryLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'ticket_id' => (int) $this->ticket_id,
            'ticket_reply_id' => $this->ticket_reply_id ? (int) $this->ticket_reply_id : null,
            'direction' => (string) $this->direction,
            'operation' => (string) $this->operation,
            'event' => (string) $this->event,
            'status' => (string) $this->status,
            'status_label' => $this->statusLabel((string) $this->status),
            'reason_code' => $this->reason_code,
            'provider_key' => $this->provider_key,
            'supplier_id' => $this->supplier_id ? (int) $this->supplier_id : null,
            'attempt' => $this->attempt ? (int) $this->attempt : null,
            'http_status' => $this->http_status ? (int) $this->http_status : null,
            'duration_ms' => $this->duration_ms ? (int) $this->duration_ms : null,
            'message' => $this->message,
            'occurred_at' => $this->occurred_at?->format('Y-m-d H:i:s'),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => '等待发送',
            'sending' => '发送中',
            'delivered' => '已转发',
            'failed' => '转发失败',
            'skipped' => '未转发',
            default => $status !== '' ? $status : '未知',
        };
    }
}
