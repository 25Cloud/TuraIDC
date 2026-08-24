<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TicketReply extends Model
{
    public $timestamps = false;

    protected $fillable = ['ticket_id', 'user_id', 'content', 'is_staff', 'sender_type', 'sender_name', 'is_pre_reply', 'attachments', 'quote_reply_id', 'recalled_at', 'created_at'];

    protected function casts(): array
    {
        return [
            'attachments' => 'array',
            'created_at' => 'datetime',
            'recalled_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function delivery(): HasOne
    {
        return $this->hasOne(TicketReplyDelivery::class);
    }
}
