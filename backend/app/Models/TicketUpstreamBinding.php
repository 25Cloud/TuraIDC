<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketUpstreamBinding extends Model
{
    protected $fillable = [
        'ticket_id', 'provider_key', 'supplier_id', 'upstream_department_id',
        'upstream_service_id', 'upstream_ticket_id', 'status', 'attempts',
        'last_error', 'last_attempt_at',
    ];

    protected function casts(): array
    {
        return [
            'last_attempt_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
