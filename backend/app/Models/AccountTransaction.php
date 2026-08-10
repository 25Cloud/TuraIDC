<?php

namespace App\Models;

use App\Models\Concerns\EnsuresTraceId;
use App\Models\Concerns\NormalizesTraceId;
use App\Support\UserBalanceCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AccountTransaction extends Model
{
    use EnsuresTraceId, NormalizesTraceId;

    protected $fillable = [
        'user_id',
        'account_type',
        'event_type',
        'change_amount',
        'currency',
        'balance_after',
        'source_type',
        'source_id',
        'origin_type',
        'origin_id',
        'remark',
        'operator',
        'trace_id',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'change_amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'source_id' => 'integer',
            'origin_id' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function rechargeRecord(): HasOne
    {
        return $this->hasOne(RechargeRecord::class);
    }

    protected static function booted(): void
    {
        static::saved(function (self $transaction): void {
            UserBalanceCache::forget((int) $transaction->user_id);
        });

        static::deleted(function (self $transaction): void {
            UserBalanceCache::forget((int) $transaction->user_id);
        });
    }
}
