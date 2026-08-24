<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiKeyUsageLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'api_key_id', 'user_id', 'method', 'path', 'status_code', 'ip', 'duration_ms', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
