<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApiKey extends Model
{
    use SoftDeletes;

    public const STATUS_ENABLED = 'enabled';

    public const STATUS_DISABLED = 'disabled';

    /** 密钥前缀（展示用） */
    public const KEY_PREFIX = 'tura_';

    protected $fillable = [
        'user_id', 'name', 'key_prefix', 'secret_hash', 'secret_last4',
        'scopes', 'expires_at', 'ip_allowlist', 'status', 'last_used_at',
    ];

    protected $casts = [
        'scopes' => 'array',
        'ip_allowlist' => 'array',
        'expires_at' => 'datetime',
        'last_used_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ENABLED);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
