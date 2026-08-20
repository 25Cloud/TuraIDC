<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketReplyDelivery extends Model
{
    protected $fillable = [
        'ticket_reply_id', 'direction', 'content_prefix', 'status', 'idempotency_key', 'remote_event_id',
        'attempts', 'last_attempt_at', 'last_error', 'delivered_at',
    ];

    protected function casts(): array
    {
        return [
            'last_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function reply(): BelongsTo
    {
        return $this->belongsTo(TicketReply::class, 'ticket_reply_id');
    }
}
