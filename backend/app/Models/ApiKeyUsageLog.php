<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiKeyUsageLog extends Model
{
    public const UPDATED_AT = null;

    /** 自有开放接口链路（Bearer API Key，api_keys 表） */
    public const CHANNEL_OPEN_API = 'open_api';

    /** 魔方财务对接链路（users.api_open 账号凭据，登录换 JWT） */
    public const CHANNEL_ZJMF_UPSTREAM = 'zjmf_upstream';

    protected $fillable = [
        'api_key_id', 'user_id', 'channel', 'method', 'path', 'status_code', 'ip', 'duration_ms', 'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
