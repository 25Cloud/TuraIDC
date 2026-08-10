<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduleTaskRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'schedule_tick_id',
        'task_key',
        'task_name',
        'rule_description',
        'source',
        'queue',
        'status',
        'duration_ms',
        'summary',
        'error_msg',
        'queued_at',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'schedule_tick_id' => 'integer',
            'duration_ms' => 'integer',
            'summary' => 'array',
            'queued_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
