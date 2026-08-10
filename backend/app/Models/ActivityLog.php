<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ActivityLog extends Model
{
    protected $fillable = [
        'actor_type',
        'actor_id',
        'actor_name',
        'module',
        'action',
        'description',
        'subject_type',
        'subject_id',
        'context',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'context' => 'array',
            'actor_id' => 'integer',
            'subject_id' => 'integer',
        ];
    }
}
