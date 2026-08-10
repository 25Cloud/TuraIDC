<?php

namespace App\Models;

use App\Constants\UserCouponStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCoupon extends Model
{
    protected $fillable = [
        'uid',
        'coupon_id',
        'user_id',
        'receive_type',
        'status',
        'claimed_at',
        'used_at',
        'revoked_at',
        'reserved_until',
        'granted_at',
        'last_used_at',
        'remark',
        'operator',
        'trace_id',
    ];

    protected function casts(): array
    {
        return [
            'coupon_id' => 'integer',
            'user_id' => 'integer',
            'status' => 'integer',
            'claimed_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'reserved_until' => 'datetime',
            'granted_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (UserCoupon $userCoupon): void {
            $userCoupon->uid ??= 'uc_'.bin2hex(random_bytes(6));
            $userCoupon->status ??= UserCouponStatus::OWNED;
        });
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
