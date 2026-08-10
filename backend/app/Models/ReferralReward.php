<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReferralReward extends Model
{
    public const STATUS_FROZEN = 0;

    public const STATUS_REWARDED = 1;

    public const STATUS_REVERSED = 2;

    protected $fillable = [
        'referrer_user_id',
        'referred_user_id',
        'order_id',
        'invoice_id',
        'product_id',
        'order_amount',
        'reward_rate',
        'reward_amount',
        'available_at',
        'released_at',
        'status',
        'operator',
        'remark',
        'trace_id',
        'rewarded_at',
    ];

    protected function casts(): array
    {
        return [
            'referrer_user_id' => 'integer',
            'referred_user_id' => 'integer',
            'order_id' => 'integer',
            'invoice_id' => 'integer',
            'product_id' => 'integer',
            'order_amount' => 'decimal:2',
            'reward_rate' => 'decimal:2',
            'reward_amount' => 'decimal:2',
            'available_at' => 'datetime',
            'released_at' => 'datetime',
            'status' => 'integer',
            'rewarded_at' => 'datetime',
        ];
    }

    public function referrer()
    {
        return $this->belongsTo(User::class, 'referrer_user_id');
    }

    public function referredUser()
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
