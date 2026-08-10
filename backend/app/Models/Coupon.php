<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    protected $fillable = [
        'coupon_campaign_id',
        'name',
        'code',
        'description',
        'distribution_type',
        'discount_scope',
        'discount_type',
        'discount_value',
        'min_amount',
        'max_discount_amount',
        'billing_cycles',
        'product_ids',
        'first_order_only',
        'total_usage_limit',
        'per_user_limit',
        'used_count',
        'status',
        'sort_order',
        'starts_at',
        'expires_at',
        'remark',
        'operator',
        'trace_id',
    ];

    protected function casts(): array
    {
        return [
            'discount_value' => 'decimal:2',
            'min_amount' => 'decimal:2',
            'max_discount_amount' => 'decimal:2',
            'coupon_campaign_id' => 'integer',
            'distribution_type' => 'string',
            'discount_scope' => 'string',
            'billing_cycles' => 'array',
            'product_ids' => 'array',
            'first_order_only' => 'boolean',
            'total_usage_limit' => 'integer',
            'per_user_limit' => 'integer',
            'used_count' => 'integer',
            'status' => 'integer',
            'sort_order' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function couponCampaign(): BelongsTo
    {
        return $this->belongsTo(CouponCampaign::class);
    }

    public function userCoupons(): HasMany
    {
        return $this->hasMany(UserCoupon::class);
    }
}
