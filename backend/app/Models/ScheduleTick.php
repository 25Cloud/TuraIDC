<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleTick extends Model
{
    protected $fillable = [
        'slot_started_at',
        'global_number',
        'daily_index',
        'triggered_at',
    ];

    protected function casts(): array
    {
        return [
            'slot_started_at' => 'immutable_datetime',
            'global_number' => 'integer',
            'daily_index' => 'integer',
            'triggered_at' => 'immutable_datetime',
        ];
    }
}
