<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    protected $fillable = [
        'user_id', 'department', 'subject', 'priority',
        'status', 'service_id', 'assignee_id', 'close_reason',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(AdminUser::class, 'assignee_id');
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [0, 1, 2]);
    }
}
