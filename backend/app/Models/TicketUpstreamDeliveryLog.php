<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TicketUpstreamDeliveryLog extends Model
{
    protected $fillable = [
        'ticket_id', 'ticket_reply_id', 'binding_id', 'delivery_id', 'direction', 'operation',
        'event', 'status', 'reason_code', 'provider_key', 'supplier_id', 'attempt',
        'http_status', 'duration_ms', 'message', 'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function reply(): BelongsTo
    {
        return $this->belongsTo(TicketReply::class, 'ticket_reply_id');
    }

    public function binding(): BelongsTo
    {
        return $this->belongsTo(TicketUpstreamBinding::class, 'binding_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(TicketReplyDelivery::class, 'delivery_id');
    }
}
