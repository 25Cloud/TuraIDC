<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MessageLog extends Model
{
    protected $fillable = [
        'channel',
        'plugin_id',
        'driver_key',
        'trace_id',
        'recipient',
        'template_code',
        'subject',
        'content',
        'params_json',
        'provider',
        'request_id',
        'status',
        'error_msg',
        'sent_at',
        'origin_type',
        'origin_id',
    ];

    protected function casts(): array
    {
        return [
            'params_json' => 'array',
            'sent_at' => 'datetime',
            'plugin_id' => 'integer',
        ];
    }
}
