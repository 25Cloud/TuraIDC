<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductDiscountGroup extends Model
{
    protected $fillable = [
        'name', 'code', 'min_discount_rate', 'cost_rate', 'status', 'sort_order', 'remark',
    ];

    protected function casts(): array
    {
        return [
            'min_discount_rate' => 'decimal:2',
            'cost_rate' => 'decimal:2',
            'status' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'product_discount_group_id');
    }

    public function agentGroupDiscounts(): HasMany
    {
        return $this->hasMany(AgentGroupDiscount::class);
    }
}
