<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleRunLog extends Model
{
    protected $fillable = [
        'task_name',
        'status',
        'duration_ms',
        'summary',
        'error_msg',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
