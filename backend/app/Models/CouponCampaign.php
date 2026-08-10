<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CouponCampaign extends Model
{
    protected $fillable = [
        'name',
        'description',
        'weekdays',
        'trigger_time',
        'issue_quantity',
        'valid_duration_hours',
        'discount_scope',
        'discount_type',
        'discount_value',
        'min_amount',
        'max_discount_amount',
        'billing_cycles',
        'product_ids',
        'first_order_only',
        'per_user_limit',
        'status',
        'sort_order',
        'last_dispatched_at',
        'last_coupon_id',
        'remark',
        'operator',
        'trace_id',
    ];

    protected function casts(): array
    {
        return [
            'weekdays' => 'array',
            'discount_value' => 'decimal:2',
            'min_amount' => 'decimal:2',
            'max_discount_amount' => 'decimal:2',
            'billing_cycles' => 'array',
            'product_ids' => 'array',
            'first_order_only' => 'boolean',
            'issue_quantity' => 'integer',
            'valid_duration_hours' => 'integer',
            'per_user_limit' => 'integer',
            'status' => 'integer',
            'sort_order' => 'integer',
            'last_coupon_id' => 'integer',
            'last_dispatched_at' => 'datetime',
        ];
    }

    public function coupons()
    {
        return $this->hasMany(Coupon::class);
    }

    public function lastCoupon()
    {
        return $this->belongsTo(Coupon::class, 'last_coupon_id');
    }
}
