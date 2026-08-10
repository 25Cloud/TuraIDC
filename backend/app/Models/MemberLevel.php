<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class MemberLevel extends Model
{
    protected $fillable = [
        'name',
        'code',
        'sales_amount_min',
        'sales_amount_max',
        'reward_rate',
        'status',
        'sort_order',
        'remark',
    ];

    protected function casts(): array
    {
        return [
            'sales_amount_min' => 'decimal:2',
            'sales_amount_max' => 'decimal:2',
            'reward_rate' => 'decimal:2',
            'status' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function users()
    {
        return $this->hasMany(User::class, 'member_level_id');
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('status', 1);
    }
}
