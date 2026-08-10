<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationLog extends Model
{
    protected $fillable = [
        'channel',
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
        ];
    }
}
