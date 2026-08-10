<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserReferral extends Model
{
    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'user_id',
        'referral_code',
        'referrer_user_id',
        'referred_at',
        'member_level_id',
        'total_sales_amount',
    ];

    protected function casts(): array
    {
        return [
            'referrer_user_id' => 'integer',
            'member_level_id' => 'integer',
            'referred_at' => 'datetime',
            'total_sales_amount' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function memberLevel(): BelongsTo
    {
        return $this->belongsTo(MemberLevel::class);
    }
}
